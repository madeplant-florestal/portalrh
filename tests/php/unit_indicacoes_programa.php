<?php
declare(strict_types=1);
set_time_limit(15);
if (@fsockopen('127.0.0.1', 3306, $errno, $errstr, 1) === false) {
    echo "SKIP unit_indicacoes_programa (MySQL indisponível)\n";
    exit(0);
}
require_once __DIR__ . '/../../app/core/bootstrap.php';

$pdo = Database::conn();
$suffix = (string)time();
$cpfTest = str_pad((string)((int)$suffix % 100000000000), 11, '0', STR_PAD_LEFT);
$vagaId = Vaga::create([
    'titulo' => 'Vaga Indicacao ' . $suffix,
    'descricao' => 'Teste',
    'requisitos' => 'Teste',
    'area' => 'RH',
    'local' => 'Remoto',
    'ativo' => 1
]);

$stageStmt = $pdo->prepare("SELECT id, nome FROM pipeline_stages ORDER BY ordem ASC");
$stageStmt->execute();
$contratadoId = 0;
foreach ($stageStmt->fetchAll(PDO::FETCH_ASSOC) as $stageRow) {
    $normalized = PipelineStage::normalizeName((string)($stageRow['nome'] ?? ''));
    if (in_array($normalized, ['contratado', 'admissao'], true)) {
        $contratadoId = (int)($stageRow['id'] ?? 0);
        break;
    }
}
if ($contratadoId <= 0) {
    $ordem = (int)$pdo->query('SELECT COALESCE(MAX(ordem),0)+1 FROM pipeline_stages')->fetchColumn();
    $ins = $pdo->prepare('INSERT INTO pipeline_stages (nome, ordem, cor) VALUES (?,?,?)');
    $ins->execute(['Admissão', $ordem, '#059669']);
    $contratadoId = (int)$pdo->lastInsertId();
}

$candId = Candidatura::create([
    'vaga_id' => $vagaId,
    'nome' => 'Candidato Indicacao ' . $suffix,
    'email' => "indicacao_{$suffix}@rhmadeplant.local",
    'telefone' => '67999999999',
    'cpf' => $cpfTest,
    'cargo_pretendido' => 'Analista',
    'experiencia' => 'Experiência teste',
    'pdf_path' => 'curriculo-test.pdf',
    'status' => 'novo',
    'indicacao_colaborador' => 1,
    'indicacao_colaborador_nome' => 'Colaborador Teste ' . $suffix
]);

$updated = Candidatura::updateStage($candId, $contratadoId, 1);
if (!$updated) {
    fwrite(STDERR, "Falha: não conseguiu mover para a etapa de admissão.\n");
    exit(1);
}
$pdo->prepare('UPDATE candidaturas SET indicacao_data_contratacao = DATE_SUB(NOW(), INTERVAL 10 DAY), indicacao_data_fim_experiencia = DATE_ADD(CURDATE(), INTERVAL 80 DAY) WHERE id = ?')->execute([$candId]);

$cand = Candidatura::find($candId);
if ((int)($cand['indicacao_colaborador'] ?? 0) !== 1) {
    fwrite(STDERR, "Falha: indicação não foi persistida.\n");
    exit(1);
}
if (trim((string)($cand['indicacao_colaborador_nome'] ?? '')) === '') {
    fwrite(STDERR, "Falha: nome do colaborador não foi persistido.\n");
    exit(1);
}
if (empty($cand['indicacao_data_contratacao']) || empty($cand['indicacao_data_fim_experiencia'])) {
    fwrite(STDERR, "Falha: datas de contratação/experiência não foram registradas.\n");
    exit(1);
}
$contratacaoBase = !empty($cand['indicacao_data_contratacao']) ? date('Y-m-d', strtotime((string)$cand['indicacao_data_contratacao'])) : date('Y-m-d');
$expectedFim = date('Y-m-d', strtotime($contratacaoBase . ' +90 days'));
if ($cand['indicacao_data_fim_experiencia'] !== $expectedFim) {
    fwrite(STDERR, "Falha: data fim experiência divergente.\n");
    exit(1);
}

$list = Candidatura::paginateIndicacoes(['q' => $suffix], 1, 10);
if ((int)($list['total'] ?? 0) < 1) {
    fwrite(STDERR, "Falha: página de indicações não listou candidato indicado.\n");
    exit(1);
}
$reportRows = Candidatura::reportIndicacoes(['q' => $suffix, 'pagamento' => 'pendente', 'indicador' => 'Colaborador']);
if (count($reportRows) < 1) {
    fwrite(STDERR, "Falha: dataset de relatório não retornou dados filtrados.\n");
    exit(1);
}
$totals = Candidatura::reportTotals($reportRows);
if ((int)($totals['total'] ?? 0) < 1) {
    fwrite(STDERR, "Falha: totais de relatório inválidos.\n");
    exit(1);
}

$pagoRes = Candidatura::markIndicacaoPagamento($candId, date('d/m/Y'), 1, 'PIX');
if (!($pagoRes['ok'] ?? false)) {
    fwrite(STDERR, "Falha: pagamento da indicação não foi aceito.\n");
    exit(1);
}
$candPago = Candidatura::find($candId);
if ((int)($candPago['indicacao_pagamento_realizado'] ?? 0) !== 1 || empty($candPago['indicacao_data_pagamento'])) {
    fwrite(STDERR, "Falha: pagamento da indicação não foi marcado corretamente.\n");
    exit(1);
}
if (trim((string)($candPago['indicacao_metodo_pagamento'] ?? '')) !== 'PIX') {
    fwrite(STDERR, "Falha: método de pagamento não foi persistido.\n");
    exit(1);
}

Candidatura::updateIndicacaoColaborador($candId, true, 'Colaborador Edit');
$editRes = Candidatura::updateIndicacaoPaymentDate($candId, date('d/m/Y', strtotime('-1 day')), 'Ajuste financeiro', 1);
if (!($editRes['ok'] ?? false)) {
    fwrite(STDERR, "Falha: edição da data de pagamento não foi aceita.\n");
    exit(1);
}
$candEdit = Candidatura::find($candId);
if (($candEdit['indicacao_data_pagamento'] ?? '') !== date('Y-m-d', strtotime('-1 day'))) {
    fwrite(STDERR, "Falha: data de pagamento editada não foi persistida.\n");
    exit(1);
}
$auditStmt = $pdo->prepare('SELECT COUNT(*) FROM indicacao_pagamento_auditoria WHERE candidatura_id = ?');
$auditStmt->execute([$candId]);
if ((int)$auditStmt->fetchColumn() < 1) {
    fwrite(STDERR, "Falha: auditoria de edição de pagamento não foi registrada.\n");
    exit(1);
}

Candidatura::updateIndicacaoColaborador($candId, true, '');
$candSemNome = Candidatura::find($candId);
if ((int)($candSemNome['indicacao_colaborador'] ?? 0) !== 1 || trim((string)($candSemNome['indicacao_colaborador_nome'] ?? '')) === '') {
    fwrite(STDERR, "Falha: indicação deveria manter nome válido quando enviado vazio.\n");
    exit(1);
}

Candidatura::updateIndicacaoColaborador($candId, true, 'Colaborador Data');
$future = Candidatura::markIndicacaoPagamento($candId, date('d/m/Y', strtotime('+1 day')));
if (($future['ok'] ?? true) !== false) {
    fwrite(STDERR, "Falha: data futura não deveria ser aceita.\n");
    exit(1);
}

Candidatura::updateIndicacaoColaborador($candId, true, 'Colaborador Data');
$old = Candidatura::markIndicacaoPagamento($candId, date('d/m/Y', strtotime('-91 days')));
if (($old['ok'] ?? true) !== false) {
    fwrite(STDERR, "Falha: data acima de 90 dias não deveria ser aceita.\n");
    exit(1);
}

$signalBlue = Candidatura::paymentSignal(['indicacao_pagamento_realizado' => 1, 'indicacao_data_fim_experiencia' => date('Y-m-d')]);
if (($signalBlue['color'] ?? '') !== 'ctgreen') {
    fwrite(STDERR, "Falha: sinalização ctgreen para pago inválida.\n");
    exit(1);
}
$signalGreenExp = Candidatura::paymentSignal(['indicacao_pagamento_realizado' => 0, 'indicacao_data_fim_experiencia' => date('Y-m-d', strtotime('+10 days'))]);
if (($signalGreenExp['color'] ?? '') !== 'ctlight') {
    fwrite(STDERR, "Falha: sinalização ctlight durante experiência inválida.\n");
    exit(1);
}
$signalRed = Candidatura::paymentSignal(['indicacao_pagamento_realizado' => 0, 'indicacao_data_fim_experiencia' => date('Y-m-d', strtotime('-2 days'))]);
if (($signalRed['color'] ?? '') !== 'ctdark') {
    fwrite(STDERR, "Falha: sinalização ctdark inválida.\n");
    exit(1);
}
$signalYellow = Candidatura::paymentSignal(['indicacao_pagamento_realizado' => 0, 'indicacao_data_fim_experiencia' => date('Y-m-d', strtotime('-6 days'))]);
if (($signalYellow['color'] ?? '') !== 'ctdark') {
    fwrite(STDERR, "Falha: sinalização ctdark inválida.\n");
    exit(1);
}
$signalGreenLate = Candidatura::paymentSignal(['indicacao_pagamento_realizado' => 0, 'indicacao_data_fim_experiencia' => date('Y-m-d', strtotime('-12 days'))]);
if (($signalGreenLate['color'] ?? '') !== 'ctdark') {
    fwrite(STDERR, "Falha: sinalização ctdark pós-limite inválida.\n");
    exit(1);
}

Candidatura::updateIndicacaoColaborador($candId, false);
$candOff = Candidatura::find($candId);
if ((int)($candOff['indicacao_colaborador'] ?? 1) !== 0) {
    fwrite(STDERR, "Falha: desmarcar indicação não funcionou.\n");
    exit(1);
}
if (!empty($candOff['indicacao_data_pagamento'])) {
    fwrite(STDERR, "Falha: data de pagamento deveria estar nula quando não indicado.\n");
    exit(1);
}

$pdo->prepare('DELETE FROM candidatura_historico WHERE candidatura_id = ?')->execute([$candId]);
$pdo->prepare('DELETE FROM indicacao_pagamento_auditoria WHERE candidatura_id = ?')->execute([$candId]);
$pdo->prepare('DELETE FROM candidaturas WHERE id = ?')->execute([$candId]);
$pdo->prepare('DELETE FROM vagas WHERE id = ?')->execute([$vagaId]);

echo "OK unit_indicacoes_programa\n";
