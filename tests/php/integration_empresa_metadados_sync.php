<?php
declare(strict_types=1);
if (@fsockopen('127.0.0.1', 3306, $errno, $errstr, 1) === false) {
    echo "SKIP integration_empresa_metadados_sync (MySQL indisponivel)\n";
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
$temColunas = (int)$pdo->query(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresas' AND COLUMN_NAME = 'codigo_empresa'"
)->fetchColumn();
if ($temColunas === 0) {
    echo "SKIP integration_empresa_metadados_sync (migration 2026-09-04-empresas-unidades-metadados.sql nao aplicada)\n";
    exit(0);
}

$sufixo = (string)time() . (string)random_int(100, 999);
$cod1 = 'ZT1' . substr($sufixo, -6);   // <= 20 chars
$cod2 = 'ZT2' . substr($sufixo, -6);
$cod3 = 'ZT3' . substr($sufixo, -6);
$nomeAdocao = 'ZZ EMPRESA ADOCAO ' . substr($sufixo, -6) . ' LTDA';

$repo = new EmpresaMetadadosRepository($pdo);
$service = new EmpresaMetadadosSyncService($repo);

$limpar = static function () use ($pdo): void {
    $pdo->exec("DELETE FROM unidades WHERE codigo_empresa LIKE 'ZT%'");
    $pdo->exec("DELETE FROM empresas WHERE codigo_empresa LIKE 'ZT%' OR nome LIKE 'ZZ %' OR slug LIKE 'zz-%'");
};
$limpar();

try {
    // 1) Primeira carga: código novo, sem empresa local de mesmo nome -> inserido.
    $r1 = $service->applyRows([
        ['codigo_empresa' => $cod1, 'razao_social' => 'ZZ FOO INDUSTRIA ' . substr($sufixo, -6) . ' LTDA'],
    ], 'RHMADEPLANT');
    $assert($r1['inserted'] === 1 && $r1['adopted'] === 0 && $r1['errors'] === 0, 'Caso 1: deveria inserir 1 empresa nova.');
    $e1 = $repo->findByCodigo($cod1);
    $assert($e1 !== null, 'Caso 1: empresa deveria existir.');
    $assert($e1['razao_social'] === 'ZZ FOO INDUSTRIA ' . substr($sufixo, -6) . ' LTDA', 'Caso 1: razao_social persistida.');
    $assert((int)$e1['ativo'] === 1, 'Caso 1: empresa nova entra ativa.');
    $assert($e1['origem_metadados'] === 'RHMADEPLANT', 'Caso 1: origem persistida.');
    $assert($e1['nome'] !== '' && $e1['slug'] !== '', 'Caso 1: nome/slug legados preenchidos.');
    $nomeOriginal = $e1['nome'];
    $slugOriginal = $e1['slug'];

    // 2) Reexecução idêntica -> unchanged (idempotente, não duplica).
    $r2 = $service->applyRows([
        ['codigo_empresa' => $cod1, 'razao_social' => 'ZZ FOO INDUSTRIA ' . substr($sufixo, -6) . ' LTDA'],
    ], 'RHMADEPLANT');
    $assert($r2['unchanged'] === 1 && $r2['inserted'] === 0, 'Caso 2: reexecutar não deveria inserir de novo.');
    $totalCod1 = (int)$pdo->query("SELECT COUNT(*) FROM empresas WHERE codigo_empresa = " . $pdo->quote($cod1))->fetchColumn();
    $assert($totalCod1 === 1, 'Caso 2: a empresa não deveria ter sido duplicada.');

    // 3) Razão social muda na origem -> updated; nome/slug legados NÃO mudam.
    $r3 = $service->applyRows([
        ['codigo_empresa' => $cod1, 'razao_social' => 'ZZ FOO HOLDING ' . substr($sufixo, -6) . ' SA'],
    ], 'RHMADEPLANT');
    $assert($r3['updated'] === 1, 'Caso 3: mudança de razão social deveria atualizar.');
    $e1b = $repo->findByCodigo($cod1);
    $assert($e1b['razao_social'] === 'ZZ FOO HOLDING ' . substr($sufixo, -6) . ' SA', 'Caso 3: nova razão social persistida.');
    $assert($e1b['nome'] === $nomeOriginal && $e1b['slug'] === $slugOriginal, 'Caso 3: nome/slug legados intocados.');
    $assert((int)$e1b['id'] === (int)$e1['id'], 'Caso 3: continua sendo a mesma linha (mesmo id).');

    // 4) Adoção única: empresa local pré-existente sem código, com nome que bate exatamente.
    $pdo->prepare('INSERT INTO empresas (nome, slug, ativo) VALUES (?, ?, 1)')
        ->execute([$nomeAdocao, 'zz-empresa-adocao-' . substr($sufixo, -6)]);
    $idLocal = (int)$pdo->lastInsertId();
    $r4 = $service->applyRows([
        ['codigo_empresa' => $cod2, 'razao_social' => $nomeAdocao],
    ], 'RHMADEPLANT');
    $assert($r4['adopted'] === 1 && $r4['inserted'] === 0, 'Caso 4: deveria adotar a empresa local existente, não inserir.');
    $adotada = $repo->findByCodigo($cod2);
    $assert((int)$adotada['id'] === $idLocal, 'Caso 4: adoção deve manter o MESMO id (preserva FKs legadas).');
    $assert($adotada['razao_social'] === $nomeAdocao, 'Caso 4: razao_social carimbada na adoção.');
    $assert($adotada['nome'] === $nomeAdocao, 'Caso 4: nome legado não é alterado pela adoção.');

    // 4b) "Código é a identidade": muda a razão social, mesmo código -> atualiza a linha adotada,
    //     sem depender mais do nome.
    $r4b = $service->applyRows([
        ['codigo_empresa' => $cod2, 'razao_social' => 'ZZ RENOMEADA ' . substr($sufixo, -6) . ' LTDA'],
    ], 'RHMADEPLANT');
    $assert($r4b['updated'] === 1, 'Caso 4b: deveria atualizar pela identidade do código.');
    $adotada2 = $repo->findByCodigo($cod2);
    $assert((int)$adotada2['id'] === $idLocal && $adotada2['razao_social'] === 'ZZ RENOMEADA ' . substr($sufixo, -6) . ' LTDA', 'Caso 4b: mesma linha, nova razão social.');

    // 5) Adoção ambígua: duas empresas locais que normalizam para o mesmo nome -> NÃO adota,
    //    insere como nova e reporta em avisos.
    $baseAmbiguo = 'ZZ AMBIGUO ' . substr($sufixo, -6) . ' LTDA';
    $pdo->prepare('INSERT INTO empresas (nome, slug, ativo) VALUES (?, ?, 1)')->execute([$baseAmbiguo, 'zz-ambiguo-a-' . substr($sufixo, -6)]);
    $pdo->prepare('INSERT INTO empresas (nome, slug, ativo) VALUES (?, ?, 1)')->execute(['ZZ AMBÍGUO ' . substr($sufixo, -6) . ' LTDA.', 'zz-ambiguo-b-' . substr($sufixo, -6)]);
    $r5 = $service->applyRows([
        ['codigo_empresa' => $cod3, 'razao_social' => $baseAmbiguo],
    ], 'RHMADEPLANT');
    $assert($r5['adopted'] === 0 && $r5['inserted'] === 1, 'Caso 5: ambiguidade de nome não deve adotar — insere como nova.');
    $assert(count(array_filter($r5['avisos'], static fn ($a) => str_contains($a, 'ambígua'))) >= 1, 'Caso 5: deveria registrar aviso de adoção ambígua.');

    // 6) Linha inválida (razão social vazia) é contada como erro e não aborta o lote.
    $r6 = $service->applyRows([
        ['codigo_empresa' => $cod1 . 'X', 'razao_social' => ''],
        ['codigo_empresa' => $cod1, 'razao_social' => 'ZZ FOO HOLDING ' . substr($sufixo, -6) . ' SA'],
    ], 'RHMADEPLANT');
    $assert($r6['errors'] === 1 && $r6['unchanged'] === 1, 'Caso 6: linha sem razão social = erro; o resto do lote segue.');
    $assert($repo->findByCodigo($cod1 . 'X') === null, 'Caso 6: linha inválida não persiste.');

    echo "OK integration_empresa_metadados_sync\n";
} finally {
    $limpar();
}
