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
            // Coluna extra só para Empresas — "Setores" é uma métrica diferente de
            // "usage_count" (que agora é Colaboradores ativos, ver usageCount()).
            if ($table === 'empresas') {
                $row['setores_count'] = self::setoresVinculadosCount((int)($row['id'] ?? 0));
            }
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
        if ($id <= 0) {
            return 0;
        }

        // Empresa: fonte oficial é colaboradores_metadados (espelho do METADADOS), nunca a
        // tabela local `colaboradores` nem `setores` (quantidade de Setores é uma métrica
        // diferente — ver setoresVinculadosCount()). Ver docs/claude/riscos-conhecidos.md.
        if ($table === 'empresas') {
            return self::colaboradoresAtivosPorEmpresa($id);
        }

        if (!self::tableExists('colaboradores')) {
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

        // Empresa: mesma fonte oficial de usageCount() — total de pessoas distintas com contrato
        // ativo no espelho (não é soma das linhas por empresa, ver colaboradoresAtivosTotal()).
        if ($table === 'empresas') {
            return self::colaboradoresAtivosTotal();
        }

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

    /**
     * Colaboradores ATIVOS de uma Empresa — fonte oficial: `colaboradores_metadados` (espelho do
     * METADADOS), casado com o catálogo local por `codigo_empresa` (código opaco, comparação
     * exata). Conta PESSOAS distintas (`codigo_pessoa`), nunca contratos — uma pessoa com dois
     * contratos ativos na mesma empresa (raro, mas possível) conta uma vez só.
     *
     * `COLLATE utf8mb4_general_ci` nos dois lados: `colaboradores_metadados` usa a collation do
     * espelho (ex.: utf8mb4_0900_ai_ci/utf8mb4_uca1400_ai_ci), `empresas` usa utf8mb4_general_ci —
     * sem o COLLATE explícito o JOIN falha com "Illegal mix of collations" no MariaDB de produção.
     */
    private static function colaboradoresAtivosPorEmpresa(int $empresaId): int
    {
        if ($empresaId <= 0 || !self::tableExists('colaboradores_metadados')) {
            return 0;
        }

        $stmt = Database::conn()->prepare(
            'SELECT COUNT(DISTINCT cm.codigo_pessoa)
             FROM colaboradores_metadados cm
             INNER JOIN empresas e
               ON e.codigo_empresa COLLATE utf8mb4_general_ci = cm.codigo_empresa COLLATE utf8mb4_general_ci
             WHERE cm.ativo = 1 AND e.id = ?'
        );
        $stmt->execute([$empresaId]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Total geral de pessoas com contrato ativo no espelho — calculado direto (nunca somando os
     * valores por empresa: uma mesma pessoa pode ter contratos ativos em empresas diferentes, e
     * pessoas sem `codigo_empresa` reconhecido no catálogo local não entrariam na soma).
     */
    private static function colaboradoresAtivosTotal(): int
    {
        if (!self::tableExists('colaboradores_metadados')) {
            return 0;
        }

        $stmt = Database::conn()->query(
            'SELECT COUNT(DISTINCT codigo_pessoa) FROM colaboradores_metadados WHERE ativo = 1'
        );

        return (int)$stmt->fetchColumn();
    }

    /**
     * Quantidade de Setores cadastrados/vinculados a uma Empresa — métrica DIFERENTE de
     * colaboradores (ver colaboradoresAtivosPorEmpresa()). Antes desta correção, este número era
     * exibido incorretamente sob o rótulo "Uso em colaboradores"; agora vira uma coluna própria.
     */
    public static function setoresVinculadosCount(int $empresaId): int
    {
        if ($empresaId <= 0 || !self::tableExists('setores') || !self::columnExists('setores', 'empresa_id')) {
            return 0;
        }

        $stmt = Database::conn()->prepare('SELECT COUNT(*) FROM setores WHERE empresa_id = ?');
        $stmt->execute([$empresaId]);

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

    private static function columnExists(string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $stmt = Database::conn()->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        $cache[$key] = (int)$stmt->fetchColumn() > 0;

        return $cache[$key];
    }
}
