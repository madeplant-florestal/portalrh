<?php

/**
 * Integração — Agendamento do Exame Admissional (correção de 2026-11, fluxo Carta Proposta ->
 * Admissão -> Exame Admissional). Prova, contra o banco real:
 *   - o agendamento só é liberado depois de existir uma Carta Proposta ACEITA para a candidatura
 *     (checado no backend, nunca só pela visibilidade do botão);
 *   - a candidatura nunca é movimentada automaticamente por esta funcionalidade (já está em
 *     Admissão por força do aceite da Carta Proposta, e continua lá);
 *   - rascunho incompleto é permitido, mas "preparar mensagem" exige os 5 campos preenchidos;
 *   - a renderização final não pode deixar NENHUM placeholder (incluindo [Nome]) sem substituir;
 *   - template ausente nunca falha silenciosamente;
 *   - "preparar" nunca é "enviar" — só confirmarEnvio() explícito marca como enviado, e isso é uma
 *     declaração manual do RH, nunca uma confirmação de entrega real;
 *   - editar qualquer um dos 5 campos depois de preparado/enviado invalida a preparação anterior
 *     (reagendamento), com histórico completo de antes/depois, usuário e data;
 *   - permissões/CSRF dos endpoints administrativos.
 *
 * Fixtures ZZEA-* (vaga, candidatura, carta proposta, usuário, mensagem), com limpeza em `finally`.
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
$criados = ['vagas' => [], 'candidaturas' => [], 'mensagens' => [], 'cartas' => [], 'arquivosPdf' => []];

try {
    // ---- usuário RH -----------------------------------------------------------------------------
    $usuarioId = (int)$pdo->query("SELECT id FROM usuarios WHERE role IN ('admin','rh') ORDER BY id LIMIT 1")->fetchColumn();
    $check($usuarioId > 0, '(pré-condição) Existe usuário admin/rh local');

    // ---- template da Carta Proposta — precondição para chegar ao aceite (mesma técnica de
    // integration_carta_proposta.php: cria só se o ambiente ainda não tiver o template oficial) ----
    $codigoCarta = CartaPropostaService::CODIGO_MENSAGEM_ENVIO;
    if (Mensagem::findAtivaByCodigo($codigoCarta) === null) {
        $tplCarta = Mensagem::create(['codigo' => $codigoCarta, 'titulo' => 'Carta Proposta enviada (fixture de teste)', 'conteudo' => 'Olá [Nome], [Link da Proposta]', 'ativo' => true]);
        if ($tplCarta['ok'] ?? false) { $criados['mensagens'][] = (int)$tplCarta['id']; }
    }

    // ---- vaga + candidatura -----------------------------------------------------------------------
    $pdo->prepare("INSERT INTO vagas (titulo, descricao, requisitos, ativo) VALUES (?, 'desc', 'req', 1)")->execute(["ZZEA Vaga {$suffix}"]);
    $vagaId = (int)$pdo->lastInsertId();
    $criados['vagas'][] = $vagaId;

    $pdo->prepare(
        "INSERT INTO candidaturas (vaga_id, nome, email, telefone, cpf, cargo_pretendido, experiencia, pdf_path, status)
         VALUES (?, ?, ?, '11999999999', ?, 'Cargo Teste', 'exp', 'x.pdf', 'novo')"
    )->execute([$vagaId, "ZZEA Candidato Silva {$suffix}", "zzea{$suffix}@teste.local", '30000000001']);
    $candId = (int)$pdo->lastInsertId();
    $criados['candidaturas'][] = $candId;

    $cartaService = new CartaPropostaService();
    $cartaRepo = new CartaPropostaRepository();
    $service = new ExameAdmissionalService();
    $repo = new ExameAdmissionalRepository();

    // ---- 1) sem Carta Proposta aceita, o agendamento é bloqueado no backend ----------------------
    $semAceite = $service->obterOuCriar($candId, $usuarioId);
    $check(($semAceite['ok'] ?? true) === false, '(gate) Sem Carta Proposta aceita, obterOuCriar() é rejeitado no backend');
    $check($repo->buscarPorCandidatura($candId) === null, '(gate) Nenhuma linha é criada enquanto o gate não passa');

    // ---- 2) leva a candidatura até Carta Proposta ACEITA -------------------------------------------
    $rCarta = $cartaService->criarOuReaproveitarRascunho($candId, $usuarioId);
    $idCarta = (int)$rCarta['id'];
    $criados['cartas'][] = $idCarta;
    $cartaService->atualizarRascunho($idCarta, [
        'nome_candidato' => "ZZEA Candidato Silva {$suffix}", 'cargo' => 'Analista ZZEA', 'setor' => null,
        'empresa_contratante' => 'ZZEA Empresa', 'salario_proposto' => 3000, 'jornada_trabalho' => '44h semanais',
        'escala_horario' => null, 'beneficios_texto' => null, 'local_trabalho' => null, 'previsao_inicio' => null,
        'validade_proposta' => (new DateTimeImmutable('today'))->modify('+10 days')->format('Y-m-d'), 'observacoes' => null,
    ]);
    $cartaService->emitir($idCarta);
    $carta = $cartaRepo->buscarPorId($idCarta);
    $criados['arquivosPdf'][] = STORAGE_PATH . DIRECTORY_SEPARATOR . 'cartas_propostas' . DIRECTORY_SEPARATOR . $carta['pdf_nome_armazenado'];
    $preparoCarta = $cartaService->prepararEnvio($idCarta, $usuarioId);
    preg_match('#/carta-proposta/([0-9a-f]{64})#', (string)$preparoCarta['texto'], $m);
    $tokenCarta = $m[1] ?? '';
    $cartaService->confirmarEnvio($idCarta, $usuarioId);
    $decisao = $cartaService->registrarDecisao($tokenCarta, 'aceita', null, '203.0.113.50', 'ZZEA-UA', new DateTimeImmutable('now'));
    $check(($decisao['ok'] ?? false) && $decisao['decisao'] === 'aceita', '(setup) Carta Proposta aceita com sucesso — Candidatura movida para Admissão');
    $stageAposAceite = (int)(Candidatura::find($candId)['stage_id'] ?? 0);

    // ---- 3) com a Carta Proposta aceita, o agendamento é liberado ----------------------------------
    $r1 = $service->obterOuCriar($candId, $usuarioId);
    $check($r1['ok'] ?? false, '(criar) Com Carta Proposta aceita, o agendamento é criado com sucesso');
    $examId = (int)$r1['id'];
    $exame1 = $repo->buscarPorId($examId);
    $check($exame1['status'] === 'rascunho', '(criar) Status inicial é rascunho');

    // reabrir não duplica a linha (UNIQUE candidatura_id).
    $r1b = $service->obterOuCriar($candId, $usuarioId);
    $check(($r1b['id'] ?? null) === $examId, '(sem duplicidade) Reabrir devolve o MESMO agendamento, nunca cria outro');

    // ---- 4) rascunho incompleto é permitido, mas bloqueia o preparo da mensagem -------------------
    $parcial = $service->atualizarDados($examId, ['clinica' => 'ZZEA Clínica Central', 'endereco' => null, 'data_exame' => null, 'horario' => null, 'telefone_contato' => null], $usuarioId);
    $check($parcial['ok'] ?? false, '(rascunho) Salvar dados parciais é permitido');
    $tentativaPreparo = $service->prepararMensagem($examId, $usuarioId);
    $check(($tentativaPreparo['ok'] ?? true) === false, '(validação) Preparar mensagem com dados incompletos é rejeitado');
    $check(str_contains((string)$tentativaPreparo['error'], 'Endereço') && str_contains((string)$tentativaPreparo['error'], 'Telefone'), '(validação) Erro lista os campos obrigatórios faltantes');

    // ---- 5) validação de formato de data/horário no backend --------------------------------------
    $dataInvalida = $service->atualizarDados($examId, ['clinica' => 'X', 'endereco' => 'Y', 'data_exame' => '31/13/2026', 'horario' => '10:00', 'telefone_contato' => '123'], $usuarioId);
    $check(($dataInvalida['ok'] ?? true) === false, '(validação) Data em formato inválido é rejeitada no backend');
    $horarioInvalido = $service->atualizarDados($examId, ['clinica' => 'X', 'endereco' => 'Y', 'data_exame' => '2026-12-01', 'horario' => '25:99', 'telefone_contato' => '123'], $usuarioId);
    $check(($horarioInvalido['ok'] ?? true) === false, '(validação) Horário em formato inválido é rejeitado no backend');

    // ---- 6) data futura é o uso normal — nunca rejeitada por estar no futuro ----------------------
    $dataFutura = (new DateTimeImmutable('today'))->modify('+30 days')->format('Y-m-d');
    $completo = $service->atualizarDados($examId, [
        'clinica' => 'ZZEA Clínica Central', 'endereco' => 'ZZEA Rua das Flores, 100',
        'data_exame' => $dataFutura, 'horario' => '09:30', 'telefone_contato' => '(65) 99999-0000',
    ], $usuarioId);
    $check($completo['ok'] ?? false, '(validação) Data futura + dados completos são aceitos sem bloqueio');

    // ---- 7) template ausente nunca falha silenciosamente (só quando o ambiente ainda não o tem) ---
    $templateJaExistia = Mensagem::findAtivaByCodigo(ExameAdmissionalService::CODIGO_MENSAGEM) !== null;
    if (!$templateJaExistia) {
        $semTemplate = $service->prepararMensagem($examId, $usuarioId);
        $check(($semTemplate['ok'] ?? true) === false && str_contains((string)$semTemplate['error'], 'Configurações'), '(template ausente) Erro claro e específico, nunca falha silenciosa');
        $tpl = Mensagem::create([
            'codigo' => ExameAdmissionalService::CODIGO_MENSAGEM,
            'titulo' => 'Exame Admissional agendado (fixture de teste)',
            'conteudo' => "Olá, [Nome].\n\nData: [Data] Horário: [Horário]\nClínica: [Nome da Clínica]\nEndereço: [Endereço]\nContato: [Telefone]",
            'ativo' => true,
        ]);
        $check($tpl['ok'] ?? false, '(setup) Template oficial "' . ExameAdmissionalService::CODIGO_MENSAGEM . '" criado para o teste');
        $criados['mensagens'][] = $tpl['id'];
    } else {
        echo "  [info] Template '" . ExameAdmissionalService::CODIGO_MENSAGEM . "' já cadastrado no ambiente — pulando a verificação de 'template ausente'.\n";
    }

    // ---- 8) preparar mensagem: todos os placeholders substituídos, nenhum [X] sobra ----------------
    $preparo1 = $service->prepararMensagem($examId, $usuarioId);
    $check($preparo1['ok'] ?? false, '(preparar) Mensagem preparada com sucesso, com todos os dados');
    $check(!preg_match('/\[[^\]]+\]/', (string)($preparo1['texto'] ?? '')), '(placeholders) Nenhum placeholder sobra sem substituir no texto final (inclui [Nome])');
    $check(str_contains((string)$preparo1['texto'], 'Olá, ZZEA.'), '(placeholders) [Nome] foi substituído pelo primeiro nome real do candidato');
    $check(str_contains((string)$preparo1['texto'], 'ZZEA Clínica Central'), '(placeholders) [Nome da Clínica] foi substituído');
    $exame2 = $repo->buscarPorId($examId);
    $check($exame2['status'] === 'mensagem_preparada', '(preparar ≠ enviar) Status passa para "mensagem_preparada", NUNCA para "enviado"');
    $comunicacaoId1 = (int)$exame2['comunicacao_id'];
    $check($comunicacaoId1 > 0, '(preparar) Comunicacao vinculada');

    // reabrir/preparar de novo sem mudar dados reaproveita a mesma Comunicacao (idempotência).
    $preparo2 = $service->prepararMensagem($examId, $usuarioId);
    $exame2b = $repo->buscarPorId($examId);
    $check((int)$exame2b['comunicacao_id'] === $comunicacaoId1, '(idempotência) Preparar de novo sem alterar dados reaproveita a mesma Comunicacao, nunca duplica');

    // ---- 9) confirmação manual do envio (declaração do RH, não entrega comprovada) -----------------
    $confirmacao = $service->confirmarEnvio($examId, $usuarioId);
    $check($confirmacao['ok'] ?? false, '(confirmar) Envio confirmado com sucesso');
    $exame3 = $repo->buscarPorId($examId);
    $check($exame3['status'] === 'enviado' && (int)$exame3['enviado_por_usuario_id'] === $usuarioId && !empty($exame3['enviado_em']), '(confirmar) Status, usuário e data/hora da confirmação registrados');
    $comunicacao1 = Comunicacao::find($comunicacaoId1);
    $check(($comunicacao1['situacao'] ?? '') === 'enviada', '(confirmar) A Comunicacao vinculada também é marcada como enviada');

    // confirmar de novo (já enviado) é bloqueado — nunca reconfirma indefinidamente.
    $confirmacao2 = $service->confirmarEnvio($examId, $usuarioId);
    $check(($confirmacao2['ok'] ?? true) === false, '(idempotência) Confirmar de novo um agendamento já "enviado" é rejeitado');

    // ---- 10) reagendamento depois de enviado invalida a preparação anterior -----------------------
    $novaData = (new DateTimeImmutable('today'))->modify('+45 days')->format('Y-m-d');
    $reagendar = $service->atualizarDados($examId, [
        'clinica' => 'ZZEA Clínica Nova', 'endereco' => 'ZZEA Rua das Flores, 100',
        'data_exame' => $novaData, 'horario' => '14:00', 'telefone_contato' => '(65) 99999-0000',
    ], $usuarioId);
    $check($reagendar['ok'] ?? false, '(reagendar) Alteração de clínica/data/horário depois de enviado é permitida');
    $exame4 = $repo->buscarPorId($examId);
    $check($exame4['status'] === 'rascunho', '(invalidação) A preparação/envio anterior é invalidada — status volta para "rascunho"');
    $check(empty($exame4['comunicacao_id']) && empty($exame4['enviado_em']) && empty($exame4['enviado_por_usuario_id']), '(invalidação) Comunicacao/data de envio anteriores são limpas — nunca ficam associadas a dados já desatualizados');
    $check($exame4['clinica'] === 'ZZEA Clínica Nova', '(reagendar) Novo valor da clínica persistido');

    // preparar de novo APÓS reagendar gera uma Comunicacao NOVA (a anterior não é reaproveitada).
    $preparo3 = $service->prepararMensagem($examId, $usuarioId);
    $exame5 = $repo->buscarPorId($examId);
    $check($preparo3['ok'] ?? false && (int)$exame5['comunicacao_id'] !== $comunicacaoId1, '(reagendar) Nova preparação depois do reagendamento cria uma Comunicacao NOVA, nunca reaproveita o texto desatualizado');
    $check(str_contains((string)$preparo3['texto'], 'ZZEA Clínica Nova'), '(reagendar) O novo texto reflete a clínica atualizada');
    $service->confirmarEnvio($examId, $usuarioId);

    // ---- 11) histórico de alterações: criado/atualizado/mensagem_preparada/envio_confirmado/reagendado
    $historico = $repo->historico($examId);
    $tipos = array_column($historico, 'tipo_evento');
    $check(in_array('criado', $tipos, true), '(histórico) Evento "criado" registrado');
    $check(in_array('atualizado', $tipos, true), '(histórico) Evento "atualizado" registrado (preenchimento inicial)');
    $check(count(array_filter($tipos, static fn($t) => $t === 'mensagem_preparada')) >= 2, '(histórico) Dois eventos "mensagem_preparada" registrados (antes e depois do reagendamento)');
    $check(count(array_filter($tipos, static fn($t) => $t === 'envio_confirmado')) >= 2, '(histórico) Dois eventos "envio_confirmado" registrados');
    $check(in_array('reagendado', $tipos, true), '(histórico) Evento "reagendado" registrado, com dados anteriores/novos preservados');
    $eventoReagendado = null;
    foreach ($historico as $h) { if ($h['tipo_evento'] === 'reagendado') { $eventoReagendado = $h; break; } }
    $check($eventoReagendado !== null && (int)$eventoReagendado['usuario_id'] === $usuarioId, '(histórico) Usuário responsável pelo reagendamento registrado');
    $dadosAnteriores = json_decode((string)$eventoReagendado['dados_anteriores'], true);
    $dadosNovos = json_decode((string)$eventoReagendado['dados_novos'], true);
    $check(($dadosAnteriores['clinica'] ?? null) === 'ZZEA Clínica Central' && ($dadosNovos['clinica'] ?? null) === 'ZZEA Clínica Nova', '(histórico) Snapshot JSON preserva os dados ANTERIORES e NOVOS do reagendamento');

    // ---- 12) consulta posterior no perfil (via repositório/serviço, mesma fonte usada pela tela) ---
    $check($service->historicoPorCandidatura($candId) !== [], '(consulta) Histórico consultável a partir da candidatura, sem perder reagendamentos anteriores');
    $check($repo->buscarPorCandidatura($candId)['id'] === $examId, '(consulta) O agendamento é encontrado a partir da candidatura');

    // ---- 13) a candidatura NUNCA é movimentada automaticamente por esta funcionalidade -------------
    $stageFinal = (int)(Candidatura::find($candId)['stage_id'] ?? 0);
    $check($stageFinal === $stageAposAceite, '(etapa) A candidatura permanece na etapa de Admissão durante todo o fluxo do exame — nenhuma movimentação adicional automática');

    // ---- 14) permissões/CSRF — inspeção de código (mesmo padrão já usado para Carta Proposta) ------
    $r = new ReflectionClass(AdminExameAdmissionalController::class);
    foreach (['criar', 'editar', 'atualizar', 'prepararEnvio', 'confirmarEnvio'] as $metodo) {
        $rm = $r->getMethod($metodo);
        $arq = new SplFileObject($rm->getFileName());
        $arq->seek($rm->getStartLine() - 1);
        $corpo = '';
        while ($arq->key() < $rm->getEndLine()) { $corpo .= $arq->current(); $arq->next(); }
        $check(str_contains($corpo, "Auth::requireRole(['admin', 'rh'])"), "(acesso) AdminExameAdmissionalController::{$metodo}() exige admin/rh");
        if ($metodo !== 'editar') {
            $check(str_contains($corpo, 'Security::csrfCheck'), "(CSRF) AdminExameAdmissionalController::{$metodo}() verifica CSRF no POST");
        }
    }

    echo "\nEXAME_ADMISSIONAL_OK\n";
} finally {
    if (!empty($criados['arquivosPdf'])) {
        foreach ($criados['arquivosPdf'] as $arq) { @unlink($arq); }
    }
    if (!empty($criados['candidaturas'])) {
        $ph = implode(',', array_fill(0, count($criados['candidaturas']), '?'));
        $pdo->prepare("DELETE FROM exame_admissional_historico WHERE exame_admissional_id IN (SELECT id FROM exames_admissionais WHERE candidatura_id IN ($ph))")->execute($criados['candidaturas']);
        $pdo->prepare("DELETE FROM exames_admissionais WHERE candidatura_id IN ($ph)")->execute($criados['candidaturas']);
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
    if (!empty($criados['mensagens'])) {
        $ph = implode(',', array_fill(0, count($criados['mensagens']), '?'));
        $pdo->prepare("DELETE FROM mensagens WHERE id IN ($ph)")->execute($criados['mensagens']);
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
echo "\nEXAME_ADMISSIONAL_OK\n";
