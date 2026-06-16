<?php
require __DIR__ . '/../../app/core/bootstrap.php';

$pdo = Database::conn();
$empresaRepo = new EmpresaRepository($pdo);
$validator = new SetorValidator($empresaRepo);
$cleanupEmpresaId = null;
$cleanups = [];

try {
    $activeEmpresa = $pdo->query("SELECT id FROM empresas WHERE ativo = 1 ORDER BY id ASC LIMIT 1")->fetchColumn();
    if (!$activeEmpresa) {
        $pdo->prepare("INSERT INTO empresas (nome, slug, ativo) VALUES (?, ?, 1)")
            ->execute(['EMPRESA TESTE UNIT', 'empresa-teste-unit']);
        $activeEmpresa = (int)$pdo->lastInsertId();
        $cleanupEmpresaId = (int)$activeEmpresa;
    } else {
        $activeEmpresa = (int)$activeEmpresa;
    }

    $inactiveEmpresa = $pdo->query("SELECT id FROM empresas WHERE ativo = 0 ORDER BY id ASC LIMIT 1")->fetchColumn();
    if (!$inactiveEmpresa) {
        $pdo->prepare("INSERT INTO empresas (nome, slug, ativo) VALUES (?, ?, 0)")
            ->execute(['EMPRESA INATIVA TESTE UNIT', 'empresa-inativa-teste-unit']);
        $inactiveEmpresa = (int)$pdo->lastInsertId();
        $cleanups[] = (int)$inactiveEmpresa;
    } else {
        $inactiveEmpresa = (int)$inactiveEmpresa;
    }

    $valid = $validator->validate(new SetorRequest([
        'nome' => 'SETOR VALIDO TESTE',
        'slug' => '',
        'ativo' => 1,
        'empresa_id' => $activeEmpresa,
    ]));
    if ($valid->empresaId !== $activeEmpresa) {
        throw new RuntimeException('Falha ao validar setor com empresa válida.');
    }

    $missingRejected = false;
    try {
        $validator->validate(new SetorRequest([
            'nome' => 'SETOR SEM EMPRESA',
            'slug' => '',
            'ativo' => 1,
            'empresa_id' => '',
        ]));
    } catch (InvalidArgumentException $e) {
        $missingRejected = true;
    }
    if (!$missingRejected) {
        throw new RuntimeException('O validator deveria rejeitar setor sem empresa.');
    }

    $notFoundRejected = false;
    try {
        $validator->validate(new SetorRequest([
            'nome' => 'SETOR EMPRESA INEXISTENTE',
            'slug' => '',
            'ativo' => 1,
            'empresa_id' => 99999999,
        ]));
    } catch (InvalidArgumentException $e) {
        $notFoundRejected = true;
    }
    if (!$notFoundRejected) {
        throw new RuntimeException('O validator deveria rejeitar empresa inexistente.');
    }

    $inactiveRejected = false;
    try {
        $validator->validate(new SetorRequest([
            'nome' => 'SETOR EMPRESA INATIVA',
            'slug' => '',
            'ativo' => 1,
            'empresa_id' => $inactiveEmpresa,
        ]));
    } catch (InvalidArgumentException $e) {
        $inactiveRejected = true;
    }
    if (!$inactiveRejected) {
        throw new RuntimeException('O validator deveria rejeitar empresa inativa.');
    }

    echo "SETOR_EMPRESA_UNIT_OK\n";
} finally {
    foreach ($cleanups as $empresaId) {
        $pdo->prepare('DELETE FROM empresas WHERE id = ?')->execute([(int)$empresaId]);
    }
    if ($cleanupEmpresaId) {
        $pdo->prepare('DELETE FROM empresas WHERE id = ?')->execute([(int)$cleanupEmpresaId]);
    }
}
