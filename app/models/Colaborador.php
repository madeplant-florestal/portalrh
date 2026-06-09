<?php
class Colaborador
{
    public static function all(array $filters = []): array
    {
        if (!self::tableExists('colaboradores')) {
            return [];
        }

        $sql = 'SELECT c.id, c.nome, c.ativo, c.created_at,
                       cg.nome AS cargo_nome,
                       e.nome AS empresa_nome,
                       s.nome AS setor_nome
                FROM colaboradores c
                INNER JOIN cargos cg ON cg.id = c.cargo_id
                LEFT JOIN empresas e ON e.id = c.empresa_id
                LEFT JOIN setores s ON s.id = c.setor_id
                WHERE 1=1';
        $params = [];

        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $sql .= ' AND (c.nome LIKE ? OR cg.nome LIKE ? OR e.nome LIKE ? OR s.nome LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if (!empty($filters['cargo_id'])) {
            $sql .= ' AND c.cargo_id = ?';
            $params[] = (int)$filters['cargo_id'];
        }

        if (!empty($filters['empresa_id'])) {
            $sql .= ' AND c.empresa_id = ?';
            $params[] = (int)$filters['empresa_id'];
        }

        if (!empty($filters['setor_id'])) {
            $sql .= ' AND c.setor_id = ?';
            $params[] = (int)$filters['setor_id'];
        }

        if (($filters['status'] ?? '') === 'vinculados') {
            $sql .= ' AND c.empresa_id IS NOT NULL AND c.setor_id IS NOT NULL';
        } elseif (($filters['status'] ?? '') === 'pendentes') {
            $sql .= ' AND (c.empresa_id IS NULL OR c.setor_id IS NULL)';
        }

        $sql .= ' ORDER BY c.nome ASC';

        $stmt = Database::conn()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function countAll(): int
    {
        if (!self::tableExists('colaboradores')) {
            return 0;
        }

        $stmt = Database::conn()->query('SELECT COUNT(*) FROM colaboradores');
        return (int)$stmt->fetchColumn();
    }

    public static function summary(): array
    {
        if (!self::tableExists('colaboradores')) {
            return [
                'total' => 0,
                'ativos' => 0,
                'com_empresa' => 0,
                'com_setor' => 0,
                'cargos_distintos' => 0,
            ];
        }

        $sql = 'SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN ativo = 1 THEN 1 ELSE 0 END) AS ativos,
                    SUM(CASE WHEN empresa_id IS NOT NULL THEN 1 ELSE 0 END) AS com_empresa,
                    SUM(CASE WHEN setor_id IS NOT NULL THEN 1 ELSE 0 END) AS com_setor,
                    COUNT(DISTINCT cargo_id) AS cargos_distintos
                FROM colaboradores';
        $stmt = Database::conn()->query($sql);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total' => (int)($row['total'] ?? 0),
            'ativos' => (int)($row['ativos'] ?? 0),
            'com_empresa' => (int)($row['com_empresa'] ?? 0),
            'com_setor' => (int)($row['com_setor'] ?? 0),
            'cargos_distintos' => (int)($row['cargos_distintos'] ?? 0),
        ];
    }

    public static function cargoOptions(): array
    {
        return self::simpleOptions('cargos');
    }

    public static function empresaOptions(): array
    {
        return self::simpleOptions('empresas');
    }

    public static function setorOptions(): array
    {
        return self::simpleOptions('setores');
    }

    private static function simpleOptions(string $table): array
    {
        if (!self::tableExists($table)) {
            return [];
        }

        $stmt = Database::conn()->query(sprintf('SELECT id, nome FROM %s ORDER BY nome ASC', $table));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function tableExists(string $table): bool
    {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }

        $stmt = Database::conn()->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        $cache[$table] = (int)$stmt->fetchColumn() > 0;
        return $cache[$table];
    }
}
