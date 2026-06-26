<?php
class Colaborador
{
    private static bool $schemaEnsured = false;

    public static function all(array $filters = []): array
    {
        self::ensureRhSchema();
        if (!self::tableExists('colaboradores')) {
            return [];
        }

        $sql = 'SELECT c.id, c.nome, c.ativo, c.created_at,
                       c.matricula, c.codigo, c.cpf, c.salario_atual,
                       c.data_admissao, c.data_inicio_cargo, c.data_nascimento, c.data_demissao, c.motivo_rescisao,
                       c.cargo_id, c.empresa_id, c.setor_id,
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
        self::ensureRhSchema();
        if (!self::tableExists('colaboradores')) {
            return 0;
        }

        $stmt = Database::conn()->query('SELECT COUNT(*) FROM colaboradores');
        return (int)$stmt->fetchColumn();
    }

    public static function summary(): array
    {
        self::ensureRhSchema();
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
        self::ensureRhSchema();
        return self::simpleOptions('cargos');
    }

    public static function empresaOptions(): array
    {
        self::ensureRhSchema();
        return self::simpleOptions('empresas');
    }

    public static function setorOptions(): array
    {
        self::ensureRhSchema();
        return self::simpleOptions('setores');
    }

    public static function find(int $id): ?array
    {
        self::ensureRhSchema();
        if (!self::tableExists('colaboradores')) {
            return null;
        }

        $stmt = Database::conn()->prepare(
            'SELECT c.*, cg.nome AS cargo_nome, e.nome AS empresa_nome, s.nome AS setor_nome
             FROM colaboradores c
             INNER JOIN cargos cg ON cg.id = c.cargo_id
             LEFT JOIN empresas e ON e.id = c.empresa_id
             LEFT JOIN setores s ON s.id = c.setor_id
             WHERE c.id = ?
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $row['tempo_empresa_meses'] = self::monthsSince($row['data_admissao'] ?? null);
        $row['tempo_cargo_meses'] = self::monthsSince($row['data_inicio_cargo'] ?? null);
        $row['tempo_empresa_label'] = self::formatMonthsLabel((int)$row['tempo_empresa_meses']);
        $row['tempo_cargo_label'] = self::formatMonthsLabel((int)$row['tempo_cargo_meses']);

        return $row;
    }

    public static function updateRhData(int $id, array $data): array
    {
        self::ensureRhSchema();
        $existing = self::find($id);
        if (!$existing) {
            return ['ok' => false, 'error' => 'Colaborador não encontrado.'];
        }

        $empresaId = array_key_exists('empresa_id', $existing) && $existing['empresa_id'] !== null
            ? (int)$existing['empresa_id']
            : null;

        $matricula = trim((string)($data['matricula'] ?? ''));
        $codigo = trim((string)($data['codigo'] ?? ($existing['codigo'] ?? '')));
        $cpf = self::normalizeCpf($data['cpf'] ?? ($existing['cpf'] ?? ''));
        $salarioAtual = self::parseMoney($data['salario_atual'] ?? '');
        $dataAdmissao = DateHelper::toDatabaseDate($data['data_admissao'] ?? '');
        $dataInicioCargo = DateHelper::toDatabaseDate($data['data_inicio_cargo'] ?? '');
        $dataNascimento = self::parseOptionalDate($data['data_nascimento'] ?? ($existing['data_nascimento'] ?? ''));
        $dataDemissao = self::parseOptionalDate($data['data_demissao'] ?? ($existing['data_demissao'] ?? ''));
        $motivoRescisao = trim((string)($data['motivo_rescisao'] ?? ($existing['motivo_rescisao'] ?? '')));

        if ($codigo === '') {
            $codigo = $matricula !== '' ? $matricula : (string)($existing['codigo'] ?? '');
        }

        if ($matricula === '') {
            return ['ok' => false, 'error' => 'Informe a matrícula do colaborador.'];
        }
        if ($codigo === '') {
            return ['ok' => false, 'error' => 'Informe um código válido para o colaborador.'];
        }
        if ($cpf !== null && !Security::isValidCpf($cpf)) {
            return ['ok' => false, 'error' => 'Informe um CPF válido com 11 dígitos.'];
        }
        if ($salarioAtual === null || $salarioAtual < 0) {
            return ['ok' => false, 'error' => 'Informe um salário atual válido.'];
        }
        if ($dataAdmissao === null) {
            return ['ok' => false, 'error' => 'Informe a data de admissão no formato DD/MM/AAAA.'];
        }
        if ($dataInicioCargo === null) {
            return ['ok' => false, 'error' => 'Informe a data de início no cargo no formato DD/MM/AAAA.'];
        }
        if (($data['data_nascimento'] ?? '') !== '' && $dataNascimento === null) {
            return ['ok' => false, 'error' => 'Informe a data de nascimento no formato DD/MM/AAAA.'];
        }
        if (($data['data_demissao'] ?? '') !== '' && $dataDemissao === null) {
            return ['ok' => false, 'error' => 'Informe a data de demissão no formato DD/MM/AAAA.'];
        }
        if ($dataInicioCargo < $dataAdmissao) {
            return ['ok' => false, 'error' => 'A data de início no cargo não pode ser anterior à data de admissão.'];
        }
        if ($dataNascimento !== null && $dataNascimento > date('Y-m-d')) {
            return ['ok' => false, 'error' => 'A data de nascimento não pode ser futura.'];
        }
        if ($dataDemissao !== null && $dataDemissao < $dataAdmissao) {
            return ['ok' => false, 'error' => 'A data de demissão não pode ser anterior à data de admissão.'];
        }

        $stmtUnique = Database::conn()->prepare(
            'SELECT id
             FROM colaboradores
             WHERE matricula = ?
               AND empresa_id <=> ?
               AND id <> ?
             LIMIT 1'
        );
        $stmtUnique->execute([$matricula, $empresaId, $id]);
        if ($stmtUnique->fetch(PDO::FETCH_ASSOC)) {
            return ['ok' => false, 'error' => 'Já existe outro colaborador cadastrado com essa matrícula na mesma empresa.'];
        }

        $stmtCodigo = Database::conn()->prepare(
            'SELECT id
             FROM colaboradores
             WHERE codigo = ?
               AND empresa_id <=> ?
               AND id <> ?
             LIMIT 1'
        );
        $stmtCodigo->execute([$codigo, $empresaId, $id]);
        if ($stmtCodigo->fetch(PDO::FETCH_ASSOC)) {
            return ['ok' => false, 'error' => 'Já existe outro colaborador cadastrado com esse código na mesma empresa.'];
        }

        if ($cpf !== null) {
            $stmtCpf = Database::conn()->prepare(
                'SELECT id
                 FROM colaboradores
                 WHERE cpf = ?
                   AND empresa_id <=> ?
                   AND data_admissao <=> ?
                   AND id <> ?
                 LIMIT 1'
            );
            $stmtCpf->execute([$cpf, $empresaId, $dataAdmissao, $id]);
            if ($stmtCpf->fetch(PDO::FETCH_ASSOC)) {
                return ['ok' => false, 'error' => 'Já existe outro colaborador cadastrado com esse CPF para a mesma empresa e data de admissão.'];
            }
        }

        $stmt = Database::conn()->prepare(
            'UPDATE colaboradores
             SET matricula = ?, codigo = ?, cpf = ?, salario_atual = ?, data_admissao = ?, data_inicio_cargo = ?,
                 data_nascimento = ?, data_demissao = ?, motivo_rescisao = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $matricula,
            $codigo,
            $cpf,
            $salarioAtual,
            $dataAdmissao,
            $dataInicioCargo,
            $dataNascimento,
            $dataDemissao,
            $motivoRescisao !== '' ? $motivoRescisao : null,
            $id,
        ]);

        return ['ok' => true];
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

    private static function ensureRhSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }
        if (class_exists('MovimentacaoPessoal')) {
            MovimentacaoPessoal::ensureSchema();
        }
        self::$schemaEnsured = true;
    }

    private static function parseMoney($value): ?float
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        $normalized = preg_replace('/[^0-9,.\-]/', '', $value);
        if ($normalized === '' || $normalized === null) {
            return null;
        }
        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } elseif (str_contains($normalized, ',')) {
            $normalized = str_replace(',', '.', $normalized);
        }
        return is_numeric($normalized) ? round((float)$normalized, 2) : null;
    }

    private static function normalizeCpf($value): ?string
    {
        $normalized = preg_replace('/\D+/', '', trim((string)$value));
        return $normalized !== '' ? $normalized : null;
    }

    private static function parseOptionalDate($value): ?string
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw;
        }
        return DateHelper::toDatabaseDate($raw);
    }

    private static function monthsSince(?string $date): int
    {
        if (!$date) {
            return 0;
        }
        try {
            $start = new DateTimeImmutable($date);
            $now = new DateTimeImmutable('today');
        } catch (Throwable $e) {
            return 0;
        }
        return ((int)$start->diff($now)->y * 12) + (int)$start->diff($now)->m;
    }

    private static function formatMonthsLabel(int $months): string
    {
        $years = intdiv(max($months, 0), 12);
        $remaining = max($months, 0) % 12;
        return sprintf('%d ano(s) e %d mes(es)', $years, $remaining);
    }
}
