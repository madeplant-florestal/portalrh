<?php

/**
 * Integração — Carta Proposta, Aceite eletrônico (Fase 1, correção de 2026-11). Prova, contra o
 * banco real:
 *   - pré-preenchimento usa vaga_beneficios (nunca cargo_beneficios, pedido explícito do RH);
 *   - edição livre do rascunho sem duplicar versão; emissão exige todos os campos;
 *   - "mensagem preparada" NUNCA é confirmação de envio — o token só é honrado depois de
 *     confirmarEnvio() explícito;
 *   - aceite move a candidatura para "Admissão" (reaproveitando RecruitmentPipelineService,
 *     idempotente); recusa NUNCA move a candidatura;
 *   - prazo de validade da proposta é checado independentemente do prazo técnico do token;
 *   - aceite duplicado é bloqueado; nova versão após decisão preserva o histórico da anterior;
 *   - PDF é gerado e salvo de fato em disco.
 *
 * Fixtures ZZCP-* (vaga, benefícios, candidatura, usuário, mensagem), com limpeza em `finally`.
 */

require __DIR__ . '/../../app/core/bootstrap.php';

$pdo = Database::conn();
$falhas = [];
$check = static function (bool $cond, string $msg) use (&$falhas): void {
    if (!$cond) {
        $falhas[] = $msg;
        fwrite(STDERR, "  [FALHOU] {$msg}\n");
    } else {
        echo "  [ok] {$msg}\n";
    }
};

$suffix = substr((string)time(), -5) . (string)random_int(10, 99);
$criados = ['vagas' => [], 'beneficios' => [], 'candidaturas' => [], 'mensagens' => [], 'cartas' => [], 'arquivosPdf' => [], 'solicitacoesVaga' => []];

try {
    // ---- template de mensagem (não existe por padrão — precisa ser criado em Configurações) --
    // IMPORTANTE (correção de homologação 2026-11): este teste NUNCA cria/sobrescreve uma linha
    // sob o código de PRODUÇÃO ('carta_proposta_enviada') — uma versão anterior fazia isso como
    // rede de segurança, e um run interrompido (pipe head/tail cortado) deixou presa, no ambiente
    // real, uma linha "(fixture de teste)" sob esse código, vazando para a tela do RH. O teste só
    // LÊ o que já existe; quando não existe nada ativo, os passos que dependem dele são pulados
    // com aviso explícito, nunca fabricados sob o código real.
    $tpl = Mensagem::create([
        'codigo' => 'zzcp_carta_proposta_' . $suffix,
        'titulo' => 'ZZCP Carta Proposta enviada',
        'conteudo' => "Olá [Nome], sua Carta Proposta está pronta: [Link da Proposta]",
        'ativo' => true,
    ]);
    $check($tpl['ok'] ?? false, '(setup) Template de mensagem criado para o teste');
    $criados['mensagens'][] = $tpl['id'];

    $codigoOriginal = CartaPropostaService::CODIGO_MENSAGEM_ENVIO;
    $templateOficial = Mensagem::findAtivaByCodigo($codigoOriginal);
    $check($templateOficial !== null, '(pré-condição) Existe um template ATIVO para "' . $codigoOriginal . '" em Configurações → Mensagens (sem ele, "preparar envio" é rejeitado com erro claro — nunca fabricado automaticamente sob o código de produção)');
    if ($templateOficial !== null) {
        $check(!str_contains((string)$templateOficial['titulo'], 'fixture de teste') && !str_contains((string)$templateOficial['titulo'], 'ZZCP'), '(template oficial) O template ativo em produção não é um leftover de teste');
    }

    // ---- usuário RH ---------------------------------------------------------------------------
    $usuarioId = (int)$pdo->query("SELECT id FROM usuarios WHERE role IN ('admin','rh') ORDER BY id LIMIT 1")->fetchColumn();
    $check($usuarioId > 0, '(pré-condição) Existe usuário admin/rh local');

    // ---- vaga + benefícios por VAGA (vaga_beneficios, nunca cargo_beneficios) ------------------
    $pdo->prepare("INSERT INTO vagas (titulo, descricao, requisitos, ativo) VALUES (?, 'desc', 'req', 1)")->execute(["ZZCP Vaga {$suffix}"]);
    $vagaId = (int)$pdo->lastInsertId();
    $criados['vagas'][] = $vagaId;

    $pdo->prepare("INSERT INTO beneficios (nome, ativo) VALUES (?, 1)")->execute(["ZZCP Vale Alimentação {$suffix}"]);
    $ben1 = (int)$pdo->lastInsertId();
    $criados['beneficios'][] = $ben1;
    $pdo->prepare("INSERT INTO beneficios (nome, ativo) VALUES (?, 1)")->execute(["ZZCP Plano de Saúde {$suffix}"]);
    $ben2 = (int)$pdo->lastInsertId();
    $criados['beneficios'][] = $ben2;
    // Benefício de outra vaga (controle negativo — nunca deve aparecer na proposta desta vaga).
    $pdo->prepare("INSERT INTO vagas (titulo, descricao, requisitos, ativo) VALUES (?, 'desc', 'req', 1)")->execute(["ZZCP Vaga B {$suffix}"]);
    $vagaBId = (int)$pdo->lastInsertId();
    $criados['vagas'][] = $vagaBId;
    $pdo->prepare("INSERT INTO beneficios (nome, ativo) VALUES (?, 1)")->execute(["ZZCP Beneficio Vaga B {$suffix}"]);
    $benB = (int)$pdo->lastInsertId();
    $criados['beneficios'][] = $benB;
    Vaga::syncBeneficios($vagaId, [$ben1, $ben2]);
    Vaga::syncBeneficios($vagaBId, [$benB]);

    $mkCandidatura = static function (int $vagaId, string $nome) use ($pdo, &$criados, $suffix): int {
        static $seq = 0;
        $seq++;
        $pdo->prepare(
            "INSERT INTO candidaturas (vaga_id, nome, email, telefone, cpf, cargo_pretendido, experiencia, pdf_path, status)
             VALUES (?, ?, ?, '11999999999', ?, 'Cargo Teste', 'exp', 'x.pdf', 'novo')"
        )->execute([$vagaId, "ZZCP {$nome} {$suffix}", "zzcp{$seq}{$suffix}@teste.local", '3000000000' . $seq]);
        $id = (int)$pdo->lastInsertId();
        $criados['candidaturas'][] = $id;
        return $id;
    };
    $candA = $mkCandidatura($vagaId, 'CandidatoA');
    $candB = $mkCandidatura($vagaBId, 'CandidatoB');

    $service = new CartaPropostaService();
    $repo = new CartaPropostaRepository();

    // ---- 1a) template ausente/inativo -> prepararEnvio() rejeita com mensagem clara, orientando
    // o RH a cadastrar em Configurações → Mensagens (nunca fabrica nada sob o código de produção) --
    if ($templateOficial !== null) {
        $pdo->prepare('UPDATE mensagens SET ativo = 0 WHERE id = ?')->execute([(int)$templateOficial['id']]);
    }
    try {
        $pdo->prepare("INSERT INTO vagas (titulo, descricao, requisitos, ativo) VALUES (?, 'desc', 'req', 1)")->execute(["ZZCP Vaga Template Ausente {$suffix}"]);
        $vagaTemplateAusenteId = (int)$pdo->lastInsertId();
        $criados['vagas'][] = $vagaTemplateAusenteId;
        $candTemplateAusente = $mkCandidatura($vagaTemplateAusenteId, 'CandidatoTemplateAusente');
        $rTemplateAusente = $service->criarOuReaproveitarRascunho($candTemplateAusente, $usuarioId);
        $idTemplateAusente = (int)$rTemplateAusente['id'];
        $criados['cartas'][] = $idTemplateAusente;
        $service->atualizarRascunho($idTemplateAusente, [
            'nome_candidato' => "ZZCP CandidatoTemplateAusente {$suffix}", 'cargo' => 'ZZCP Cargo', 'setor' => null,
            'empresa_contratante' => 'ZZCP Empresa', 'salario_proposto' => 3000, 'jornada_trabalho' => '40h semanais',
            'escala_horario' => null, 'beneficios_texto' => null, 'local_trabalho' => null, 'previsao_inicio' => null,
            'validade_proposta' => (new DateTimeImmutable('today'))->modify('+10 days')->format('Y-m-d'), 'observacoes' => null,
        ]);
        $service->emitir($idTemplateAusente);
        $cartaTemplateAusente = $repo->buscarPorId($idTemplateAusente);
        $criados['arquivosPdf'][] = STORAGE_PATH . DIRECTORY_SEPARATOR . 'cartas_propostas' . DIRECTORY_SEPARATOR . $cartaTemplateAusente['pdf_nome_armazenado'];
        $preparoSemTemplate = $service->prepararEnvio($idTemplateAusente, $usuarioId);
        $check(($preparoSemTemplate['ok'] ?? true) === false, '(template ausente) Sem template ativo para "' . $codigoOriginal . '", preparar envio é rejeitado (nunca fabrica um template sob o código de produção)');
        $check(str_contains((string)($preparoSemTemplate['error'] ?? ''), $codigoOriginal), '(template ausente) A mensagem de erro orienta claramente qual template cadastrar/ativar');
    } finally {
        if ($templateOficial !== null) {
            $pdo->prepare('UPDATE mensagens SET ativo = 1 WHERE id = ?')->execute([(int)$templateOficial['id']]);
        }
    }

    // ---- 1) criação do rascunho + pré-preenchimento ---------------------------------------------
    $r1 = $service->criarOuReaproveitarRascunho($candA, $usuarioId);
    $check($r1['ok'] ?? false, '(criar) Rascunho criado com sucesso');
    $idA1 = (int)$r1['id'];
    $criados['cartas'][] = $idA1;
    $cartaA1 = $repo->buscarPorId($idA1);
    $check($cartaA1['status'] === 'rascunho', '(criar) Status inicial é rascunho');
    $check(str_contains((string)$cartaA1['beneficios_texto'], 'ZZCP Vale Alimentação') && str_contains((string)$cartaA1['beneficios_texto'], 'ZZCP Plano de Saúde'), '(pré-preenchimento) Benefícios da VAGA (vaga_beneficios) aparecem no texto pré-preenchido');
    $check(!str_contains((string)$cartaA1['beneficios_texto'], 'ZZCP Beneficio Vaga B'), '(isolamento) Benefício de OUTRA vaga nunca aparece aqui (vaga_beneficios é por vaga, não pelo catálogo inteiro)');
    $check($cartaA1['nome_candidato'] === "ZZCP CandidatoA {$suffix}", '(pré-preenchimento) Nome do candidato vem da candidatura');

    // ---- 1b) abertura da página de edição não lança exceção (correção de homologação: rota com
    // {id} + parâmetro de método com nome diferente quebrava em runtime antes do fix) — renderiza o
    // MESMO template usado por AdminCartaPropostaController::editar(), com candidatura COM benefícios.
    $viewTeste = new View();
    $htmlComBeneficios = $viewTeste->renderPartial('admin/carta_proposta/editar', [
        'base' => '', 'carta' => $cartaA1, 'candidatura' => Candidatura::find($candA), 'historico' => $repo->listarPorCandidatura($candA), 'csrf' => Security::csrfToken(),
    ]);
    $check(str_contains($htmlComBeneficios, 'ZZCP Vale Alimentação'), '(abertura) Página de edição renderiza sem exceção para candidatura COM benefícios cadastrados');

    // vaga SEM nenhum benefício cadastrado (vaga_beneficios vazio) — prefillDados() nunca pode
    // lançar exceção aqui, só deixar beneficios_texto null.
    $pdo->prepare("INSERT INTO vagas (titulo, descricao, requisitos, ativo) VALUES (?, 'desc', 'req', 1)")->execute(["ZZCP Vaga Sem Beneficios {$suffix}"]);
    $vagaSemBeneficiosId = (int)$pdo->lastInsertId();
    $criados['vagas'][] = $vagaSemBeneficiosId;
    $candSemBeneficios = $mkCandidatura($vagaSemBeneficiosId, 'CandidatoSemBeneficios');
    $rSemBeneficios = $service->criarOuReaproveitarRascunho($candSemBeneficios, $usuarioId);
    $check($rSemBeneficios['ok'] ?? false, '(sem benefícios) Rascunho é criado normalmente para vaga sem nenhum vaga_beneficios');
    $idSemBeneficios = (int)$rSemBeneficios['id'];
    $criados['cartas'][] = $idSemBeneficios;
    $cartaSemBeneficios = $repo->buscarPorId($idSemBeneficios);
    $check($cartaSemBeneficios['beneficios_texto'] === null, '(sem benefícios) beneficios_texto fica null, nunca uma exceção, quando a vaga não tem nenhum benefício cadastrado');
    $htmlSemBeneficios = $viewTeste->renderPartial('admin/carta_proposta/editar', [
        'base' => '', 'carta' => $cartaSemBeneficios, 'candidatura' => Candidatura::find($candSemBeneficios), 'historico' => $repo->listarPorCandidatura($candSemBeneficios), 'csrf' => Security::csrfToken(),
    ]);
    $check(str_contains($htmlSemBeneficios, 'Carta Proposta'), '(abertura) Página de edição renderiza sem exceção também para candidatura SEM benefícios cadastrados');

    // ---- 1c) pré-preenchimento a partir de uma Solicitação de Vaga vinculada (correção de
    // homologação 2026-11: setor/jornada/escala vinham do solicitacoes_vaga, mas local_trabalho e
    // escala_horario ficavam sempre null por não serem lidos) ---------------------------------------
    $setorReal = $pdo->query("SELECT id, nome FROM setores ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $cargoRealId = (int)$pdo->query("SELECT id FROM cargos ORDER BY id LIMIT 1")->fetchColumn();
    $centroCustoRealId = (int)$pdo->query("SELECT id FROM centros_custo ORDER BY id LIMIT 1")->fetchColumn();
    $colaboradorRealId = (int)$pdo->query("SELECT id FROM colaboradores ORDER BY id LIMIT 1")->fetchColumn();
    $check($setorReal !== false && $cargoRealId > 0 && $centroCustoRealId > 0 && $colaboradorRealId > 0, '(pré-condição) Catálogos de Setor/Cargo/Centro de Custo/Colaborador existem localmente para montar a fixture de Solicitação de Vaga');

    $escalaPlano = "ZZCP Seg a sex, 08h às 18h {$suffix}";
    $pdo->prepare(
        "INSERT INTO solicitacoes_vaga (
            setor_id, quantidade_vagas, cargo_id, gestor_solicitante_colaborador_id, solicitante_usuario_id,
            tipo_vaga, tipo_contratacao, salario_previsto, centro_custo_id, previsto_orcamento,
            jornada_trabalho, escala_encrypted, escolaridade_minima, entregas_esperadas_encrypted,
            nivel_responsabilidade, data_prevista_inicio, urgencia, status_fluxo
         ) VALUES (?, 1, ?, ?, ?, 'nova_posicao', 'clt', 3200.00, ?, 1, 'ZZCP 44h semanais', ?, 'medio', 'x', 'operacional', CURDATE(), 'media', 'aprovada')"
    )->execute([(int)$setorReal['id'], $cargoRealId, $colaboradorRealId, $usuarioId, $centroCustoRealId, Cipher::encrypt($escalaPlano)]);
    $solicitacaoId = (int)$pdo->lastInsertId();
    $criados['solicitacoesVaga'][] = $solicitacaoId;

    $pdo->prepare("INSERT INTO vagas (solicitacao_vaga_id, titulo, descricao, requisitos, area, local, ativo) VALUES (?, ?, 'desc', 'req', ?, ?, 1)")
        ->execute([$solicitacaoId, "ZZCP Vaga Com Solicitacao {$suffix}", "ZZCP Area Vaga Nunca Deve Aparecer {$suffix}", "ZZCP Local Direto Da Vaga {$suffix}"]);
    $vagaComSolicitacaoId = (int)$pdo->lastInsertId();
    $criados['vagas'][] = $vagaComSolicitacaoId;
    $candComSolicitacao = $mkCandidatura($vagaComSolicitacaoId, 'CandidatoComSolicitacao');
    $rComSolicitacao = $service->criarOuReaproveitarRascunho($candComSolicitacao, $usuarioId);
    $criados['cartas'][] = (int)$rComSolicitacao['id'];
    $cartaComSolicitacao = $repo->buscarPorId((int)$rComSolicitacao['id']);
    $check($cartaComSolicitacao['setor'] === $setorReal['nome'], '(pré-preenchimento/solicitação) Setor vem do setor_id da Solicitação de Vaga (catálogo oficial), nunca de vagas.area');
    $check($cartaComSolicitacao['jornada_trabalho'] === 'ZZCP 44h semanais', '(pré-preenchimento/solicitação) Jornada de trabalho vem da Solicitação de Vaga');
    $check((float)$cartaComSolicitacao['salario_proposto'] === 3200.0, '(pré-preenchimento/solicitação) Salário previsto vem da Solicitação de Vaga (fonte válida, nunca presumido)');
    $check($cartaComSolicitacao['escala_horario'] === $escalaPlano, '(pré-preenchimento/solicitação) Escala/Horário é decriptada a partir de solicitacoes_vaga.escala_encrypted (antes ficava sempre null)');
    $check($cartaComSolicitacao['local_trabalho'] === "ZZCP Local Direto Da Vaga {$suffix}", '(pré-preenchimento) Local de trabalho vem de vagas.local — igual com ou sem Solicitação vinculada');

    // ---- 1d) vaga LEGADA/manual, sem Solicitação de Vaga vinculada: usa o texto livre já
    // cadastrado na própria vaga (area/local) como aproximação; jornada/salário continuam
    // genuinamente ausentes (nenhuma origem cadastral existe para eles nesse caso — nunca inventados) --
    $pdo->prepare("INSERT INTO vagas (titulo, descricao, requisitos, area, local, ativo) VALUES (?, 'desc', 'req', ?, ?, 1)")
        ->execute(["ZZCP Vaga Legada {$suffix}", "ZZCP Area Legada {$suffix}", "ZZCP Local Legado {$suffix}"]);
    $vagaLegadaId = (int)$pdo->lastInsertId();
    $criados['vagas'][] = $vagaLegadaId;
    $candLegada = $mkCandidatura($vagaLegadaId, 'CandidatoVagaLegada');
    $rLegada = $service->criarOuReaproveitarRascunho($candLegada, $usuarioId);
    $criados['cartas'][] = (int)$rLegada['id'];
    $cartaLegada = $repo->buscarPorId((int)$rLegada['id']);
    $check($cartaLegada['setor'] === "ZZCP Area Legada {$suffix}", '(pré-preenchimento/legado) Sem Solicitação vinculada, Setor cai no fallback de vagas.area (texto livre revisável pelo RH)');
    $check($cartaLegada['local_trabalho'] === "ZZCP Local Legado {$suffix}", '(pré-preenchimento/legado) Local de trabalho vem de vagas.local');
    $check($cartaLegada['jornada_trabalho'] === '', '(pré-preenchimento/legado) Jornada de trabalho fica genuinamente vazia — não existe outra origem cadastral, nunca inventada');
    $check((float)$cartaLegada['salario_proposto'] === 0.0, '(pré-preenchimento/legado) Salário fica 0 (vazio) — nunca presumido — quando não há Solicitação de Vaga vinculada');
    $check($cartaLegada['empresa_contratante'] === '', '(pré-preenchimento/legado) Empresa contratante fica vazia quando a vaga não tem empresa_id cadastrado — ausência real, RH preenche');

    // ---- 2) reabrir não duplica versão -----------------------------------------------------------
    $r1b = $service->criarOuReaproveitarRascunho($candA, $usuarioId);
    $check(($r1b['id'] ?? null) === $idA1 && ($r1b['nova_versao'] ?? true) === false, '(sem duplicidade) Reabrir o rascunho devolve a MESMA versão, nunca cria uma nova sem necessidade');

    // ---- 3) edição livre do rascunho --------------------------------------------------------------
    $service->atualizarRascunho($idA1, [
        'nome_candidato' => $cartaA1['nome_candidato'], 'cargo' => 'Analista ZZCP', 'setor' => 'ZZCP Setor',
        'empresa_contratante' => '', // deixado vazio de propósito para testar a rejeição de emitir()
        'salario_proposto' => 3500.50, 'jornada_trabalho' => '44h semanais', 'escala_horario' => null,
        'beneficios_texto' => $cartaA1['beneficios_texto'], 'local_trabalho' => 'Matriz', 'previsao_inicio' => null,
        'validade_proposta' => (new DateTimeImmutable('today'))->modify('+10 days')->format('Y-m-d'), 'observacoes' => null,
    ]);

    // ---- 4) emitir() rejeita dados incompletos ----------------------------------------------------
    $tentativaEmitir = $service->emitir($idA1);
    $check(($tentativaEmitir['ok'] ?? true) === false, '(validação) emitir() rejeita quando falta empresa_contratante');

    // ---- 5) corrige e emite com sucesso -----------------------------------------------------------
    $service->atualizarRascunho($idA1, [
        'nome_candidato' => $cartaA1['nome_candidato'], 'cargo' => 'Analista ZZCP', 'setor' => 'ZZCP Setor',
        'empresa_contratante' => 'ZZCP Empresa Contratante', 'salario_proposto' => 3500.50,
        'jornada_trabalho' => '44h semanais', 'escala_horario' => null, 'beneficios_texto' => $cartaA1['beneficios_texto'],
        'local_trabalho' => 'Matriz', 'previsao_inicio' => (new DateTimeImmutable('today'))->modify('+15 days')->format('Y-m-d'),
        'validade_proposta' => (new DateTimeImmutable('today'))->modify('+10 days')->format('Y-m-d'), 'observacoes' => null,
    ]);
    $emitiu = $service->emitir($idA1);
    $check($emitiu['ok'] ?? false, '(emitir) Com todos os campos preenchidos, a emissão é aceita');
    $cartaA1 = $repo->buscarPorId($idA1);
    $check($cartaA1['status'] === 'gerada', '(emitir) Status passa para "gerada"');
    $check(!empty($cartaA1['pdf_nome_armazenado']), '(emitir) Nome do PDF foi registrado');

    $caminhoPdf = STORAGE_PATH . DIRECTORY_SEPARATOR . 'cartas_propostas' . DIRECTORY_SEPARATOR . $cartaA1['pdf_nome_armazenado'];
    $criados['arquivosPdf'][] = $caminhoPdf;
    $check(is_file($caminhoPdf) && filesize($caminhoPdf) > 0, '(PDF) O arquivo foi realmente salvo em disco, com conteúdo (dompdf gerou bytes de verdade)');

    // ---- 6) emitir() não pode ser chamado duas vezes na mesma versão -----------------------------
    $segundaEmissao = $service->emitir($idA1);
    $check(($segundaEmissao['ok'] ?? true) === false, '(idempotência) Não é possível emitir a mesma versão duas vezes (já não está em rascunho)');

    // ---- 7) preparar envio: gera token, mas NÃO confirma envio -------------------------------------
    $preparo = $service->prepararEnvio($idA1, $usuarioId);
    $check($preparo['ok'] ?? false, '(preparar) Mensagem preparada com sucesso');
    $check(str_contains((string)$preparo['texto'], '/carta-proposta/'), '(preparar) O texto preparado já contém o link real da proposta');
    $cartaA1 = $repo->buscarPorId($idA1);
    $check($cartaA1['status'] === 'gerada', '(preparar ≠ enviar) Preparar a mensagem NÃO altera o status para "enviada" — exigência explícita do RH');
    $check(!empty($cartaA1['token_hash']), '(preparar) O token já existe (o texto copiável precisa do link real)');

    // extrai o token BRUTO do texto preparado para simular o candidato clicando no link.
    preg_match('#/carta-proposta/([0-9a-f]{64})#', (string)$preparo['texto'], $m);
    $tokenA = $m[1] ?? '';
    $check(preg_match('/^[0-9a-f]{64}$/', $tokenA) === 1, '(preparar) Token de 256 bits extraído do texto preparado');

    // ---- 8) ANTES de confirmar o envio, o token NÃO é honrado -------------------------------------
    $resolvidoAntes = $service->resolverParaPagina($tokenA, new DateTimeImmutable('now'));
    $check($resolvidoAntes['estado'] === 'invalido', '(segurança) Token existente mas ainda não "enviado" (confirmação pendente) é tratado IGUAL a inexistente — nunca dá acesso antecipado');

    // preparar de novo não gera um segundo token/segunda comunicação.
    $preparo2 = $service->prepararEnvio($idA1, $usuarioId);
    $cartaA1Depois = $repo->buscarPorId($idA1);
    $check($cartaA1Depois['token_hash'] === $cartaA1['token_hash'] && $cartaA1Depois['comunicacao_id'] === $cartaA1['comunicacao_id'], '(idempotência) Preparar de novo reaproveita o mesmo token/comunicação, nunca duplica');

    // ---- 9) confirmar envio — só agora o token passa a valer --------------------------------------
    $confirmacao = $service->confirmarEnvio($idA1, $usuarioId);
    $check($confirmacao['ok'] ?? false, '(confirmar) Envio confirmado com sucesso');
    $cartaA1 = $repo->buscarPorId($idA1);
    $check($cartaA1['status'] === 'enviada', '(confirmar) Status passa para "enviada"');
    $comunicacaoA = Comunicacao::find((int)$cartaA1['comunicacao_id']);
    $check(($comunicacaoA['situacao'] ?? '') === 'enviada', '(confirmar) A Comunicacao vinculada também é marcada como enviada');

    $resolvidoDepois = $service->resolverParaPagina($tokenA, new DateTimeImmutable('now'));
    $check($resolvidoDepois['estado'] === 'formulario', '(acesso) Depois de confirmado, o token agora resolve para o formulário');
    $check($resolvidoDepois['contexto']['nome_candidato'] === $cartaA1['nome_candidato'], '(acesso) O contexto devolvido é exatamente o snapshot da versão certa');

    // ---- 10) token inválido / inexistente ----------------------------------------------------------
    $invalido = $service->resolverParaPagina(str_repeat('0', 64), new DateTimeImmutable('now'));
    $check($invalido['estado'] === 'invalido', '(segurança) Token de 64 hex bem formado mas inexistente -> invalido');
    $malformado = $service->resolverParaPagina('token-curto', new DateTimeImmutable('now'));
    $check($malformado['estado'] === 'invalido', '(segurança) Token malformado -> invalido, sem nem consultar o banco');

    // ---- 11) aceite válido move a candidatura para "Admissão" --------------------------------------
    $candidaturaAntesDecisao = Candidatura::find($candA);
    $decisaoAceite = $service->registrarDecisao($tokenA, 'aceita', null, '203.0.113.10', 'ZZCP-UserAgent/1.0', new DateTimeImmutable('now'));
    $check(($decisaoAceite['ok'] ?? false) && $decisaoAceite['decisao'] === 'aceita', '(aceite) Decisão registrada com sucesso');
    $cartaA1 = $repo->buscarPorId($idA1);
    $check($cartaA1['status'] === 'aceita' && $cartaA1['decisao_ip'] === '203.0.113.10', '(aceite) Status e evidências (IP) registrados');

    $etapaAdmissao = null;
    foreach (PipelineStage::all() as $et) { if (($et['slug'] ?? '') === 'admissao') { $etapaAdmissao = $et; break; } }
    $candidaturaDepoisDecisao = Candidatura::find($candA);
    $check($etapaAdmissao !== null && (int)$candidaturaDepoisDecisao['stage_id'] === (int)$etapaAdmissao['id'], '(movimentação automática) Após o aceite, a candidatura foi movida para a etapa "Admissão" (slug canônico), reaproveitando RecruitmentPipelineService');
    $check((int)$candidaturaAntesDecisao['stage_id'] !== (int)$candidaturaDepoisDecisao['stage_id'], '(movimentação) A etapa realmente mudou (não estava em Admissão antes)');

    // ---- 12) aceite duplicado é bloqueado -----------------------------------------------------------
    $segundaDecisao = $service->registrarDecisao($tokenA, 'aceita', null, '203.0.113.10', 'ZZCP-UserAgent/1.0', new DateTimeImmutable('now'));
    $check(($segundaDecisao['ok'] ?? true) === false, '(prevenção de duplicidade) Decidir de novo com o mesmo token (já decidido) é rejeitado');
    $resolvidoAposDecisao = $service->resolverParaPagina($tokenA, new DateTimeImmutable('now'));
    $check($resolvidoAposDecisao['estado'] === 'concluida', '(página) Depois de decidido, o link mostra "concluída", nunca o formulário de novo');

    // ---- 13) nova versão depois de decidida preserva o histórico -----------------------------------
    $r2 = $service->criarOuReaproveitarRascunho($candA, $usuarioId);
    $check(($r2['ok'] ?? false) && ($r2['id'] ?? 0) !== $idA1, '(nova versão) Uma nova versão é criada (a anterior já foi decidida, não pode ser editada)');
    $idA2 = (int)$r2['id'];
    $criados['cartas'][] = $idA2;
    $cartaA1Final = $repo->buscarPorId($idA1);
    $check($cartaA1Final['status'] === 'aceita', '(preservação) A versão 1 continua "aceita" — nunca é apagada nem alterada pela criação da versão 2');
    $historicoA = $repo->listarPorCandidatura($candA);
    $check(count($historicoA) === 2, '(histórico) As duas versões aparecem no histórico da candidatura');

    // ---- 14) recusa NUNCA movimenta a candidatura; mensagem/motivo registrados --------------------
    $rB = $service->criarOuReaproveitarRascunho($candB, $usuarioId);
    $idB1 = (int)$rB['id'];
    $criados['cartas'][] = $idB1;
    $service->atualizarRascunho($idB1, [
        'nome_candidato' => "ZZCP CandidatoB {$suffix}", 'cargo' => 'Analista ZZCP B', 'setor' => null,
        'empresa_contratante' => 'ZZCP Empresa B', 'salario_proposto' => 2800, 'jornada_trabalho' => '40h semanais',
        'escala_horario' => null, 'beneficios_texto' => null, 'local_trabalho' => null, 'previsao_inicio' => null,
        'validade_proposta' => (new DateTimeImmutable('today'))->modify('+10 days')->format('Y-m-d'), 'observacoes' => null,
    ]);
    $service->emitir($idB1);
    $cartaB1 = $repo->buscarPorId($idB1);
    $criados['arquivosPdf'][] = STORAGE_PATH . DIRECTORY_SEPARATOR . 'cartas_propostas' . DIRECTORY_SEPARATOR . $cartaB1['pdf_nome_armazenado'];
    $preparoB = $service->prepararEnvio($idB1, $usuarioId);
    preg_match('#/carta-proposta/([0-9a-f]{64})#', (string)$preparoB['texto'], $mB);
    $tokenB = $mB[1] ?? '';
    $service->confirmarEnvio($idB1, $usuarioId);

    $candidaturaBAntes = Candidatura::find($candB);
    $decisaoRecusa = $service->registrarDecisao($tokenB, 'recusada', 'ZZCP Motivo: salário abaixo do esperado', '203.0.113.20', 'ZZCP-UA', new DateTimeImmutable('now'));
    $check(($decisaoRecusa['ok'] ?? false) && $decisaoRecusa['decisao'] === 'recusada', '(recusa) Decisão de recusa registrada com sucesso');
    $cartaB1 = $repo->buscarPorId($idB1);
    $check($cartaB1['status'] === 'recusada' && str_contains((string)$cartaB1['motivo_recusa'], 'salário abaixo'), '(recusa) Status e motivo registrados');
    $candidaturaBDepois = Candidatura::find($candB);
    $check((int)$candidaturaBAntes['stage_id'] === (int)$candidaturaBDepois['stage_id'], '(recusa) A candidatura NUNCA é movimentada automaticamente após uma recusa');

    // ---- 15) isolamento entre candidaturas: token de B nunca expõe/afeta dados de A ---------------
    $resolvidoB = $service->resolverParaPagina($tokenB, new DateTimeImmutable('now'));
    $check($resolvidoB['estado'] === 'concluida', '(isolamento) Token B, já decidido, mostra "concluída" (igual ao de A — cada um isolado)');
    $check($tokenA !== $tokenB, '(isolamento) Tokens de candidaturas diferentes são sempre diferentes');

    // ---- 16) validade da proposta vencida é checada INDEPENDENTEMENTE do prazo do token -----------
    $rC = $service->criarOuReaproveitarRascunho($candB, $usuarioId); // reaproveita candB (já decidida -> nova versão)
    $idC = (int)$rC['id'];
    $criados['cartas'][] = $idC;
    $service->atualizarRascunho($idC, [
        'nome_candidato' => "ZZCP CandidatoB {$suffix}", 'cargo' => 'Analista ZZCP B', 'setor' => null,
        'empresa_contratante' => 'ZZCP Empresa B', 'salario_proposto' => 2800, 'jornada_trabalho' => '40h semanais',
        'escala_horario' => null, 'beneficios_texto' => null, 'local_trabalho' => null, 'previsao_inicio' => null,
        'validade_proposta' => (new DateTimeImmutable('today'))->modify('-1 day')->format('Y-m-d'), // já vencida
        'observacoes' => null,
    ]);
    $service->emitir($idC);
    $cartaC = $repo->buscarPorId($idC);
    $criados['arquivosPdf'][] = STORAGE_PATH . DIRECTORY_SEPARATOR . 'cartas_propostas' . DIRECTORY_SEPARATOR . $cartaC['pdf_nome_armazenado'];
    $preparoC = $service->prepararEnvio($idC, $usuarioId);
    preg_match('#/carta-proposta/([0-9a-f]{64})#', (string)$preparoC['texto'], $mC);
    $tokenC = $mC[1] ?? '';
    $service->confirmarEnvio($idC, $usuarioId);
    // Token tecnicamente válido (prazo de 15 dias, recém-criado) e status 'enviada' — mas a
    // VALIDADE DA PROPOSTA (regra de negócio) já passou. Tem que bloquear mesmo assim.
    $resolvidoVencida = $service->resolverParaPagina($tokenC, new DateTimeImmutable('now'));
    $check($resolvidoVencida['estado'] === 'proposta_vencida', '(validade) Proposta com validade_proposta no passado é bloqueada mesmo com token tecnicamente ainda válido — checado no backend, independente do token');

    // ---- 17) prazo TÉCNICO do token (independente da validade da proposta) ------------------------
    // idA2 nunca foi emitida/enviada (ainda rascunho) — simula diretamente um cenário "enviada com token expirado":
    $pdo->prepare("UPDATE cartas_propostas SET status='gerada', pdf_nome_armazenado='zzcp-fake.pdf' WHERE id = ?")->execute([$idA2]);
    $tokenExpirado = bin2hex(random_bytes(32));
    $pdo->prepare("UPDATE cartas_propostas SET token_hash = ?, status = 'enviada', token_expira_em = ? WHERE id = ?")
        ->execute([hash('sha256', $tokenExpirado), (new DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'), $idA2]);
    $resolvidoExpirado = $service->resolverParaPagina($tokenExpirado, new DateTimeImmutable('now'));
    $check($resolvidoExpirado['estado'] === 'indisponivel', '(token) Token tecnicamente expirado -> "indisponivel", categoria DIFERENTE de "proposta_vencida" (validade de negócio) — as duas checagens são independentes');

    // ---- 17b) Histórico da candidatura: timeline unificada projeta aceite/recusa/envio da Carta
    // Proposta a partir de cartas_propostas, SEM gravar nada novo e sem duplicar o que já existe
    // em comunicacoes (correção de homologação 2026-11) ----------------------------------------------
    $metodoEventos = new ReflectionMethod(AdminCandidaturasController::class, 'eventosCartaProposta');
    $controllerCandidaturas = new AdminCandidaturasController();

    $eventosA = $metodoEventos->invoke($controllerCandidaturas, $repo->listarPorCandidatura($candA));
    $tiposA = array_column($eventosA, 'situacao');
    $check(in_array('enviada', $tiposA, true), '(histórico) Evento "Carta Proposta enviada" é projetado a partir de cartas_propostas.enviada_em');
    $check(in_array('aceita', $tiposA, true), '(histórico) Evento "Carta Proposta aceita" é projetado a partir de cartas_propostas.decisao_em/status');
    $eventoAceite = current(array_filter($eventosA, static fn(array $e): bool => $e['situacao'] === 'aceita'));
    $check(($eventoAceite['usuario_nome'] ?? null) === 'Candidato', '(histórico) O evento de aceite identifica claramente que a decisão foi do CANDIDATO, nunca do RH');
    $check(str_contains((string)($eventoAceite['mensagem_titulo'] ?? ''), 'versão 1'), '(histórico) Evento de aceite referencia a versão correta da proposta (versão 1, a única decidida de A)');

    $eventosB = $metodoEventos->invoke($controllerCandidaturas, $repo->listarPorCandidatura($candB));
    $tiposB = array_column($eventosB, 'situacao');
    $check(in_array('recusada', $tiposB, true), '(histórico) Evento "Carta Proposta recusada" é projetado a partir de cartas_propostas.decisao_em/status');
    $eventoRecusa = current(array_filter($eventosB, static fn(array $e): bool => $e['situacao'] === 'recusada'));
    $check(str_contains((string)($eventoRecusa['conteudo'] ?? ''), 'salário abaixo'), '(histórico) Evento de recusa expõe o motivo já registrado em cartas_propostas.motivo_recusa, sem duplicar o armazenamento');

    $check(in_array('emitida', $tiposA, true), '(histórico) Evento "Carta Proposta emitida" é projetado a partir de cartas_propostas.gerada_em');
    $comunicacoesReaisA = Comunicacao::allByCandidatura($candA);
    $check(count($eventosA) === 3, '(histórico) Exatamente 3 eventos projetados para A (emitida + enviada + aceita da versão 1 — a versão 2 nunca foi de fato emitida/enviada pelo serviço, só manipulada por SQL para o teste de token expirado)');
    $totalMerged = array_merge($comunicacoesReaisA, $eventosA);
    usort($totalMerged, static fn(array $a, array $b): int => strtotime((string)$b['created_at']) <=> strtotime((string)$a['created_at']));
    $check(count($totalMerged) === count($comunicacoesReaisA) + count($eventosA), '(histórico) A mescla com as comunicações reais (mesma lógica do controller) não perde nem duplica nenhum item');

    // ---- 17c) Título da página pública (correção de homologação 2026-11: layout compartilhado com
    // a Entrevista de Desligamento mostrava o título errado na Carta Proposta) ----------------------
    $viewPublica = new View();
    $paramsPublicos = ['base' => '', 'estado' => 'invalido', 'titulo' => 'Carta Proposta | Madeplant', 'csrf' => Security::csrfToken()];
    $conteudoPublico = $viewPublica->renderPartial('carta_proposta/publica', $paramsPublicos);
    $htmlPublico = $viewPublica->renderPartial('layouts/publico-seguro', $paramsPublicos + ['content' => $conteudoPublico]);
    $check(str_contains($htmlPublico, '<title>Carta Proposta | Madeplant</title>'), '(título) A página pública da Carta Proposta usa o título correto, nunca o da Entrevista de Desligamento');
    $check(!str_contains($htmlPublico, 'Entrevista de Desligamento'), '(título) Nenhuma referência à Entrevista de Desligamento aparece na página pública da Carta Proposta');

    $paramsEntrevista = ['base' => '', 'estado' => 'invalido', 'csrf' => Security::csrfToken()];
    $conteudoEntrevista = $viewPublica->renderPartial('entrevista_desligamento/publica', $paramsEntrevista);
    $htmlEntrevistaDefault = $viewPublica->renderPartial('layouts/publico-seguro', $paramsEntrevista + ['content' => $conteudoEntrevista]);
    $check(str_contains($htmlEntrevistaDefault, '<title>Entrevista de Desligamento — Madeplant</title>'), '(título) O título padrão da Entrevista de Desligamento continua INALTERADO (layout compartilhado, mas default preservado)');

    // ---- 17d) Via com comprovante de aceite: gerada automaticamente após o aceite, PDF SEPARADO
    // do original, nunca sobrescreve o original, idempotente (correção de homologação 2026-11) ------
    $cartaA1ComComprovante = $repo->buscarPorId($idA1);
    $check(!empty($cartaA1ComComprovante['comprovante_pdf_nome_armazenado']), '(comprovante) Via com comprovante de aceite é gerada automaticamente logo após o aceite válido');
    $check(!empty($cartaA1ComComprovante['comprovante_identificador']), '(comprovante) Identificador público da decisão é gerado (nunca o id interno auto-increment)');
    $check($cartaA1ComComprovante['comprovante_pdf_nome_armazenado'] !== $cartaA1ComComprovante['pdf_nome_armazenado'], '(comprovante) O comprovante é um ARQUIVO SEPARADO do PDF original, nunca o sobrescreve');
    $caminhoComprovanteA1 = STORAGE_PATH . DIRECTORY_SEPARATOR . 'cartas_propostas' . DIRECTORY_SEPARATOR . $cartaA1ComComprovante['comprovante_pdf_nome_armazenado'];
    $criados['arquivosPdf'][] = $caminhoComprovanteA1;
    $check(is_file($caminhoComprovanteA1) && filesize($caminhoComprovanteA1) > 0, '(comprovante) O arquivo do comprovante foi realmente salvo em disco, com conteúdo');
    $caminhoOriginalA1 = STORAGE_PATH . DIRECTORY_SEPARATOR . 'cartas_propostas' . DIRECTORY_SEPARATOR . $cartaA1ComComprovante['pdf_nome_armazenado'];
    $check(is_file($caminhoOriginalA1), '(comprovante) O PDF ORIGINAL continua preservado e intacto no disco, sem alteração');

    $reflGerarComprovante = new ReflectionMethod(CartaPropostaService::class, 'gerarComprovanteAceite');
    $reflGerarComprovante->invoke($service, $idA1);
    $cartaA1Reconsultada = $repo->buscarPorId($idA1);
    $check($cartaA1Reconsultada['comprovante_pdf_nome_armazenado'] === $cartaA1ComComprovante['comprovante_pdf_nome_armazenado'], '(comprovante) Idempotente: chamar de novo nunca gera um segundo comprovante para a mesma versão');

    // Tela de edição (admin) renderiza sem exceção no estado 'aceita' COM comprovante já gerado —
    // branch nova desta correção (links de Visualizar/Baixar comprovante).
    $htmlAceitaComComprovante = $viewTeste->renderPartial('admin/carta_proposta/editar', [
        'base' => '', 'carta' => $cartaA1Reconsultada, 'candidatura' => Candidatura::find($candA), 'historico' => $repo->listarPorCandidatura($candA), 'csrf' => Security::csrfToken(),
    ]);
    $check(str_contains($htmlAceitaComComprovante, 'Visualizar comprovante') && str_contains($htmlAceitaComComprovante, 'Baixar comprovante'), '(abertura) Página de edição, com a proposta já aceita e o comprovante já gerado, mostra os links de Visualizar/Baixar comprovante sem exceção');

    // ---- 17e) Link de decisão já utilizado NUNCA mais expõe PDF (nem o original, nem qualquer
    // dado) — único gate (resolverAcessoPorToken) reaproveitado pela página E pelo PDF -------------
    $acessoAposDecisaoA = $service->resolverAcessoPorToken($tokenA, new DateTimeImmutable('now'));
    $check($acessoAposDecisaoA['estado'] === 'concluida' && $acessoAposDecisaoA['carta'] === null, '(segurança) Depois de decidida, resolverAcessoPorToken nunca devolve a carta (nenhum PDF pode ser servido pelo link já usado)');

    // ---- 17f) Validade vencida bloqueia o PDF mesmo de uma proposta JÁ ACEITA (a validade do link
    // de aceite é independente do direito de acesso posterior à via aceita, que é outro mecanismo) --
    $pdo->prepare("UPDATE cartas_propostas SET validade_proposta = ? WHERE id = ?")
        ->execute([(new DateTimeImmutable('-30 days'))->format('Y-m-d'), $idA1]);
    $acessoVencidaMesmoAceita = $service->resolverAcessoPorToken($tokenA, new DateTimeImmutable('now'));
    $check($acessoVencidaMesmoAceita['estado'] === 'proposta_vencida' && $acessoVencidaMesmoAceita['carta'] === null, '(validade) Depois que a validade da proposta passa, o link mostra "vencida" mesmo para uma proposta JÁ ACEITA — nunca mais "concluída"');
    // restaura para não afetar nenhuma checagem seguinte que ainda use $tokenA/$idA1.
    $pdo->prepare("UPDATE cartas_propostas SET validade_proposta = ? WHERE id = ?")
        ->execute([(new DateTimeImmutable('today'))->modify('+10 days')->format('Y-m-d'), $idA1]);

    // ---- 17g) Fuso de NEGÓCIO (Campo Grande/MS) no corte de validade — independente do timezone
    // padrão do servidor PHP (correção de homologação 2026-11: ambiente roda em Europe/Berlin,
    // 5h adiantado em relação a Campo Grande; sem fuso explícito, o corte de "23:59:59" expirava
    // a proposta ~5h mais cedo do que o horário real do candidato) -----------------------------------
    $rFuso = $service->criarOuReaproveitarRascunho($candB, $usuarioId); // reaproveita candB (já tem versões decididas -> nova versão)
    $idFuso = (int)$rFuso['id'];
    $criados['cartas'][] = $idFuso;
    $service->atualizarRascunho($idFuso, [
        'nome_candidato' => "ZZCP CandidatoFuso {$suffix}", 'cargo' => 'ZZCP Cargo Fuso', 'setor' => null,
        'empresa_contratante' => 'ZZCP Empresa Fuso', 'salario_proposto' => 3000, 'jornada_trabalho' => '40h semanais',
        'escala_horario' => null, 'beneficios_texto' => null, 'local_trabalho' => null, 'previsao_inicio' => null,
        'validade_proposta' => '2026-01-01', 'observacoes' => null,
    ]);
    $service->emitir($idFuso);
    $cartaFuso = $repo->buscarPorId($idFuso);
    $criados['arquivosPdf'][] = STORAGE_PATH . DIRECTORY_SEPARATOR . 'cartas_propostas' . DIRECTORY_SEPARATOR . $cartaFuso['pdf_nome_armazenado'];
    $preparoFuso = $service->prepararEnvio($idFuso, $usuarioId);
    preg_match('#/carta-proposta/([0-9a-f]{64})#', (string)$preparoFuso['texto'], $mFuso);
    $tokenFuso = $mFuso[1] ?? '';
    $service->confirmarEnvio($idFuso, $usuarioId);

    // 2026-01-02 01:00 UTC está DEPOIS de "2026-01-01 23:59:59" interpretado em Berlin (UTC+1,
    // = 2026-01-01 22:59:59 UTC) mas ainda ANTES de "2026-01-01 23:59:59" em Campo Grande (UTC-4,
    // = 2026-01-02 03:59:59 UTC). Se o código não fixar o fuso de negócio, este instante já
    // apareceria como vencido (bug); com o fuso correto, ainda deve estar dentro do prazo.
    $agoraEntreOsDoisFusos = new DateTimeImmutable('2026-01-02 01:00:00', new DateTimeZone('UTC'));
    $acessoFuso = $service->resolverAcessoPorToken($tokenFuso, $agoraEntreOsDoisFusos);
    $check($acessoFuso['estado'] === 'formulario', '(fuso) Corte de validade usa o fuso de NEGÓCIO (America/Campo_Grande) explicitamente — não expira ~5h mais cedo por causa do timezone padrão do servidor (Europe/Berlin)');

    // Bem depois do corte em QUALQUER fuso -> vencida de qualquer forma.
    $acessoFusoBemDepois = $service->resolverAcessoPorToken($tokenFuso, new DateTimeImmutable('2026-01-05 12:00:00', new DateTimeZone('UTC')));
    $check($acessoFusoBemDepois['estado'] === 'proposta_vencida', '(fuso) Bem depois do corte, em qualquer fuso, a proposta aparece vencida');

    // ---- 17h) Documentos unificados: PDF original + comprovante aparecem ao lado dos documentos
    // reais, sem copiar nenhum arquivo físico para candidatura_documentos ---------------------------
    $reflDocsUnificados = new ReflectionMethod(AdminCandidaturasController::class, 'documentosUnificados');
    $controllerCandidaturasDocs = new AdminCandidaturasController();
    $docsUnificadosA = $reflDocsUnificados->invoke($controllerCandidaturasDocs, [], $repo->listarPorCandidatura($candA));
    $nomesDocsA = array_column($docsUnificadosA, 'nome_original');
    $check(in_array('Carta Proposta — Versão 1.pdf', $nomesDocsA, true), '(documentos) PDF original da Carta Proposta aparece na listagem unificada, com o nome esperado');
    $check(in_array('Carta Proposta Aceita — Versão 1.pdf', $nomesDocsA, true), '(documentos) Via com comprovante de aceite aparece na listagem unificada, com o nome esperado');
    $docOriginalA = current(array_filter($docsUnificadosA, static fn(array $d): bool => $d['nome_original'] === 'Carta Proposta — Versão 1.pdf'));
    $check($docOriginalA['url_baixar'] === '/admin/cartas-propostas/' . $idA1 . '/pdf', '(documentos) URL de download do PDF original reaproveita o endpoint já existente, sem rota nova');
    $check($docOriginalA['mime'] === 'application/pdf' && $docOriginalA['tamanho_bytes'] > 0, '(documentos) Tamanho real do arquivo é lido do disco (não é um valor fictício)');

    // ---- 18) permissões — inspeção de código (mesmo padrão já usado para documentos) --------------
    $corpoAdmin = file_get_contents(__DIR__ . '/../../app/controllers/AdminCartaPropostaController.php');
    foreach (['criar', 'editar', 'atualizar', 'emitir', 'prepararEnvio', 'confirmarEnvio', 'pdf'] as $metodo) {
        $r = new ReflectionMethod(AdminCartaPropostaController::class, $metodo);
        $arq = new SplFileObject($r->getFileName());
        $arq->seek($r->getStartLine() - 1);
        $corpo = '';
        while ($arq->key() < $r->getEndLine()) { $corpo .= $arq->current(); $arq->next(); }
        $check(str_contains($corpo, "Auth::requireRole(['admin', 'rh'])"), "(acesso) AdminCartaPropostaController::{$metodo}() exige admin/rh");
    }
    unset($corpoAdmin);

    // ---- 19) regressão: nome do parâmetro do método PRECISA bater com o placeholder da rota -------
    // Router::invoke() despacha via call_user_func_array() com um array ASSOCIATIVO vindo dos grupos
    // nomeados da própria rota ({id}, {token}, ...) — PHP interpreta isso como argumentos NOMEADOS.
    // Um parâmetro com nome diferente do placeholder (ex.: {id} + `function criar(string $candidaturaId)`)
    // nunca dá erro no `php -l`/autoload — só estoura em runtime, na primeira requisição real
    // (bug real encontrado em homologação local: "Unknown named parameter $id" em Router.php:57,
    // mascarado pelo catch genérico de index.php como "Erro interno do sistema.").
    $indexPhp = file_get_contents(__DIR__ . '/../../index.php');
    preg_match_all(
        "#\\\$router->(?:get|post)\\('([^']+)',\\s*\\[AdminCartaPropostaController::class,\\s*'(\\w+)'\\]\\)#",
        $indexPhp,
        $rotas,
        PREG_SET_ORDER
    );
    $check(count($rotas) >= 7, '(rotas) Todas as rotas de AdminCartaPropostaController foram encontradas em index.php para a checagem');
    foreach ($rotas as [, $rota, $metodo]) {
        preg_match_all('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', $rota, $mPlaceholders);
        $placeholders = $mPlaceholders[1];
        $parametrosMetodo = array_map(
            static fn(ReflectionParameter $p): string => $p->getName(),
            (new ReflectionMethod(AdminCartaPropostaController::class, $metodo))->getParameters()
        );
        foreach ($placeholders as $nomePlaceholder) {
            $check(in_array($nomePlaceholder, $parametrosMetodo, true), "(rotas) {$rota} -> {$metodo}(): parâmetro \${$nomePlaceholder} existe (placeholder da rota vira argumento NOMEADO)");
        }
    }

    echo "\nCARTA_PROPOSTA_OK\n";
} finally {
    if (!empty($criados['arquivosPdf'])) {
        foreach ($criados['arquivosPdf'] as $arq) { @unlink($arq); }
    }
    if (!empty($criados['cartas'])) {
        $ph = implode(',', array_fill(0, count($criados['cartas']), '?'));
        $pdo->prepare("DELETE FROM cartas_propostas WHERE id IN ($ph)")->execute($criados['cartas']);
    }
    if (!empty($criados['candidaturas'])) {
        $ph = implode(',', array_fill(0, count($criados['candidaturas']), '?'));
        $pdo->prepare("DELETE FROM pipeline_movements WHERE candidatura_id IN ($ph)")->execute($criados['candidaturas']);
        $pdo->prepare("DELETE FROM candidatura_historico WHERE candidatura_id IN ($ph)")->execute($criados['candidaturas']);
        $pdo->prepare("DELETE FROM comunicacoes WHERE candidatura_id IN ($ph)")->execute($criados['candidaturas']);
        $pdo->prepare("DELETE FROM candidaturas WHERE id IN ($ph)")->execute($criados['candidaturas']);
    }
    if (!empty($criados['vagas'])) {
        $ph = implode(',', array_fill(0, count($criados['vagas']), '?'));
        $pdo->prepare("DELETE FROM vaga_beneficios WHERE vaga_id IN ($ph)")->execute($criados['vagas']);
        $pdo->prepare("DELETE FROM vagas WHERE id IN ($ph)")->execute($criados['vagas']);
    }
    if (!empty($criados['solicitacoesVaga'])) {
        $ph = implode(',', array_fill(0, count($criados['solicitacoesVaga']), '?'));
        $pdo->prepare("DELETE FROM solicitacoes_vaga WHERE id IN ($ph)")->execute($criados['solicitacoesVaga']);
    }
    if (!empty($criados['beneficios'])) {
        $ph = implode(',', array_fill(0, count($criados['beneficios']), '?'));
        $pdo->prepare("DELETE FROM beneficios WHERE id IN ($ph)")->execute($criados['beneficios']);
    }
    if (!empty($criados['mensagens'])) {
        $ph = implode(',', array_fill(0, count($criados['mensagens']), '?'));
        $pdo->prepare("DELETE FROM mensagens WHERE id IN ($ph)")->execute($criados['mensagens']);
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
echo "\nCARTA_PROPOSTA_OK\n";
