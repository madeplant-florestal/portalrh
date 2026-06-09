<?php
declare(strict_types=1);
set_time_limit(20);
if (@fsockopen('127.0.0.1', 3306, $errno, $errstr, 1) === false) {
    echo "SKIP unit_indicacoes_exports (MySQL indisponível)\n";
    exit(0);
}
require_once __DIR__ . '/../../app/core/bootstrap.php';

$_SESSION['user'] = true;
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'admin';
$_SESSION['user_name'] = 'Administrador Teste';
$_SESSION['user_is_supervisor'] = 1;

$pdo = Database::conn();
$suffix = (string)time();
$cpf = str_pad((string)((int)$suffix % 100000000000), 11, '0', STR_PAD_LEFT);

$vagaId = Vaga::create([
    'titulo' => 'Vaga Export ' . $suffix,
    'descricao' => 'Teste export',
    'requisitos' => 'Teste',
    'area' => 'RH',
    'local' => 'Remoto',
    'ativo' => 1
]);

$candId = Candidatura::create([
    'vaga_id' => $vagaId,
    'nome' => 'Cand Export ' . $suffix,
    'email' => "exp_{$suffix}@rhmadeplant.local",
    'telefone' => '67999999999',
    'cpf' => $cpf,
    'cargo_pretendido' => 'Analista',
    'experiencia' => 'Teste',
    'pdf_path' => 'x.pdf',
    'status' => 'novo',
    'indicacao_colaborador' => 1,
    'indicacao_colaborador_nome' => 'Indicador Export'
]);

$candId2 = Candidatura::create([
    'vaga_id' => $vagaId,
    'nome' => 'Cand Export 2 ' . $suffix,
    'email' => "exp2_{$suffix}@rhmadeplant.local",
    'telefone' => '67988887777',
    'cpf' => str_pad((string)(((int)$suffix + 7) % 100000000000), 11, '0', STR_PAD_LEFT),
    'cargo_pretendido' => 'Coordenador',
    'experiencia' => 'Teste',
    'pdf_path' => 'x2.pdf',
    'status' => 'novo',
    'indicacao_colaborador' => 1,
    'indicacao_colaborador_nome' => 'Indicador Export'
]);

$ok = Candidatura::markIndicacaoPagamento($candId, date('d/m/Y'), 1, 'PIX');
if (!($ok['ok'] ?? false)) {
    fwrite(STDERR, "Falha: não marcou pagamento para cenário de exportação.\n");
    exit(1);
}
$pdo->prepare('UPDATE candidaturas SET indicacao_pagamento_status = ? WHERE id = ?')->execute(['pendente', $candId2]);

$filtros = ['q' => $suffix, 'pagamento' => 'pago', 'indicador' => 'Indicador Export'];
$rowsUi = Candidatura::paginateIndicacoes($filtros, 1, 100);
$rowsExport = Candidatura::reportIndicacoes($filtros);
if ((int)($rowsUi['total'] ?? 0) !== count($rowsExport)) {
    fwrite(STDERR, "Falha: inconsistência entre filtros da interface e exportação.\n");
    exit(1);
}
$uiIds = array_map(static fn($r) => (int)$r['id'], (array)($rowsUi['items'] ?? []));
$exIds = array_map(static fn($r) => (int)$r['id'], $rowsExport);
sort($uiIds);
sort($exIds);
if ($uiIds !== $exIds) {
    fwrite(STDERR, "Falha: IDs filtrados da interface divergem da exportação.\n");
    exit(1);
}

$controller = new AdminIndicacoesController();

$_GET = ['format' => 'excel', 'q' => $suffix, 'pagamento' => 'pago', 'indicador' => 'Indicador Export'];
ob_start();
$controller->export();
$xlsOut = ob_get_clean();
if (strpos((string)$xlsOut, '<Workbook') === false) {
    fwrite(STDERR, "Falha: exportação Excel inválida.\n");
    exit(1);
}
if (strpos((string)$xlsOut, '>pago<') === false) {
    fwrite(STDERR, "Falha: status no Excel não refletiu data de pagamento válida.\n");
    exit(1);
}
if (strpos((string)$xlsOut, 'Cand Export') === false) {
    fwrite(STDERR, "Falha: Excel não contém dados esperados.\n");
    exit(1);
}
if (strpos((string)$xlsOut, 'Cand Export 2') !== false) {
    fwrite(STDERR, "Falha: Excel incluiu registro fora dos filtros aplicados.\n");
    exit(1);
}

$pdo->prepare('DELETE FROM indicacao_pagamento_auditoria WHERE candidatura_id = ?')->execute([$candId]);
$pdo->prepare('DELETE FROM indicacao_pagamento_auditoria WHERE candidatura_id = ?')->execute([$candId2]);
$pdo->prepare('DELETE FROM candidatura_historico WHERE candidatura_id = ?')->execute([$candId]);
$pdo->prepare('DELETE FROM candidatura_historico WHERE candidatura_id = ?')->execute([$candId2]);
$pdo->prepare('DELETE FROM candidaturas WHERE id = ?')->execute([$candId]);
$pdo->prepare('DELETE FROM candidaturas WHERE id = ?')->execute([$candId2]);
$pdo->prepare('DELETE FROM vagas WHERE id = ?')->execute([$vagaId]);

echo "OK unit_indicacoes_exports\n";
