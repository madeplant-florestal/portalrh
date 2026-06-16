<?php
class CargoSetorService
{
    private CargoSetorRepository $repository;

    public function __construct(?CargoSetorRepository $repository = null)
    {
        $this->repository = $repository ?? new CargoSetorRepository();
    }

    public function vincularCargoSetor(int $cargoId, int $setorId): void
    {
        $pdo = $this->repository->connection();
        $pdo->beginTransaction();

        try {
            $this->vincularCargoSetorInterno($cargoId, $setorId);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function desvincularCargoSetor(int $cargoId, int $setorId): bool
    {
        $this->assertPositiveId($cargoId, 'Cargo inválido para o vínculo.');
        $this->assertPositiveId($setorId, 'Setor inválido para o vínculo.');

        if (!$this->repository->cargoExists($cargoId)) {
            throw new InvalidArgumentException('Cargo não encontrado para o vínculo informado.');
        }
        if (!$this->repository->setorExists($setorId)) {
            throw new InvalidArgumentException('Setor não encontrado para o vínculo informado.');
        }
        if (!$this->repository->relationshipExists($cargoId, $setorId)) {
            throw new RuntimeException('O relacionamento informado não existe.');
        }

        $pdo = $this->repository->connection();
        $pdo->beginTransaction();

        try {
            $removed = $this->repository->deleteLink($cargoId, $setorId);
            $pdo->commit();

            return $removed;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function listarSetoresPorCargo(int $cargoId): array
    {
        $this->assertPositiveId($cargoId, 'Cargo inválido para consulta.');
        if (!$this->repository->cargoExists($cargoId)) {
            throw new InvalidArgumentException('Cargo não encontrado.');
        }

        return $this->repository->listarSetoresPorCargo($cargoId);
    }

    public function listarCargosPorSetor(int $setorId): array
    {
        $this->assertPositiveId($setorId, 'Setor inválido para consulta.');
        if (!$this->repository->setorExists($setorId)) {
            throw new InvalidArgumentException('Setor não encontrado.');
        }

        return $this->repository->listarCargosPorSetor($setorId);
    }

    public function vincularSetoresAoCargo(int $cargoId, array $setorIds): int
    {
        $this->assertPositiveId($cargoId, 'Cargo inválido para vinculação.');
        if (!$this->repository->cargoExists($cargoId)) {
            throw new InvalidArgumentException('Cargo não encontrado para vinculação.');
        }

        $setorIds = $this->normalizeIds($setorIds);
        if ($setorIds === []) {
            throw new InvalidArgumentException('Selecione ao menos um setor para vincular.');
        }

        $pdo = $this->repository->connection();
        $pdo->beginTransaction();

        try {
            $created = 0;
            foreach ($setorIds as $setorId) {
                if ($this->repository->relationshipExists($cargoId, $setorId)) {
                    continue;
                }
                $this->vincularCargoSetorInterno($cargoId, $setorId);
                $created++;
            }

            if ($created === 0) {
                throw new RuntimeException('Todos os setores selecionados já estavam vinculados a este cargo.');
            }

            $pdo->commit();
            return $created;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function vincularCargosAoSetor(int $setorId, array $cargoIds): int
    {
        $this->assertPositiveId($setorId, 'Setor inválido para vinculação.');
        if (!$this->repository->setorExists($setorId)) {
            throw new InvalidArgumentException('Setor não encontrado para vinculação.');
        }

        $cargoIds = $this->normalizeIds($cargoIds);
        if ($cargoIds === []) {
            throw new InvalidArgumentException('Selecione ao menos um cargo para vincular.');
        }

        $pdo = $this->repository->connection();
        $pdo->beginTransaction();

        try {
            $created = 0;
            foreach ($cargoIds as $cargoId) {
                if ($this->repository->relationshipExists($cargoId, $setorId)) {
                    continue;
                }
                $this->vincularCargoSetorInterno($cargoId, $setorId);
                $created++;
            }

            if ($created === 0) {
                throw new RuntimeException('Todos os cargos selecionados já estavam vinculados a este setor.');
            }

            $pdo->commit();
            return $created;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function dadosTelaCargo(int $cargoId): array
    {
        $this->assertPositiveId($cargoId, 'Cargo inválido.');

        $cargo = $this->repository->buscarCargo($cargoId);
        if (!$cargo) {
            throw new InvalidArgumentException('Cargo não encontrado.');
        }

        return [
            'cargo' => $cargo,
            'linkedSetores' => $this->repository->listarSetoresPorCargo($cargoId),
            'availableSetores' => $this->repository->listarSetoresDisponiveisParaCargo($cargoId),
        ];
    }

    public function dadosTelaSetor(int $setorId): array
    {
        $this->assertPositiveId($setorId, 'Setor inválido.');

        $setor = $this->repository->buscarSetor($setorId);
        if (!$setor) {
            throw new InvalidArgumentException('Setor não encontrado.');
        }

        return [
            'setor' => $setor,
            'linkedCargos' => $this->repository->listarCargosPorSetor($setorId),
            'availableCargos' => $this->repository->listarCargosDisponiveisParaSetor($setorId),
        ];
    }

    private function vincularCargoSetorInterno(int $cargoId, int $setorId): void
    {
        $this->assertPositiveId($cargoId, 'Cargo inválido para o vínculo.');
        $this->assertPositiveId($setorId, 'Setor inválido para o vínculo.');

        if (!$this->repository->cargoExists($cargoId)) {
            throw new InvalidArgumentException('Cargo não encontrado para o vínculo informado.');
        }
        if (!$this->repository->setorExists($setorId)) {
            throw new InvalidArgumentException('Setor não encontrado para o vínculo informado.');
        }
        if ($this->repository->relationshipExists($cargoId, $setorId)) {
            throw new RuntimeException('Este cargo já está vinculado ao setor selecionado.');
        }

        $this->repository->insertLink($cargoId, $setorId);
    }

    private function normalizeIds(array $ids): array
    {
        $normalized = [];
        foreach ($ids as $id) {
            $value = (int)$id;
            if ($value > 0) {
                $normalized[$value] = $value;
            }
        }

        return array_values($normalized);
    }

    private function assertPositiveId(int $id, string $message): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException($message);
        }
    }
}
