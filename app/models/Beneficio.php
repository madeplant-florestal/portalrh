<?php
class Beneficio
{
    private static function ensureTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS beneficios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(120) NOT NULL,
            descricao TEXT NULL,
            parceiro VARCHAR(120) NULL,
            logo_path VARCHAR(255) NULL,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        Database::conn()->exec($sql);
    }

    public static function all(): array
    {
        self::ensureTable();
        $stmt = Database::conn()->query('SELECT * FROM beneficios ORDER BY created_at DESC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function allActive(): array
    {
        self::ensureTable();
        $stmt = Database::conn()->query('SELECT * FROM beneficios WHERE ativo = 1 ORDER BY created_at DESC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function find(int $id): ?array
    {
        self::ensureTable();
        $stmt = Database::conn()->prepare('SELECT * FROM beneficios WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function create(array $data): int
    {
        self::ensureTable();
        $stmt = Database::conn()->prepare('INSERT INTO beneficios (nome, descricao, parceiro, logo_path, ativo) VALUES (?,?,?,?,?)');
        $stmt->execute([$data['nome'], $data['descricao'] ?? null, $data['parceiro'] ?? null, $data['logo_path'] ?? null, (int)($data['ativo'] ?? 1)]);
        return (int)Database::conn()->lastInsertId();
    }

    public static function update(int $id, array $data): bool
    {
        self::ensureTable();
        $stmt = Database::conn()->prepare('UPDATE beneficios SET nome = ?, descricao = ?, parceiro = ?, logo_path = ?, ativo = ? WHERE id = ?');
        return $stmt->execute([$data['nome'], $data['descricao'] ?? null, $data['parceiro'] ?? null, $data['logo_path'] ?? null, (int)($data['ativo'] ?? 1), $id]);
    }

    public static function delete(int $id): bool
    {
        self::ensureTable();
        $stmt = Database::conn()->prepare('DELETE FROM beneficios WHERE id = ?');
        return $stmt->execute([$id]);
    }
}