<?php
require __DIR__ . '/../../app/core/bootstrap.php';

$service = new CargoSetorService();
$repository = new CargoSetorRepository();
$pdo = Database::conn();
$createdLink = null;

try {
    $pair = $pdo->query(
        "SELECT c.id AS cargo_id, s.id AS setor_id
         FROM cargos c
         INNER JOIN setores s ON s.ativo = 1
         WHERE c.ativo = 1
           AND NOT EXISTS (
                SELECT 1
                FROM cargo_setores cs
                WHERE cs.cargo_id = c.id AND cs.setor_id = s.id
           )
         ORDER BY c.id ASC, s.id ASC
         LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);

    if (!$pair) {
        throw new RuntimeException('Nenhum par disponível para validar a integração de cargo_setores.');
    }

    $cargoId = (int)$pair['cargo_id'];
    $setorId = (int)$pair['setor_id'];

    $service->vincularCargoSetor($cargoId, $setorId);
    $createdLink = [$cargoId, $setorId];

    if (!$repository->relationshipExists($cargoId, $setorId)) {
        throw new RuntimeException('O vínculo não foi persistido após a operação de inclusão.');
    }

    $setores = $service->listarSetoresPorCargo($cargoId);
    $foundSetor = false;
    foreach ($setores as $setor) {
        if ((int)$setor['id'] === $setorId) {
            $foundSetor = true;
            break;
        }
    }
    if (!$foundSetor) {
        throw new RuntimeException('A listagem de setores por cargo não retornou o vínculo criado.');
    }

    $cargos = $service->listarCargosPorSetor($setorId);
    $foundCargo = false;
    foreach ($cargos as $cargo) {
        if ((int)$cargo['id'] === $cargoId) {
            $foundCargo = true;
            break;
        }
    }
    if (!$foundCargo) {
        throw new RuntimeException('A listagem de cargos por setor não retornou o vínculo criado.');
    }

    $duplicateRejected = false;
    try {
        $service->vincularCargoSetor($cargoId, $setorId);
    } catch (RuntimeException $e) {
        $duplicateRejected = true;
    }

    if (!$duplicateRejected) {
        throw new RuntimeException('A camada de serviço deveria rejeitar vínculos duplicados.');
    }

    if (!$service->desvincularCargoSetor($cargoId, $setorId)) {
        throw new RuntimeException('A exclusão do vínculo retornou falso inesperadamente.');
    }
    $createdLink = null;

    if ($repository->relationshipExists($cargoId, $setorId)) {
        throw new RuntimeException('O vínculo deveria ter sido removido ao final do teste.');
    }

    echo "CARGO_SETOR_FLOW_OK\n";
} finally {
    if ($createdLink) {
        $pdo->prepare('DELETE FROM cargo_setores WHERE cargo_id = ? AND setor_id = ?')->execute($createdLink);
    }
}
