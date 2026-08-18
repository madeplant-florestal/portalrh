<?php
require __DIR__ . '/../../app/core/bootstrap.php';

RecruitmentWebhookSchemaService::ensureSchema();
PipelineStage::ensureRecruitmentLifecycle();

$pdo = Database::conn();
$vagaId = null;
$candidaturaId = null;
$createdUserId = null;

try {
    $userRow = $pdo->query('SELECT id FROM usuarios ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if ($userRow) {
        $actorId = (int)$userRow['id'];
    } else {
        $createdUserId = User::create('Usuário Teste Validação Etapa', 'validacao.etapa.' . time() . '@teste.local', password_hash('12345678', PASSWORD_BCRYPT), 'admin');
        $actorId = $createdUserId;
    }

    $stagesBySlug = [];
    foreach (PipelineStage::all() as $stage) {
        $stagesBySlug[(string)($stage['slug'] ?? '')] = (int)$stage['id'];
    }
    foreach (['nova-inscricao', 'triagem-rh', 'entrevista-rh', 'entrevista-gestor', 'testes', 'aprovado', 'admissao', 'reprovado', 'banco-de-talentos'] as $slug) {
        if (($stagesBySlug[$slug] ?? 0) <= 0) {
            throw new RuntimeException("Etapa '$slug' não encontrada no catálogo.");
        }
    }

    $vagaId = Vaga::create([
        'titulo' => 'Vaga Validação Etapa',
        'descricao' => 'Teste de integração das regras de obrigatoriedade por etapa.',
        'requisitos' => 'PHP, MariaDB.',
        'area' => 'RH',
        'local' => 'Remoto',
        'ativo' => 1,
    ]);

    $candidaturaId = Candidatura::create([
        'vaga_id' => $vagaId,
        'nome' => 'Candidato Validação Etapa',
        'email' => 'candidato.validacao.' . time() . '@teste.local',
        'telefone' => '67999999999',
        'cpf' => substr((string)time(), -11),
        'cargo_pretendido' => 'Analista de RH',
        'experiencia' => 'Teste automatizado de validação de etapa.',
        'pdf_path' => 'teste-validacao.pdf',
        'status' => 'novo',
        'indicacao_colaborador' => 0,
    ]);

    // 0) Regressão do bug do Kanban (2026-07-13): candidatura recém-criada nasce já na etapa
    //    inicial (nunca com stage_id NULL) — o NULL fazia o Kanban exibir o candidato na coluna
    //    "Nova Inscrição" só na tela, enquanto o banco continuava sem etapa real, e a checagem de
    //    concorrência rejeitava a primeira movimentação com um falso "movido por outro usuário".
    $candidaturaRecemCriada = Candidatura::find($candidaturaId);
    if ((int)($candidaturaRecemCriada['stage_id'] ?? 0) !== $stagesBySlug['nova-inscricao']) {
        throw new RuntimeException('Candidatura recém-criada deveria nascer na etapa "Nova Inscrição", não com stage_id NULL/divergente.');
    }

    // Simula o fluxo real do Kanban: o front-end lê a etapa de origem da coluna onde o card está
    // e envia isso como expected_current_stage_id. Precisa funcionar já na primeira movimentação,
    // sem exigir nenhuma etapa "de aquecimento" antes.
    $candidaturaFluxoReal = Candidatura::create([
        'vaga_id' => $vagaId,
        'nome' => 'Candidato Fluxo Real Kanban',
        'email' => 'candidato.fluxo.real.' . time() . '@teste.local',
        'telefone' => '67999999999',
        'cpf' => substr((string)(time() + 2), -11),
        'cargo_pretendido' => 'Analista de RH',
        'experiencia' => 'Teste automatizado do fluxo real de primeira movimentação no Kanban.',
        'pdf_path' => 'teste-fluxo-real.pdf',
        'status' => 'novo',
        'indicacao_colaborador' => 0,
    ]);
    $primeiraMovimentacao = Candidatura::updateStage(
        $candidaturaFluxoReal,
        $stagesBySlug['triagem-rh'],
        $actorId,
        [],
        true,
        $stagesBySlug['nova-inscricao']
    );
    $pdo->prepare('DELETE FROM candidatura_historico WHERE candidatura_id = ?')->execute([$candidaturaFluxoReal]);
    $pdo->prepare('DELETE FROM pipeline_movements WHERE candidatura_id = ?')->execute([$candidaturaFluxoReal]);
    $pdo->prepare('DELETE FROM webhook_events WHERE payload_json LIKE ?')->execute(['%"id":' . (int)$candidaturaFluxoReal . ',%']);
    $pdo->prepare('DELETE FROM candidaturas WHERE id = ?')->execute([$candidaturaFluxoReal]);
    if (!($primeiraMovimentacao['ok'] ?? false)) {
        throw new RuntimeException('Primeira movimentação de uma candidatura recém-criada não deveria ser rejeitada como conflito de concorrência: ' . json_encode($primeiraMovimentacao));
    }

    $countAll = static function () use ($pdo, $candidaturaId): array {
        $stage = (int)($pdo->query('SELECT stage_id FROM candidaturas WHERE id = ' . (int)$candidaturaId)->fetchColumn() ?: 0);
        $historico = (int)$pdo->query('SELECT COUNT(*) FROM candidatura_historico WHERE candidatura_id = ' . (int)$candidaturaId)->fetchColumn();
        $movements = (int)$pdo->query('SELECT COUNT(*) FROM pipeline_movements WHERE candidatura_id = ' . (int)$candidaturaId)->fetchColumn();
        $events = (int)$pdo->query('SELECT COUNT(*) FROM webhook_events WHERE payload_json LIKE \'%"id":' . (int)$candidaturaId . ',%\'')->fetchColumn();
        return ['stage_id' => $stage, 'historico' => $historico, 'movements' => $movements, 'events' => $events];
    };

    // 1) Entrevista RH sem nenhum dado -> rejeitado, nada persistido (sem estado parcial).
    $before = $countAll();
    $result = Candidatura::updateStage($candidaturaId, $stagesBySlug['entrevista-rh'], $actorId, []);
    if (($result['ok'] ?? true) !== false || ($result['error'] ?? '') !== 'validation') {
        throw new RuntimeException('Entrevista RH sem dados deveria ser rejeitada com erro de validação.');
    }
    if (!in_array('interview_date', $result['missing_fields'] ?? [], true) || !in_array('interview_time', $result['missing_fields'] ?? [], true) || !in_array('interview_location_or_link', $result['missing_fields'] ?? [], true)) {
        throw new RuntimeException('Lista de campos faltantes incorreta para Entrevista RH vazia: ' . json_encode($result['missing_fields'] ?? []));
    }
    $after = $countAll();
    if ($after !== $before) {
        throw new RuntimeException('Rejeição por validação não deveria alterar nenhum estado (histórico/movimento/evento/etapa).');
    }

    // 2) Só data preenchida -> ainda rejeitado (falta horário e local/link).
    $result = Candidatura::updateStage($candidaturaId, $stagesBySlug['entrevista-rh'], $actorId, [
        'interview_date' => '20/07/2026',
    ]);
    if (($result['ok'] ?? true) !== false) {
        throw new RuntimeException('Entrevista RH só com data deveria ser rejeitada.');
    }
    if (in_array('interview_date', $result['missing_fields'] ?? [], true)) {
        throw new RuntimeException('interview_date não deveria constar como faltante quando foi preenchido.');
    }
    if (!in_array('interview_time', $result['missing_fields'] ?? [], true)) {
        throw new RuntimeException('interview_time deveria constar como faltante.');
    }

    // 3) Data + horário + local (sem link) -> aceito.
    $result = Candidatura::updateStage($candidaturaId, $stagesBySlug['entrevista-rh'], $actorId, [
        'interview_date' => '20/07/2026',
        'interview_time' => '10:00',
        'interview_location' => 'Sala 1',
    ]);
    if (!($result['ok'] ?? false)) {
        throw new RuntimeException('Entrevista RH com data+horário+local deveria ser aceita: ' . ($result['message'] ?? ''));
    }
    $afterOk = $countAll();
    if ($afterOk['stage_id'] !== $stagesBySlug['entrevista-rh'] || $afterOk['historico'] !== $before['historico'] + 1 || $afterOk['movements'] !== $before['movements'] + 1) {
        throw new RuntimeException('Movimentação válida deveria criar exatamente um histórico e um pipeline_movement.');
    }

    // 4) Reenviar a MESMA transição (mesma etapa de origem/destino) não deve duplicar nada (idempotência
    // do ponto de vista de negócio: já está na etapa, é um no-op).
    $noop = Candidatura::updateStage($candidaturaId, $stagesBySlug['entrevista-rh'], $actorId, [
        'interview_date' => '20/07/2026',
        'interview_time' => '10:00',
        'interview_location' => 'Sala 1',
    ]);
    if (!($noop['ok'] ?? false)) {
        throw new RuntimeException('Repetir a etapa atual deveria ser um no-op bem-sucedido, não uma falha.');
    }
    $afterNoop = $countAll();
    if ($afterNoop['historico'] !== $afterOk['historico'] || $afterNoop['movements'] !== $afterOk['movements']) {
        throw new RuntimeException('No-op (mesma etapa) não deveria criar novo histórico/movimento.');
    }

    // 5) Concorrência: expected_current_stage_id desatualizado deve ser rejeitado com conflito, sem
    //    sobrescrever a etapa real (simula duplo clique/duplo envio ou outro usuário movendo antes).
    $conflict = Candidatura::updateStage(
        $candidaturaId,
        $stagesBySlug['testes'],
        $actorId,
        ['test_name' => 'Teste X', 'deadline' => '25/07/2026'],
        true,
        $stagesBySlug['triagem-rh'] // etapa esperada errada: candidato já está em entrevista-rh
    );
    if (($conflict['ok'] ?? true) !== false || ($conflict['error'] ?? '') !== 'conflict') {
        throw new RuntimeException('expected_current_stage_id divergente deveria retornar conflito.');
    }
    $afterConflict = $countAll();
    if ($afterConflict['stage_id'] !== $stagesBySlug['entrevista-rh']) {
        throw new RuntimeException('Conflito de concorrência não deveria alterar a etapa real da candidatura.');
    }

    // 6) Testes: sem campos -> rejeitado; com campos -> aceito.
    $result = Candidatura::updateStage($candidaturaId, $stagesBySlug['testes'], $actorId, []);
    if (($result['ok'] ?? true) !== false || !in_array('test_name', $result['missing_fields'] ?? [], true) || !in_array('deadline', $result['missing_fields'] ?? [], true)) {
        throw new RuntimeException('Testes sem dados deveria ser rejeitado exigindo test_name e deadline.');
    }
    $result = Candidatura::updateStage($candidaturaId, $stagesBySlug['testes'], $actorId, [
        'test_name' => 'Teste Comportamental',
        'deadline' => '28/07/2026',
    ]);
    if (!($result['ok'] ?? false)) {
        throw new RuntimeException('Testes com dados válidos deveria ser aceito.');
    }

    // 7) Admissão: sem campos -> rejeitado; com campos -> aceito.
    $result = Candidatura::updateStage($candidaturaId, $stagesBySlug['admissao'], $actorId, []);
    if (($result['ok'] ?? true) !== false || !in_array('admission_date', $result['missing_fields'] ?? [], true) || !in_array('admission_notes', $result['missing_fields'] ?? [], true)) {
        throw new RuntimeException('Admissão sem dados deveria ser rejeitada exigindo admission_date e admission_notes.');
    }
    $result = Candidatura::updateStage($candidaturaId, $stagesBySlug['admissao'], $actorId, [
        'admission_date' => '01/08/2026',
        'admission_notes' => 'Enviar documentos até 30/07.',
    ]);
    if (!($result['ok'] ?? false)) {
        throw new RuntimeException('Admissão com dados válidos deveria ser aceita.');
    }

    // 8) Reprovado: sem observações/confirmação -> rejeitado; com ambos -> aceito, e o motivo nunca
    //    aparece no payload do webhook (dados_adicionais não tem chave de motivo).
    $result = Candidatura::updateStage($candidaturaId, $stagesBySlug['reprovado'], $actorId, []);
    if (($result['ok'] ?? true) !== false || !in_array('observacoes', $result['missing_fields'] ?? [], true) || !in_array('confirm', $result['missing_fields'] ?? [], true)) {
        throw new RuntimeException('Reprovado sem observações/confirmação deveria ser rejeitado.');
    }
    $result = Candidatura::updateStage($candidaturaId, $stagesBySlug['reprovado'], $actorId, [
        'observacoes' => 'Não atendeu aos requisitos técnicos da vaga.',
        'confirm' => '1',
    ]);
    if (!($result['ok'] ?? false)) {
        throw new RuntimeException('Reprovado com observações e confirmação deveria ser aceito.');
    }
    $lastEvent = $pdo->query('SELECT payload_json FROM webhook_events ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $lastPayload = json_decode((string)($lastEvent['payload_json'] ?? ''), true);
    $payloadAsString = (string)($lastEvent['payload_json'] ?? '');
    if (strpos($payloadAsString, 'requisitos técnicos') !== false) {
        throw new RuntimeException('O motivo interno da reprovação vazou para o payload do webhook.');
    }
    $historicoUltimo = $pdo->query('SELECT observacoes FROM candidatura_historico WHERE candidatura_id = ' . (int)$candidaturaId . ' ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (strpos((string)($historicoUltimo['observacoes'] ?? ''), 'requisitos técnicos') === false) {
        throw new RuntimeException('O motivo da reprovação deveria ficar registrado no histórico interno.');
    }

    // 9) Banco de Talentos: sem confirmação -> rejeitado; com confirmação -> aceito (observação opcional).
    $result = Candidatura::updateStage($candidaturaId, $stagesBySlug['banco-de-talentos'], $actorId, []);
    if (($result['ok'] ?? true) !== false || !in_array('confirm', $result['missing_fields'] ?? [], true)) {
        throw new RuntimeException('Banco de Talentos sem confirmação deveria ser rejeitado.');
    }
    $result = Candidatura::updateStage($candidaturaId, $stagesBySlug['banco-de-talentos'], $actorId, ['confirm' => '1']);
    if (!($result['ok'] ?? false)) {
        throw new RuntimeException('Banco de Talentos com confirmação deveria ser aceito.');
    }

    // 10) Etapas livres: movimentação direta sem metadado algum.
    $result = Candidatura::updateStage($candidaturaId, $stagesBySlug['triagem-rh'], $actorId, []);
    if (!($result['ok'] ?? false)) {
        throw new RuntimeException('Triagem RH não deveria exigir nenhum dado adicional.');
    }
    $result = Candidatura::updateStage($candidaturaId, $stagesBySlug['aprovado'], $actorId, []);
    if (!($result['ok'] ?? false)) {
        throw new RuntimeException('Aprovado não deveria exigir nenhum dado adicional.');
    }

    echo "PIPELINE_STAGE_VALIDATION_OK\n";
} finally {
    if ($candidaturaId) {
        $pdo->prepare('DELETE FROM candidatura_stage_metadata WHERE candidatura_id = ?')->execute([$candidaturaId]);
        $pdo->prepare('DELETE FROM webhook_events WHERE payload_json LIKE ?')->execute(['%"id":' . (int)$candidaturaId . ',%']);
        $pdo->prepare('DELETE FROM candidatura_historico WHERE candidatura_id = ?')->execute([$candidaturaId]);
        $pdo->prepare('DELETE FROM pipeline_movements WHERE candidatura_id = ?')->execute([$candidaturaId]);
        $pdo->prepare('DELETE FROM candidaturas WHERE id = ?')->execute([$candidaturaId]);
    }
    if ($vagaId) {
        $pdo->prepare('DELETE FROM vagas WHERE id = ?')->execute([$vagaId]);
    }
    if ($createdUserId) {
        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$createdUserId]);
    }
}
