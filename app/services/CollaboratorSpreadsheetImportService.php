<?php

class CollaboratorSpreadsheetImportService
{
    private const DEFAULT_SHEET = 'Ativos x Desligados';
    private const CSV_VIRTUAL_SHEET = 'CSV';
    private const SUPPORTED_EXTENSIONS = ['xlsx', 'csv'];
    private const REQUIRED_HEADERS = [
        'COD',
        'COLABORADOR',
        'EMPRESA',
        'CPF',
        'ADMISSÃO',
        'NASC.',
        'CARGO',
        'DEMISSÃO',
        'MOTIVO RESCISÃO',
    ];

    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    public function validateFile(string $filePath, string $sheetName = self::DEFAULT_SHEET): array
    {
        $extension = $this->detectExtension($filePath);
        if (!is_file($filePath)) {
            return [
                'ok' => false,
                'file_exists' => false,
                'file_path' => $filePath,
                'file_type' => $extension,
                'sheet_exists' => false,
                'sheet_name' => $sheetName,
                'sheet_names' => [],
                'headers' => [],
                'row_count' => 0,
                'errors' => ['Arquivo de importacao nao encontrado.'],
            ];
        }

        if (!in_array($extension, self::SUPPORTED_EXTENSIONS, true)) {
            return [
                'ok' => false,
                'file_exists' => true,
                'file_path' => $filePath,
                'file_type' => $extension,
                'sheet_exists' => false,
                'sheet_name' => $sheetName,
                'sheet_names' => [],
                'headers' => [],
                'row_count' => 0,
                'errors' => ['Formato de arquivo nao suportado. Utilize .xlsx ou .csv.'],
            ];
        }

        if ($extension === 'csv') {
            return $this->validateCsvFile($filePath);
        }

        return $this->validateXlsxFile($filePath, $sheetName);
    }

    private function validateXlsxFile(string $filePath, string $sheetName): array
    {
        $reader = new SpreadsheetXlsxReader($filePath);
        $validation = $reader->validate($sheetName);
        if (!($validation['ok'] ?? false)) {
            $validation['file_type'] = 'xlsx';
            return $validation;
        }

        $sheet = $reader->readSheet($sheetName);
        $headers = $sheet['headers'] ?? [];
        $missingHeaders = array_values(array_diff(self::REQUIRED_HEADERS, $headers));

        return [
            'ok' => $missingHeaders === [],
            'file_exists' => true,
            'file_path' => $filePath,
            'file_type' => 'xlsx',
            'sheet_exists' => true,
            'sheet_name' => $sheetName,
            'sheet_names' => $validation['sheet_names'] ?? [],
            'headers' => $headers,
            'row_count' => count($sheet['rows'] ?? []),
            'errors' => $missingHeaders === []
                ? []
                : ['Cabecalhos obrigatorios ausentes: ' . implode(', ', $missingHeaders)],
        ];
    }

    private function validateCsvFile(string $filePath): array
    {
        $sheet = $this->readCsvFile($filePath);
        $headers = $sheet['headers'] ?? [];
        $missingHeaders = array_values(array_diff(self::REQUIRED_HEADERS, $headers));

        return [
            'ok' => $missingHeaders === [],
            'file_exists' => true,
            'file_path' => $filePath,
            'file_type' => 'csv',
            'sheet_exists' => true,
            'sheet_name' => self::CSV_VIRTUAL_SHEET,
            'sheet_names' => [self::CSV_VIRTUAL_SHEET],
            'headers' => $headers,
            'row_count' => count($sheet['rows'] ?? []),
            'errors' => $missingHeaders === []
                ? []
                : ['Cabecalhos obrigatorios ausentes: ' . implode(', ', $missingHeaders)],
        ];
    }

    public function import(string $filePath, array $options = []): array
    {
        $sheetName = (string)($options['sheet_name'] ?? self::DEFAULT_SHEET);
        $dryRun = !empty($options['dry_run']);
        $validateOnly = !empty($options['validate_only']);

        $validation = $this->validateFile($filePath, $sheetName);
        $report = [
            'ok' => false,
            'file_path' => $filePath,
            'sheet_name' => $sheetName,
            'file_type' => (string)($validation['file_type'] ?? $this->detectExtension($filePath)),
            'dry_run' => $dryRun,
            'validate_only' => $validateOnly,
            'generated_at' => date(DATE_ATOM),
            'summary' => [
                'processed' => 0,
                'ignored_blank_rows' => 0,
                'valid' => 0,
                'rejected' => 0,
                'inserted' => 0,
                'updated' => 0,
                'catalog_companies_created' => 0,
                'catalog_roles_created' => 0,
            ],
            'errors' => [],
            'warnings' => [],
            'rejected_records' => [],
            'created_companies' => [],
            'created_roles' => [],
            'validation' => $validation,
        ];

        if (!($validation['ok'] ?? false)) {
            $report['errors'] = $validation['errors'] ?? ['Falha na validacao da planilha.'];
            $report['report_path'] = $this->writeReport($report);
            return $report;
        }

        $sheet = $this->readTabularData($filePath, $sheetName, (string)($validation['file_type'] ?? 'xlsx'));
        $analysis = $this->analyzeRows($sheet['rows'] ?? []);

        $report['summary']['processed'] = $analysis['summary']['processed'];
        $report['summary']['ignored_blank_rows'] = $analysis['summary']['ignored_blank_rows'];
        $report['summary']['valid'] = $analysis['summary']['valid'];
        $report['summary']['rejected'] = $analysis['summary']['rejected'];
        $report['warnings'] = $analysis['warnings'];
        $report['rejected_records'] = $analysis['rejected_records'];

        if ($validateOnly) {
            $report['ok'] = empty($report['errors']) && empty($report['rejected_records']);
            $report['report_path'] = $this->writeReport($report);
            return $report;
        }

        MovimentacaoPessoal::ensureSchema();

        if ($analysis['valid_records'] === []) {
            $report['errors'][] = 'Nenhum registro valido foi encontrado para importacao.';
            $report['report_path'] = $this->writeReport($report);
            return $report;
        }

        $createdCompanies = [];
        $createdRoles = [];
        try {
            $this->pdo()->beginTransaction();
            foreach ($analysis['valid_records'] as $record) {
                $companyId = $this->findOrCreateCatalog('empresas', $record['empresa_nome'], $createdCompanies);
                $roleId = $this->findOrCreateCatalog('cargos', $record['cargo_nome'], $createdRoles);
                $result = $this->upsertCollaborator($record, $companyId, $roleId);

                if ($result['action'] === 'inserted') {
                    $report['summary']['inserted']++;
                } else {
                    $report['summary']['updated']++;
                }
            }

            $report['summary']['catalog_companies_created'] = count($createdCompanies);
            $report['summary']['catalog_roles_created'] = count($createdRoles);
            $report['created_companies'] = array_values($createdCompanies);
            $report['created_roles'] = array_values($createdRoles);

            if ($dryRun) {
                $this->pdo()->rollBack();
            } else {
                $this->pdo()->commit();
            }

            $report['ok'] = true;
        } catch (Throwable $e) {
            if ($this->pdo !== null && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $report['errors'][] = 'Falha transacional na importacao: ' . $e->getMessage();
            Logger::error('Falha ao importar colaboradores via planilha', [
                'file_path' => $filePath,
                'sheet_name' => $sheetName,
                'file_type' => $report['file_type'],
                'exception' => $e->getMessage(),
            ]);
        }

        $report['report_path'] = $this->writeReport($report);
        return $report;
    }

    private function analyzeRows(array $rows): array
    {
        $summary = ['processed' => 0, 'ignored_blank_rows' => 0, 'valid' => 0, 'rejected' => 0];
        $warnings = [];
        $rejected = [];
        $valid = [];
        $seenCompanyCodes = [];
        $seenCpfs = [];

        foreach ($rows as $row) {
            $rowNumber = (int)($row['row_number'] ?? 0);
            $values = is_array($row['values'] ?? null) ? $row['values'] : [];
            if ($this->isBlankSpreadsheetRow($values)) {
                $summary['ignored_blank_rows']++;
                continue;
            }

            $summary['processed']++;
            $record = $this->mapSpreadsheetRow($values, $rowNumber);
            $issues = $this->validateMappedRecord($record);

            if ($record['codigo'] !== '' && $record['empresa_nome'] !== '') {
                $seenCompanyCodes[$this->companyCodeKey($record['empresa_nome'], $record['codigo'])][] = $rowNumber;
            }
            if ($record['cpf'] !== null) {
                $seenCpfs[$record['cpf']][] = $rowNumber;
            }

            if ($issues !== []) {
                $summary['rejected']++;
                $rejected[] = [
                    'row_number' => $rowNumber,
                    'codigo' => $record['codigo'],
                    'nome' => $record['nome'],
                    'causes' => $issues,
                ];
                continue;
            }

            $valid[] = $record;
            $summary['valid']++;
        }

        $duplicateCompanyCodeRows = $this->duplicateRowMap($seenCompanyCodes);

        if ($duplicateCompanyCodeRows !== []) {
            $filtered = [];
            foreach ($valid as $record) {
                $rowNumber = (int)$record['row_number'];
                $conflicts = [];
                if ($record['codigo'] !== '' && $record['empresa_nome'] !== '' && isset($duplicateCompanyCodeRows[$rowNumber])) {
                    $conflicts[] = 'COD duplicado para a mesma empresa na planilha';
                }

                if ($conflicts !== []) {
                    $summary['valid']--;
                    $summary['rejected']++;
                    $rejected[] = [
                        'row_number' => $rowNumber,
                        'codigo' => $record['codigo'],
                        'nome' => $record['nome'],
                        'causes' => $conflicts,
                    ];
                    continue;
                }

                $filtered[] = $record;
            }
            $valid = $filtered;
        }

        foreach ($seenCpfs as $cpf => $rowsByCpf) {
            if (count($rowsByCpf) <= 1) {
                continue;
            }

            $warnings[] = sprintf(
                'CPF %s aparece %d vezes na planilha e sera tratado como historico de recontratacao quando as datas de admissao forem distintas.',
                $cpf,
                count($rowsByCpf)
            );
        }

        foreach ($valid as $record) {
            if ($record['data_demissao'] === null && $record['motivo_rescisao'] !== null) {
                $warnings[] = sprintf(
                    'Linha %d possui motivo de rescisao preenchido sem data de demissao; o registro sera mantido como ativo.',
                    (int)$record['row_number']
                );
            }
        }

        return [
            'summary' => $summary,
            'warnings' => $warnings,
            'rejected_records' => $rejected,
            'valid_records' => $valid,
        ];
    }

    private function mapSpreadsheetRow(array $values, int $rowNumber): array
    {
        $codigo = $this->normalizeText($values['COD'] ?? '');
        $nome = $this->normalizeText($values['COLABORADOR'] ?? '');
        $empresaNome = $this->normalizeText($values['EMPRESA'] ?? '');
        $cpf = $this->normalizeCpf($values['CPF'] ?? '');
        $dataAdmissao = $this->normalizeDate($values['ADMISSÃO'] ?? '');
        $dataNascimento = $this->normalizeDate($values['NASC.'] ?? '');
        $cargoNome = $this->normalizeText($values['CARGO'] ?? '');
        $dataDemissao = $this->normalizeDate($values['DEMISSÃO'] ?? '');
        $motivoRescisao = $this->normalizeText($values['MOTIVO RESCISÃO'] ?? '');

        return [
            'row_number' => $rowNumber,
            'codigo' => $codigo,
            'matricula' => $codigo,
            'nome' => $nome,
            'empresa_nome' => $empresaNome,
            'cpf' => $cpf,
            'data_admissao' => $dataAdmissao,
            'data_nascimento' => $dataNascimento,
            'cargo_nome' => $cargoNome,
            'data_demissao' => $dataDemissao,
            'motivo_rescisao' => $motivoRescisao !== '' ? $motivoRescisao : null,
            'ativo' => $dataDemissao === null ? 1 : 0,
            'raw' => $values,
        ];
    }

    private function validateMappedRecord(array $record): array
    {
        $issues = [];

        if ($record['codigo'] === '') {
            $issues[] = 'COD obrigatorio nao informado';
        }
        if ($record['nome'] === '') {
            $issues[] = 'COLABORADOR obrigatorio nao informado';
        }
        if ($record['empresa_nome'] === '') {
            $issues[] = 'EMPRESA obrigatoria nao informada';
        }
        if ($record['cargo_nome'] === '') {
            $issues[] = 'CARGO obrigatorio nao informado';
        }
        if ($record['cpf'] === null) {
            $issues[] = 'CPF obrigatorio nao informado';
        } elseif (!Security::isValidCpf($record['cpf'])) {
            $issues[] = 'CPF invalido';
        }
        if ($record['data_admissao'] === null) {
            $issues[] = 'ADMISSÃO obrigatoria invalida ou ausente';
        }
        if ($record['data_nascimento'] === null) {
            $issues[] = 'NASC. obrigatoria invalida ou ausente';
        }
        if ($record['data_nascimento'] !== null && $record['data_nascimento'] > date('Y-m-d')) {
            $issues[] = 'Data de nascimento futura';
        }
        if ($record['data_admissao'] !== null && $record['data_nascimento'] !== null && $record['data_nascimento'] > $record['data_admissao']) {
            $issues[] = 'Nascimento posterior a admissao';
        }
        if ($record['data_demissao'] !== null && $record['data_admissao'] !== null && $record['data_demissao'] < $record['data_admissao']) {
            $issues[] = 'Demissao anterior a admissao';
        }
        if ($record['data_demissao'] !== null && $record['motivo_rescisao'] === null) {
            $issues[] = 'Motivo de rescisao obrigatorio quando houver demissao';
        }

        return $issues;
    }

    private function upsertCollaborator(array $record, int $companyId, int $roleId): array
    {
        $matchesByCompanyCode = $this->findCollaboratorsByCompanyAndCode($companyId, $record['codigo']);
        $matchesByCpfAndAdmission = $record['cpf'] !== null && $record['data_admissao'] !== null
            ? $this->findCollaboratorsByCpfAndAdmission($record['cpf'], $record['data_admissao'])
            : [];
        $matchesByActiveCompanyCpf = ($record['cpf'] !== null && (int)$record['ativo'] === 1)
            ? $this->findActiveCollaboratorsByCompanyAndCpf($companyId, $record['cpf'])
            : [];

        $this->assertUniqueMatchSet(
            sprintf('Conflito: COD %s ja esta vinculado a mais de um colaborador na empresa %d.', $record['codigo'], $companyId),
            $matchesByCompanyCode
        );
        $this->assertUniqueMatchSet(
            sprintf(
                'Conflito: CPF %s com admissao %s esta vinculado a mais de um colaborador no banco.',
                (string)$record['cpf'],
                (string)$record['data_admissao']
            ),
            $matchesByCpfAndAdmission
        );
        $this->assertUniqueMatchSet(
            sprintf('Conflito: CPF %s possui mais de um vinculo ativo na empresa %d.', (string)$record['cpf'], $companyId),
            $matchesByActiveCompanyCpf
        );

        $current = $this->resolveCurrentCollaborator(
            $record,
            $companyId,
            $matchesByCompanyCode,
            $matchesByCpfAndAdmission,
            $matchesByActiveCompanyCpf
        );

        if ($current) {
            $stmt = $this->pdo()->prepare(
                'UPDATE colaboradores
                 SET nome = ?, slug = ?, matricula = ?, codigo = ?, cpf = ?, cargo_id = ?, empresa_id = ?,
                     data_admissao = ?, data_nascimento = ?, data_demissao = ?, motivo_rescisao = ?, ativo = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                $record['nome'],
                $this->uniqueCollaboratorSlug($record['nome'], $record['codigo'], (int)$current['id']),
                $record['matricula'],
                $record['codigo'],
                $record['cpf'],
                $roleId,
                $companyId,
                $record['data_admissao'],
                $record['data_nascimento'],
                $record['data_demissao'],
                $record['motivo_rescisao'],
                $record['ativo'],
                (int)$current['id'],
            ]);

            return ['action' => 'updated', 'id' => (int)$current['id']];
        }

        $stmt = $this->pdo()->prepare(
            'INSERT INTO colaboradores
             (nome, slug, matricula, codigo, cpf, cargo_id, empresa_id, setor_id, salario_atual, data_admissao, data_inicio_cargo, data_nascimento, data_demissao, motivo_rescisao, ativo)
             VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $record['nome'],
            $this->uniqueCollaboratorSlug($record['nome'], $record['codigo'], null),
            $record['matricula'],
            $record['codigo'],
            $record['cpf'],
            $roleId,
            $companyId,
            $record['data_admissao'],
            $record['data_admissao'],
            $record['data_nascimento'],
            $record['data_demissao'],
            $record['motivo_rescisao'],
            $record['ativo'],
        ]);

        return ['action' => 'inserted', 'id' => (int)$this->pdo()->lastInsertId()];
    }

    private function findOrCreateCatalog(string $table, string $name, array &$createdItems): int
    {
        $normalizedName = $this->normalizeText($name);
        if ($normalizedName === '') {
            throw new RuntimeException(sprintf('Nome vazio ao localizar cadastro em %s.', $table));
        }

        $stmt = $this->pdo()->prepare(sprintf(
            'SELECT id, nome FROM %s WHERE LOWER(TRIM(nome)) = LOWER(TRIM(?)) ORDER BY id ASC LIMIT 1',
            $table
        ));
        $stmt->execute([$normalizedName]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            return (int)$existing['id'];
        }

        $slug = $this->uniqueCatalogSlug($table, $normalizedName);
        $insert = $this->pdo()->prepare(sprintf(
            'INSERT INTO %s (nome, slug, ativo) VALUES (?, ?, 1)',
            $table
        ));
        $insert->execute([$normalizedName, $slug]);
        $id = (int)$this->pdo()->lastInsertId();
        $createdItems[(string)$id] = [
            'id' => $id,
            'nome' => $normalizedName,
            'slug' => $slug,
        ];

        return $id;
    }

    private function findCollaboratorsByCompanyAndCode(int $companyId, string $code): array
    {
        if ($code === '') {
            return [];
        }

        $stmt = $this->pdo()->prepare(
            'SELECT id, nome, slug, codigo, cpf, empresa_id, data_admissao, data_demissao, ativo
             FROM colaboradores
             WHERE codigo = ? AND empresa_id = ?
             ORDER BY id ASC'
        );
        $stmt->execute([$code, $companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function findCollaboratorsByCpfAndAdmission(string $cpf, string $admissionDate): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT id, nome, slug, codigo, cpf, empresa_id, data_admissao, data_demissao, ativo
             FROM colaboradores
             WHERE cpf = ? AND data_admissao = ?
             ORDER BY id ASC'
        );
        $stmt->execute([$cpf, $admissionDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function findActiveCollaboratorsByCompanyAndCpf(int $companyId, string $cpf): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT id, nome, slug, codigo, cpf, empresa_id, data_admissao, data_demissao, ativo
             FROM colaboradores
             WHERE empresa_id = ? AND cpf = ? AND ativo = 1 AND data_demissao IS NULL
             ORDER BY id ASC'
        );
        $stmt->execute([$companyId, $cpf]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function writeReport(array $report): string
    {
        $directory = BASE_PATH . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'imports';
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $filePath = $directory . DIRECTORY_SEPARATOR . 'colaboradores-import-' . date('Ymd-His') . '.json';
        file_put_contents(
            $filePath,
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        Logger::info('Relatorio de importacao de colaboradores gerado', [
            'report_path' => $filePath,
            'processed' => (int)($report['summary']['processed'] ?? 0),
            'inserted' => (int)($report['summary']['inserted'] ?? 0),
            'updated' => (int)($report['summary']['updated'] ?? 0),
            'rejected' => (int)($report['summary']['rejected'] ?? 0),
        ]);

        return $filePath;
    }

    private function normalizeText($value): string
    {
        $value = trim((string)$value);
        $value = preg_replace('/\s+/', ' ', $value) ?? '';
        return $value;
    }

    private function normalizeCpf($value): ?string
    {
        $digits = preg_replace('/\D+/', '', trim((string)$value)) ?? '';
        if ($digits === '') {
            return null;
        }

        if (strlen($digits) < 11) {
            $digits = str_pad($digits, 11, '0', STR_PAD_LEFT);
        }

        return $digits;
    }

    private function normalizeDate($value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d+$/', $value)) {
            $days = (int)$value;
            if ($days > 0) {
                $base = new DateTimeImmutable('1899-12-30');
                return $base->modify('+' . $days . ' days')->format('Y-m-d');
            }
        }

        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $value)) {
            return DateHelper::toDatabaseDate($value);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }

        return null;
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($transliterated) && $transliterated !== '') {
            $value = $transliterated;
        }

        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }

    private function uniqueCatalogSlug(string $table, string $name): string
    {
        $baseSlug = $this->slugify($name);
        $slug = $baseSlug !== '' ? $baseSlug : 'item';
        $suffix = 1;

        while ($this->catalogSlugExists($table, $slug)) {
            $suffix++;
            $slug = ($baseSlug !== '' ? $baseSlug : 'item') . '-' . $suffix;
        }

        return $slug;
    }

    private function catalogSlugExists(string $table, string $slug): bool
    {
        $stmt = $this->pdo()->prepare(sprintf('SELECT COUNT(*) FROM %s WHERE slug = ?', $table));
        $stmt->execute([$slug]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function uniqueCollaboratorSlug(string $name, string $code, ?int $ignoreId): string
    {
        $baseSlug = $this->slugify($name . '-' . $code);
        if ($baseSlug === '') {
            $baseSlug = 'colaborador';
        }
        $slug = $baseSlug;
        $suffix = 1;

        while ($this->collaboratorSlugExists($slug, $ignoreId)) {
            $suffix++;
            $slug = $baseSlug . '-' . $suffix;
        }

        return $slug;
    }

    private function collaboratorSlugExists(string $slug, ?int $ignoreId): bool
    {
        $sql = 'SELECT COUNT(*) FROM colaboradores WHERE slug = ?';
        $params = [$slug];
        if ($ignoreId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $ignoreId;
        }

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function duplicateRowMap(array $valuesByKey): array
    {
        $duplicates = [];
        foreach ($valuesByKey as $rows) {
            if (count($rows) <= 1) {
                continue;
            }
            foreach ($rows as $rowNumber) {
                $duplicates[(int)$rowNumber] = true;
            }
        }
        return $duplicates;
    }

    private function readTabularData(string $filePath, string $sheetName, string $fileType): array
    {
        if ($fileType === 'csv') {
            return $this->readCsvFile($filePath);
        }

        $reader = new SpreadsheetXlsxReader($filePath);
        return $reader->readSheet($sheetName);
    }

    private function readCsvFile(string $filePath): array
    {
        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Nao foi possivel abrir o arquivo CSV para leitura.');
        }

        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            return ['headers' => [], 'rows' => []];
        }

        $delimiter = $this->detectCsvDelimiter($this->normalizeCsvLine($firstLine));
        rewind($handle);

        $headers = [];
        $rows = [];
        $lineNumber = 0;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $lineNumber++;
            $row = array_map(fn($value) => $this->normalizeImportedValue($value), $row);
            if ($lineNumber === 1) {
                if (isset($row[0])) {
                    $row[0] = ltrim($row[0], "\xEF\xBB\xBF");
                }
                $headers = array_map(fn($value) => trim((string)$value), $row);
                continue;
            }

            $values = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }
                $values[$header] = $row[$index] ?? '';
            }

            $rows[] = [
                'row_number' => $lineNumber,
                'values' => $values,
            ];
        }

        fclose($handle);

        return [
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    private function detectCsvDelimiter(string $line): string
    {
        $candidates = [';' => substr_count($line, ';'), ',' => substr_count($line, ','), "\t" => substr_count($line, "\t")];
        arsort($candidates);
        $delimiter = (string)array_key_first($candidates);
        return $delimiter !== '' ? $delimiter : ';';
    }

    private function normalizeCsvLine(string $line): string
    {
        $line = ltrim($line, "\xEF\xBB\xBF");
        return $this->normalizeImportedValue($line);
    }

    private function normalizeImportedValue($value): string
    {
        $value = (string)$value;
        if ($value === '') {
            return '';
        }

        if (function_exists('mb_check_encoding') && !mb_check_encoding($value, 'UTF-8')) {
            $converted = @mb_convert_encoding($value, 'UTF-8', 'Windows-1252, ISO-8859-1, UTF-8');
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        return $value;
    }

    private function detectExtension(string $filePath): string
    {
        return strtolower((string)pathinfo($filePath, PATHINFO_EXTENSION));
    }

    private function companyCodeKey(string $companyName, string $code): string
    {
        return mb_strtolower(trim($companyName), 'UTF-8') . '|' . trim($code);
    }

    private function assertUniqueMatchSet(string $message, array $matches): void
    {
        if (count($matches) > 1) {
            throw new RuntimeException($message);
        }
    }

    private function resolveCurrentCollaborator(
        array $record,
        int $companyId,
        array $matchesByCompanyCode,
        array $matchesByCpfAndAdmission,
        array $matchesByActiveCompanyCpf
    ): ?array {
        $candidateById = [];
        foreach ([$matchesByCompanyCode, $matchesByCpfAndAdmission, $matchesByActiveCompanyCpf] as $matches) {
            foreach ($matches as $match) {
                $candidateById[(int)$match['id']] = $match;
            }
        }

        if (count($candidateById) > 1) {
            throw new RuntimeException(sprintf(
                'Conflito de identificacao para CPF %s, COD %s e empresa %d. Revise o historico existente antes de importar.',
                (string)$record['cpf'],
                $record['codigo'],
                $companyId
            ));
        }

        if ($candidateById === []) {
            return null;
        }

        return array_values($candidateById)[0];
    }

    private function isBlankSpreadsheetRow(array $values): bool
    {
        foreach ($values as $value) {
            if ($this->normalizeText($value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = Database::conn();
        }

        return $this->pdo;
    }
}
