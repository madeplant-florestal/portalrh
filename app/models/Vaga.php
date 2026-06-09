<?php
class Vaga
{
    public int $id;
    public string $titulo;
    public string $descricao;
    public string $requisitos;
    public string $area;
    public string $local;
    public int $ativo; // 1 ou 0
    public string $created_at;

    public static function allActive(): array
    {
        $sql = 'SELECT id, titulo, requisitos, area, local FROM vagas WHERE ativo = 1 ORDER BY created_at DESC';
        $stmt = Database::conn()->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function all(): array
    {
        $sql = 'SELECT * FROM vagas ORDER BY created_at DESC';
        $stmt = Database::conn()->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function find(int $id): ?array
    {
        $sql = 'SELECT * FROM vagas WHERE id = ? LIMIT 1';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute([$id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        return $data ?: null;
    }

    public static function create(array $data): int
    {
        $sql = 'INSERT INTO vagas (titulo, descricao, requisitos, area, local, ativo) VALUES (?,?,?,?,?,?)';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute([
            $data['titulo'], $data['descricao'], $data['requisitos'], $data['area'], $data['local'], (int)$data['ativo']
        ]);
        return (int)Database::conn()->lastInsertId();
    }

    public static function update(int $id, array $data): bool
    {
        $sql = 'UPDATE vagas SET titulo=?, descricao=?, requisitos=?, area=?, local=?, ativo=? WHERE id=?';
        $stmt = Database::conn()->prepare($sql);
        return $stmt->execute([
            $data['titulo'], $data['descricao'], $data['requisitos'], $data['area'], $data['local'], (int)$data['ativo'], $id
        ]);
    }

    public static function delete(int $id): bool
    {
        $sql = 'DELETE FROM vagas WHERE id = ?';
        $stmt = Database::conn()->prepare($sql);
        return $stmt->execute([$id]);
    }
}