<?php
class EmpresaRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::conn();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, nome, slug, ativo, created_at FROM empresas WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function exists(int $id): bool
    {
        return $this->find($id) !== null;
    }

    public function isActive(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT ativo FROM empresas WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $value = $stmt->fetchColumn();

        return (int)$value === 1;
    }

    public function activeOptions(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, nome, slug FROM empresas WHERE ativo = 1 ORDER BY nome ASC'
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function allOptions(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, nome, slug, ativo FROM empresas ORDER BY nome ASC'
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function linkedSetorCount(int $empresaId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM setores WHERE empresa_id = ?'
        );
        $stmt->execute([$empresaId]);

        return (int)$stmt->fetchColumn();
    }
}
