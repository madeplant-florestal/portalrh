<?php
class CadastroOrganizacional
{
    private const TABLES = [
        'empresas' => [
            'singular' => 'Empresa',
            'plural' => 'Empresas',
            'reference_column' => 'empresa_id',
        ],
        'setores' => [
            'singular' => 'Setor',
            'plural' => 'Setores',
            'reference_column' => 'setor_id',
        ],
        'cargos' => [
            'singular' => 'Cargo',
            'plural' => 'Cargos',
            'reference_column' => 'cargo_id',
        ],
    ];

    public static function meta(string $table): array
    {
        self::assertSupportedTable($table);
        return self::TABLES[$table];
    }

    public static function all(string $table, array $filters = []): array
    {
        self::assertSupportedTable($table);
        if (!self::tableExists($table)) {
            return [];
        }

        $sql = sprintf(
            'SELECT id, nome, slug, ativo, created_at FROM %s WHERE 1=1',
            $table
        );
        $params = [];

        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $sql .= ' AND (nome LIKE ? OR slug LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $status = trim((string)($filters['status'] ?? ''));
        if ($status === 'active') {
            $sql .= ' AND ativo = 1';
        } elseif ($status === 'inactive') {
            $sql .= ' AND ativo = 0';
        }

        $sql .= ' ORDER BY nome ASC';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['usage_count'] = self::usageCount($table, (int)($row['id'] ?? 0));
        }

        return $rows;
    }

    public static function summary(string $table): array
    {
        self::assertSupportedTable($table);
        if (!self::tableExists($table)) {
            return [
                'total' => 0,
                'ativos' => 0,
                'inativos' => 0,
                'vinculados' => 0,
            ];
        }

        $stmt = Database::conn()->query(sprintf(
            'SELECT COUNT(*) AS total, SUM(CASE WHEN ativo = 1 THEN 1 ELSE 0 END) AS ativos FROM %s',
            $table
        ));
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total' => (int)($row['total'] ?? 0),
            'ativos' => (int)($row['ativos'] ?? 0),
            'inativos' => max(0, (int)($row['total'] ?? 0) - (int)($row['ativos'] ?? 0)),
            'vinculados' => self::linkedCount($table),
        ];
    }

    public static function find(string $table, int $id): ?array
    {
        self::assertSupportedTable($table);
        if (!self::tableExists($table)) {
            return null;
        }

        $stmt = Database::conn()->prepare(sprintf(
            'SELECT id, nome, slug, ativo, created_at FROM %s WHERE id = ? LIMIT 1',
            $table
        ));
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function create(string $table, array $data): int
    {
        self::assertSupportedTable($table);
        $normalized = self::normalizeData($data);

        $stmt = Database::conn()->prepare(sprintf(
            'INSERT INTO %s (nome, slug, ativo) VALUES (?, ?, ?)',
            $table
        ));
        $stmt->execute([
            $normalized['nome'],
            $normalized['slug'],
            $normalized['ativo'],
        ]);

        return (int)Database::conn()->lastInsertId();
    }

    public static function update(string $table, int $id, array $data): bool
    {
        self::assertSupportedTable($table);
        $normalized = self::normalizeData($data);

        $stmt = Database::conn()->prepare(sprintf(
            'UPDATE %s SET nome = ?, slug = ?, ativo = ? WHERE id = ?',
            $table
        ));

        return $stmt->execute([
            $normalized['nome'],
            $normalized['slug'],
            $normalized['ativo'],
            $id,
        ]);
    }

    public static function delete(string $table, int $id): bool
    {
        self::assertSupportedTable($table);

        if (self::usageCount($table, $id) > 0) {
            return false;
        }

        $stmt = Database::conn()->prepare(sprintf('DELETE FROM %s WHERE id = ?', $table));
        return $stmt->execute([$id]);
    }

    public static function usageCount(string $table, int $id): int
    {
        self::assertSupportedTable($table);
        if (!self::tableExists('colaboradores') || $id <= 0) {
            return 0;
        }

        $referenceColumn = self::meta($table)['reference_column'];
        $stmt = Database::conn()->prepare(sprintf(
            'SELECT COUNT(*) FROM colaboradores WHERE %s = ?',
            $referenceColumn
        ));
        $stmt->execute([$id]);

        return (int)$stmt->fetchColumn();
    }

    private static function linkedCount(string $table): int
    {
        self::assertSupportedTable($table);
        if (!self::tableExists('colaboradores')) {
            return 0;
        }

        $referenceColumn = self::meta($table)['reference_column'];
        $stmt = Database::conn()->query(sprintf(
            'SELECT COUNT(DISTINCT %s) FROM colaboradores WHERE %s IS NOT NULL',
            $referenceColumn,
            $referenceColumn
        ));

        return (int)$stmt->fetchColumn();
    }

    private static function normalizeData(array $data): array
    {
        $nome = trim(Security::sanitizeString($data['nome'] ?? ''));
        if ($nome === '') {
            throw new InvalidArgumentException('Informe o nome do cadastro.');
        }

        $slug = trim(Security::sanitizeString($data['slug'] ?? ''));
        if ($slug === '') {
            $slug = self::slugify($nome);
        } else {
            $slug = self::slugify($slug);
        }

        if ($slug === '') {
            throw new InvalidArgumentException('Nao foi possivel gerar um identificador valido.');
        }

        return [
            'nome' => $nome,
            'slug' => $slug,
            'ativo' => !empty($data['ativo']) ? 1 : 0,
        ];
    }

    private static function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($transliterated) && $transliterated !== '') {
            $value = $transliterated;
        }

        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }

    private static function assertSupportedTable(string $table): void
    {
        if (!isset(self::TABLES[$table])) {
            throw new InvalidArgumentException('Cadastro nao suportado.');
        }
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
