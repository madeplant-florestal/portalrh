<?php
require __DIR__ . '/../../app/core/bootstrap.php';

$pdo = Database::conn();
$setorService = new SetorService();
$empresaRepository = new EmpresaRepository($pdo);
$cargoSetorService = new CargoSetorService();

$empresaAId = null;
$empresaBId = null;
$setorId = null;

try {
    $pdo->prepare("INSERT INTO empresas (nome, slug, ativo) VALUES (?, ?, 1)")
        ->execute(['EMPRESA A INTEGRACAO SETOR', 'empresa-a-integracao-setor']);
    $empresaAId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO empresas (nome, slug, ativo) VALUES (?, ?, 1)")
        ->execute(['EMPRESA B INTEGRACAO SETOR', 'empresa-b-integracao-setor']);
    $empresaBId = (int)$pdo->lastInsertId();

    $setorId = $setorService->create([
        'nome' => 'SETOR INTEGRACAO EMPRESA',
        'slug' => '',
        'ativo' => 1,
        'empresa_id' => $empresaAId,
    ]);

    $created = $setorService->find($setorId);
    if (!$created || (int)($created['empresa_id'] ?? 0) !== $empresaAId) {
        throw new RuntimeException('Falha ao cadastrar setor com empresa válida.');
    }

    $setorService->update($setorId, [
        'nome' => 'SETOR INTEGRACAO EMPRESA EDITADO',
        'slug' => '',
        'ativo' => 1,
        'empresa_id' => $empresaBId,
    ]);

    $updated = $setorService->find($setorId);
    if (!$updated || (int)($updated['empresa_id'] ?? 0) !== $empresaBId) {
        throw new RuntimeException('Falha ao editar empresa vinculada do setor.');
    }

    $byEmpresa = $setorService->listByEmpresa($empresaBId);
    $found = false;
    foreach ($byEmpresa as $row) {
        if ((int)($row['id'] ?? 0) === $setorId) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        throw new RuntimeException('Falha ao consultar setores por empresa.');
    }

    $paginated = $setorService->paginateAdmin(['empresa' => (string)$empresaBId, 'q' => '', 'status' => ''], 1, 10);
    if ((int)($paginated['total'] ?? 0) < 1) {
        throw new RuntimeException('Filtro por empresa não retornou o setor esperado.');
    }

    $exportRows = $setorService->exportDataset(['empresa' => (string)$empresaBId, 'q' => '', 'status' => '']);
    if (empty($exportRows) || ($exportRows[0]['empresa_nome'] ?? '') === '') {
        throw new RuntimeException('Dataset de exportação não trouxe o nome da empresa.');
    }

    if (CadastroOrganizacional::delete('empresas', $empresaBId) !== false) {
        throw new RuntimeException('Deveria impedir exclusão de empresa vinculada.');
    }

    echo "SETOR_EMPRESA_INTEGRATION_OK\n";
} finally {
    if ($setorId) {
        $pdo->prepare('DELETE FROM cargo_setores WHERE setor_id = ?')->execute([$setorId]);
        $pdo->prepare('DELETE FROM setores WHERE id = ?')->execute([$setorId]);
    }
    if ($empresaAId) {
        $pdo->prepare('DELETE FROM empresas WHERE id = ?')->execute([$empresaAId]);
    }
    if ($empresaBId) {
        $pdo->prepare('DELETE FROM empresas WHERE id = ?')->execute([$empresaBId]);
    }
}
