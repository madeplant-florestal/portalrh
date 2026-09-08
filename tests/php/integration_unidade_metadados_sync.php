<?php
declare(strict_types=1);
if (@fsockopen('127.0.0.1', 3306, $errno, $errstr, 1) === false) {
    echo "SKIP integration_unidade_metadados_sync (MySQL indisponivel)\n";
    exit(0);
}
require_once __DIR__ . '/../../app/core/bootstrap.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$pdo = Database::conn();
$temTabela = (int)$pdo->query(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'unidades'"
)->fetchColumn();
if ($temTabela === 0) {
    echo "SKIP integration_unidade_metadados_sync (migration 2026-09-04-empresas-unidades-metadados.sql nao aplicada)\n";
    exit(0);
}

$sufixo = substr((string)time(), -6) . (string)random_int(10, 99);
$empA = 'ZTU1' . $sufixo;
$empB = 'ZTU2' . $sufixo;

$empresaRepo = new EmpresaMetadadosRepository($pdo);
$empresaService = new EmpresaMetadadosSyncService($empresaRepo);
$unidadeRepo = new UnidadeMetadadosRepository($pdo);
$service = new UnidadeMetadadosSyncService($unidadeRepo, $empresaRepo);

$limpar = static function () use ($pdo): void {
    $pdo->exec("DELETE FROM unidades WHERE codigo_empresa LIKE 'ZTU%'");
    $pdo->exec("DELETE FROM empresas WHERE codigo_empresa LIKE 'ZTU%'");
};
$limpar();

try {
    $empresaService->applyRows([
        ['codigo_empresa' => $empA, 'razao_social' => 'ZZ UNID EMPRESA A ' . $sufixo . ' LTDA'],
        ['codigo_empresa' => $empB, 'razao_social' => 'ZZ UNID EMPRESA B ' . $sufixo . ' LTDA'],
    ], 'RHMADEPLANT');
    $idEmpA = $empresaRepo->findIdByCodigo($empA);
    $idEmpB = $empresaRepo->findIdByCodigo($empB);
    $assert($idEmpA !== null && $idEmpB !== null, 'Setup: as duas empresas de teste deveriam existir.');

    // 1) Carga vinculada à empresa — empresa_id resolvido a partir de codigo_empresa.
    $r1 = $service->applyRows([
        ['codigo_empresa' => $empA, 'codigo_unidade' => '0001', 'descricao' => 'MATRIZ A'],
        ['codigo_empresa' => $empA, 'codigo_unidade' => '0002', 'descricao' => 'FILIAL A2'],
    ], 'RHMADEPLANT');
    $assert($r1['inserted'] === 2 && $r1['errors'] === 0, 'Caso 1: deveria inserir as 2 unidades.');
    $u1 = $unidadeRepo->findByCodigo($empA, '0001');
    $assert((int)$u1['empresa_id'] === (int)$idEmpA, 'Caso 1: empresa_id deveria ter sido resolvido pelo codigo_empresa.');
    $assert($u1['descricao'] === 'MATRIZ A' && $u1['origem_metadados'] === 'RHMADEPLANT', 'Caso 1: descricao/origem persistidas.');

    // 2) Mesmo codigo_unidade em empresa diferente NÃO colide (chave composta).
    $r2 = $service->applyRows([
        ['codigo_empresa' => $empB, 'codigo_unidade' => '0001', 'descricao' => 'MATRIZ B'],
    ], 'RHMADEPLANT');
    $assert($r2['inserted'] === 1 && $r2['errors'] === 0, 'Caso 2: codigo_unidade 0001 na empresa B deveria inserir, não colidir com o da empresa A.');
    $uB = $unidadeRepo->findByCodigo($empB, '0001');
    $assert((int)$uB['empresa_id'] === (int)$idEmpB && $uB['descricao'] === 'MATRIZ B', 'Caso 2: unidade da empresa B independente.');

    // 3) Reexecução idêntica -> unchanged (idempotente, não duplica).
    $r3 = $service->applyRows([
        ['codigo_empresa' => $empA, 'codigo_unidade' => '0001', 'descricao' => 'MATRIZ A'],
        ['codigo_empresa' => $empA, 'codigo_unidade' => '0002', 'descricao' => 'FILIAL A2'],
    ], 'RHMADEPLANT');
    $assert($r3['unchanged'] === 2 && $r3['inserted'] === 0, 'Caso 3: reexecutar não deveria inserir/atualizar nada.');
    $total = (int)$pdo->query("SELECT COUNT(*) FROM unidades WHERE codigo_empresa = " . $pdo->quote($empA) . " AND codigo_unidade = '0001'")->fetchColumn();
    $assert($total === 1, 'Caso 3: unidade não duplicada.');

    // 4) Descrição muda -> updated.
    $r4 = $service->applyRows([
        ['codigo_empresa' => $empA, 'codigo_unidade' => '0001', 'descricao' => 'MATRIZ A - RENOMEADA'],
    ], 'RHMADEPLANT');
    $assert($r4['updated'] === 1, 'Caso 4: mudança de descrição deveria atualizar.');
    $assert($unidadeRepo->findByCodigo($empA, '0001')['descricao'] === 'MATRIZ A - RENOMEADA', 'Caso 4: nova descrição persistida.');

    // 5) Empresa inexistente -> erro controlado de integridade, sem abortar o lote, sem persistir.
    $r5 = $service->applyRows([
        ['codigo_empresa' => 'ZTU_INEXISTENTE_' . $sufixo, 'codigo_unidade' => '0009', 'descricao' => 'ORFA'],
        ['codigo_empresa' => $empA, 'codigo_unidade' => '0003', 'descricao' => 'FILIAL A3'],
    ], 'RHMADEPLANT');
    $assert($r5['errors'] === 1, 'Caso 5: unidade com empresa ausente deveria ser 1 erro controlado.');
    $assert($r5['inserted'] === 1, 'Caso 5: o restante do lote deveria continuar processando.');
    $assert($unidadeRepo->findByCodigo('ZTU_INEXISTENTE_' . $sufixo, '0009') === null, 'Caso 5: unidade órfã não persiste.');
    $assert(str_contains($r5['error_details'][0]['erro'] ?? '', 'não sincronizada'), 'Caso 5: mensagem de erro deveria explicar a causa.');

    // 6) Linha inválida (descrição vazia) é contada como erro.
    $r6 = $service->applyRows([
        ['codigo_empresa' => $empA, 'codigo_unidade' => '0004', 'descricao' => ''],
    ], 'RHMADEPLANT');
    $assert($r6['errors'] === 1 && $unidadeRepo->findByCodigo($empA, '0004') === null, 'Caso 6: descrição vazia = erro, não persiste.');

    echo "OK integration_unidade_metadados_sync\n";
} finally {
    $limpar();
}
