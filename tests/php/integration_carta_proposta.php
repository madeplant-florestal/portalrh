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
$criados = ['vagas' => [], 'beneficios' => [], 'candidaturas' => [], 'mensagens' => [], 'cartas' => [], 'arquivosPdf' => []];

try {
    // ---- template de mensagem (não existe por padrão — precisa ser criado em Configurações) --
    $tpl = Mensagem::create([
        'codigo' => 'zzcp_carta_proposta_' . $suffix,
        'titulo' => 'ZZCP Carta Proposta enviada',
        'conteudo' => "Olá [Nome], sua Carta Proposta está pronta: [Link da Proposta]",
        'ativo' => true,
    ]);
    $check($tpl['ok'] ?? false, '(setup) Template de mensagem criado para o teste');
    $criados['mensagens'][] = $tpl['id'];
    // PHP não permite sobrescrever uma constante de classe em runtime, e o código é fixo
    // (CartaPropostaService::CODIGO_MENSAGEM_ENVIO) — por isso o teste garante que o template
    // OFICIAL existe (cria um só se o ambiente ainda não tiver nenhum), em vez de usar um código
    // fictício que o serviço nunca procuraria.
    $codigoOriginal = CartaPropostaService::CODIGO_MENSAGEM_ENVIO;
    $existente = Mensagem::findAtivaByCodigo($codigoOriginal);
    if ($existente === null) {
        Mensagem::create(['codigo' => $codigoOriginal, 'titulo' => 'Carta Proposta enviada (fixture de teste)', 'conteudo' => 'Olá [Nome], [Link da Proposta]', 'ativo' => true]);
        $criadoOficial = Mensagem::findAtivaByCodigo($codigoOriginal);
        if ($criadoOficial !== null) { $criados['mensagens'][] = (int)$criadoOficial['id']; }
    }
    $check(Mensagem::findAtivaByCodigo($codigoOriginal) !== null, '(setup) Template oficial "' . $codigoOriginal . '" está disponível para o teste (criado se ainda não existia)');

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
