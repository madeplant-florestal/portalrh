<?php
class SetorRepository
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

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT s.id, s.nome, s.slug, s.ativo, s.created_at, s.empresa_id, e.nome AS empresa_nome, e.ativo AS empresa_ativa
             FROM setores s
             LEFT JOIN empresas e ON e.id = s.empresa_id
             WHERE s.id = ?
             LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function create(SetorData $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO setores (nome, slug, ativo, empresa_id) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$data->nome, $data->slug, $data->ativo, $data->empresaId]);

        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, SetorData $data): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE setores SET nome = ?, slug = ?, ativo = ?, empresa_id = ? WHERE id = ?'
        );

        return $stmt->execute([$data->nome, $data->slug, $data->ativo, $data->empresaId, $id]);
    }

    public function paginateAdmin(array $filters, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        [$whereSql, $params] = $this->buildFilterSql($filters);

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM setores s LEFT JOIN empresas e ON e.id = s.empresa_id' . $whereSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $pages = max(1, (int)ceil($total / $perPage));
        if ($page > $pages) {
            $page = $pages;
            $offset = ($page - 1) * $perPage;
        }

        $sql = "SELECT s.id, s.nome, s.slug, s.ativo, s.created_at, s.empresa_id,
                       e.nome AS empresa_nome,
                       e.ativo AS empresa_ativa,
                       COUNT(DISTINCT cs.cargo_id) AS cargos_vinculados,
                       COUNT(DISTINCT c.id) AS colaboradores_vinculados
                FROM setores s
                LEFT JOIN empresas e ON e.id = s.empresa_id
                LEFT JOIN cargo_setores cs ON cs.setor_id = s.id
                LEFT JOIN colaboradores c ON c.setor_id = s.id
                {$whereSql}
                GROUP BY s.id, s.nome, s.slug, s.ativo, s.created_at, s.empresa_id, e.nome, e.ativo
                ORDER BY s.nome ASC
                LIMIT ? OFFSET ?";
        $stmt = $this->pdo->prepare($sql);
        $queryParams = $params;
        $queryParams[] = $perPage;
        $queryParams[] = $offset;
        $stmt->execute($queryParams);

        return [
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
        ];
    }

    public function exportDataset(array $filters): array
    {
        [$whereSql, $params] = $this->buildFilterSql($filters);

        $sql = "SELECT s.id, s.nome, s.slug, s.ativo, s.created_at, s.empresa_id,
                       e.nome AS empresa_nome,
                       COUNT(DISTINCT cs.cargo_id) AS cargos_vinculados,
                       COUNT(DISTINCT c.id) AS colaboradores_vinculados
                FROM setores s
                LEFT JOIN empresas e ON e.id = s.empresa_id
                LEFT JOIN cargo_setores cs ON cs.setor_id = s.id
                LEFT JOIN colaboradores c ON c.setor_id = s.id
                {$whereSql}
                GROUP BY s.id, s.nome, s.slug, s.ativo, s.created_at, s.empresa_id, e.nome
                ORDER BY s.nome ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countLegacyWithoutEmpresa(): int
    {
        if (!$this->columnExists('empresa_id')) {
            return 0;
        }

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM setores WHERE empresa_id IS NULL');
        return (int)$stmt->fetchColumn();
    }

    public function listByEmpresa(int $empresaId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT s.id, s.nome, s.slug, s.ativo, s.created_at, s.empresa_id, e.nome AS empresa_nome
             FROM setores s
             INNER JOIN empresas e ON e.id = s.empresa_id
             WHERE s.empresa_id = ?
             ORDER BY s.nome ASC"
        );
        $stmt->execute([$empresaId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function reportSummary(array $filters): array
    {
        $rows = $this->exportDataset($filters);
        $semEmpresa = count(array_filter($rows, static fn(array $row): bool => empty($row['empresa_id'])));

        return [
            'total' => count($rows),
            'ativos' => count(array_filter($rows, static fn(array $row): bool => (int)($row['ativo'] ?? 0) === 1)),
            'inativos' => count(array_filter($rows, static fn(array $row): bool => (int)($row['ativo'] ?? 0) !== 1)),
            'sem_empresa' => $semEmpresa,
        ];
    }

    public function sanitizeLegacyWithoutEmpresa(int $empresaId): int
    {
        if (!$this->columnExists('empresa_id')) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE setores SET empresa_id = ? WHERE empresa_id IS NULL'
        );
        $stmt->execute([$empresaId]);

        return $stmt->rowCount();
    }

    private function buildFilterSql(array $filters): array
    {
        $where = [];
        $params = [];

        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(s.nome LIKE ? OR s.slug LIKE ? OR e.nome LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $status = trim((string)($filters['status'] ?? ''));
        if ($status === 'active') {
            $where[] = 's.ativo = 1';
        } elseif ($status === 'inactive') {
            $where[] = 's.ativo = 0';
        }

        $empresaFilter = trim((string)($filters['empresa'] ?? ''));
        if ($empresaFilter === 'sem_empresa') {
            $where[] = 's.empresa_id IS NULL';
        } elseif ($empresaFilter !== '') {
            $where[] = 's.empresa_id = ?';
            $params[] = (int)$empresaFilter;
        }

        $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
        return [$whereSql, $params];
    }

    private function columnExists(string $column): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'setores'
               AND COLUMN_NAME = ?"
        );
        $stmt->execute([$column]);

        return (int)$stmt->fetchColumn() > 0;
    }
}
