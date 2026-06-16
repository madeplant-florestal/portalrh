<?php
class CargoSetorRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::conn();
    }

    public function connection(): PDO
    {
        return $this->pdo;
    }

    public function buscarCargo(int $cargoId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, nome, slug, ativo, created_at FROM cargos WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$cargoId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function buscarSetor(int $setorId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT s.id, s.nome, s.slug, s.ativo, s.created_at, s.empresa_id, e.nome AS empresa_nome, e.ativo AS empresa_ativa
             FROM setores s
             LEFT JOIN empresas e ON e.id = s.empresa_id
             WHERE s.id = ?
             LIMIT 1"
        );
        $stmt->execute([$setorId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function cargoExists(int $cargoId): bool
    {
        return $this->buscarCargo($cargoId) !== null;
    }

    public function setorExists(int $setorId): bool
    {
        return $this->buscarSetor($setorId) !== null;
    }

    public function relationshipExists(int $cargoId, int $setorId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM cargo_setores WHERE cargo_id = ? AND setor_id = ?'
        );
        $stmt->execute([$cargoId, $setorId]);

        return (int)$stmt->fetchColumn() > 0;
    }

    public function insertLink(int $cargoId, int $setorId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO cargo_setores (cargo_id, setor_id) VALUES (?, ?)'
        );
        $stmt->execute([$cargoId, $setorId]);
    }

    public function deleteLink(int $cargoId, int $setorId): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM cargo_setores WHERE cargo_id = ? AND setor_id = ?'
        );
        $stmt->execute([$cargoId, $setorId]);

        return $stmt->rowCount() > 0;
    }

    public function listarSetoresPorCargo(int $cargoId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT s.id, s.nome, s.slug, s.ativo, cs.created_at
             FROM cargo_setores cs
             INNER JOIN setores s ON s.id = cs.setor_id
             WHERE cs.cargo_id = ?
             ORDER BY s.nome ASC"
        );
        $stmt->execute([$cargoId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listarCargosPorSetor(int $setorId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.id, c.nome, c.slug, c.ativo, cs.created_at
             FROM cargo_setores cs
             INNER JOIN cargos c ON c.id = cs.cargo_id
             WHERE cs.setor_id = ?
             ORDER BY c.nome ASC"
        );
        $stmt->execute([$setorId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listarSetoresDisponiveisParaCargo(int $cargoId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT s.id, s.nome, s.slug, s.ativo
             FROM setores s
             WHERE s.ativo = 1
               AND NOT EXISTS (
                    SELECT 1
                    FROM cargo_setores cs
                    WHERE cs.cargo_id = ? AND cs.setor_id = s.id
               )
             ORDER BY s.nome ASC"
        );
        $stmt->execute([$cargoId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listarCargosDisponiveisParaSetor(int $setorId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.id, c.nome, c.slug, c.ativo
             FROM cargos c
             WHERE c.ativo = 1
               AND NOT EXISTS (
                    SELECT 1
                    FROM cargo_setores cs
                    WHERE cs.setor_id = ? AND cs.cargo_id = c.id
               )
             ORDER BY c.nome ASC"
        );
        $stmt->execute([$setorId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
