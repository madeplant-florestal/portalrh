<?php
class Candidatura
{
    public static function create(array $data): int
    {
        $telefone = Phone::normalize((string)($data['telefone'] ?? ''));
        if ($telefone === null) {
            throw new InvalidArgumentException('Telefone inválido. Informe 11 dígitos (DDD + número).');
        }
        self::ensureCpfColumn();
        self::ensureStageColumn();
        self::ensureIndicacaoColumns();
        $hasNomeIndicador = self::hasColumn('candidaturas', 'indicacao_colaborador_nome');
        if ($hasNomeIndicador) {
            $sql = 'INSERT INTO candidaturas (vaga_id, nome, email, telefone, cpf, cargo_pretendido, experiencia, pdf_path, status, indicacao_colaborador, indicacao_colaborador_nome) VALUES (?,?,?,?,?,?,?,?,?,?,?)';
            $stmt = Database::conn()->prepare($sql);
            $stmt->execute([
                (int)$data['vaga_id'], $data['nome'], $data['email'], $telefone, $data['cpf'], $data['cargo_pretendido'], $data['experiencia'], $data['pdf_path'], $data['status'] ?? 'novo', (int)($data['indicacao_colaborador'] ?? 0), (string)($data['indicacao_colaborador_nome'] ?? '')
            ]);
        } else {
            $sql = 'INSERT INTO candidaturas (vaga_id, nome, email, telefone, cpf, cargo_pretendido, experiencia, pdf_path, status, indicacao_colaborador) VALUES (?,?,?,?,?,?,?,?,?,?)';
            $stmt = Database::conn()->prepare($sql);
            $stmt->execute([
                (int)$data['vaga_id'], $data['nome'], $data['email'], $telefone, $data['cpf'], $data['cargo_pretendido'], $data['experiencia'], $data['pdf_path'], $data['status'] ?? 'novo', (int)($data['indicacao_colaborador'] ?? 0)
            ]);
        }
        return (int)Database::conn()->lastInsertId();
    }

    public static function all(array $filters = []): array
    {
        self::ensureStageColumn();
        self::ensureIndicacaoColumns();
        $sql = 'SELECT c.*, v.titulo AS vaga_titulo, s.nome as stage_nome, s.cor as stage_cor 
                FROM candidaturas c 
                LEFT JOIN vagas v ON v.id = c.vaga_id 
                LEFT JOIN pipeline_stages s ON s.id = c.stage_id
                WHERE 1=1';
        $params = [];
        if (!empty($filters['vaga_id'])) { $sql .= ' AND c.vaga_id = ?'; $params[] = (int)$filters['vaga_id']; }
        if (!empty($filters['status'])) { $sql .= ' AND c.status = ?'; $params[] = $filters['status']; }
        if (!empty($filters['stage_id'])) { $sql .= ' AND c.stage_id = ?'; $params[] = (int)$filters['stage_id']; }
        if (!empty($filters['data_de'])) { $sql .= ' AND c.created_at >= ?'; $params[] = $filters['data_de']; }
        if (!empty($filters['data_ate'])) { $sql .= ' AND c.created_at <= ?'; $params[] = $filters['data_ate']; }
        $sql .= ' ORDER BY c.created_at DESC';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function find(int $id): ?array
    {
        self::ensureStageColumn();
        self::ensureIndicacaoColumns();
        $sql = 'SELECT c.*, v.titulo AS vaga_titulo, s.nome as stage_nome, s.cor as stage_cor 
                FROM candidaturas c 
                LEFT JOIN vagas v ON v.id = c.vaga_id 
                LEFT JOIN pipeline_stages s ON s.id = c.stage_id
                WHERE c.id = ? LIMIT 1';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute([$id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        return $data ?: null;
    }

    public static function updateStage(int $id, int $newStageId, int $userId): bool
    {
        self::ensureStageColumn();
        self::ensureIndicacaoColumns();
        $candidatura = self::find($id);
        if (!$candidatura) return false;

        $oldStageId = $candidatura['stage_id'] ?? null;
        if ($oldStageId == $newStageId) return true;

        $pdo = Database::conn();
        try {
            $pdo->beginTransaction();

            // Update Candidatura
            $stmt = $pdo->prepare('UPDATE candidaturas SET stage_id = ? WHERE id = ?');
            $stmt->execute([$newStageId, $id]);

            // Log Movement
            $stmt = $pdo->prepare('INSERT INTO pipeline_movements (candidatura_id, stage_anterior_id, stage_novo_id, usuario_id) VALUES (?, ?, ?, ?)');
            $stmt->execute([$id, $oldStageId, $newStageId, $userId]);
            
            // Log History (Legacy Table for View Compatibility)
            // Get stage names
            $oldStageName = $candidatura['stage_nome'] ?? 'Desconhecido';
            $stmt = $pdo->prepare('SELECT nome FROM pipeline_stages WHERE id = ?');
            $stmt->execute([$newStageId]);
            $newStageName = $stmt->fetchColumn() ?: 'Desconhecido';

            $normalized = function (string $value): string {
                $clean = trim(mb_strtolower($value, 'UTF-8'));
                $replace = ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c'];
                return strtr($clean, $replace);
            };
            $isContratado = $normalized((string)$newStageName) === 'contratado';
            if ($isContratado && (int)($candidatura['indicacao_colaborador'] ?? 0) === 1) {
                $stmt = $pdo->prepare('UPDATE candidaturas SET indicacao_data_contratacao = COALESCE(indicacao_data_contratacao, NOW()), indicacao_data_fim_experiencia = COALESCE(indicacao_data_fim_experiencia, DATE_ADD(CURDATE(), INTERVAL 90 DAY)) WHERE id = ?');
                $stmt->execute([$id]);
            }
            
            self::addHistorico($id, $oldStageName, $newStageName, "Mudança de etapa via Pipeline", $userId);

            $pdo->commit();
            return true;
        } catch (\Exception $e) {
            $pdo->rollBack();
            return false;
        }
    }

    public static function updateStatusNotes(int $id, string $status, ?string $observacoes = null, ?int $usuarioId = null): bool
    {
        self::ensureObservacoesColumn();
        self::ensureHistoricoTable();
        
        // Buscar status anterior para histórico
        $candidatura = self::find($id);
        $statusAnterior = $candidatura['status'] ?? null;
        
        $sql = 'UPDATE candidaturas SET status = ?, observacoes = ? WHERE id = ?';
        $stmt = Database::conn()->prepare($sql);
        $result = $stmt->execute([$status, $observacoes, $id]);
        
        if ($result) {
            // Salvar no histórico
            self::addHistorico($id, $statusAnterior, $status, $observacoes, $usuarioId);
        }
        
        return $result;
    }

    public static function getHistorico(int $candidaturaId): array
    {
        $sql = 'SELECT h.*, u.nome AS usuario_nome FROM candidatura_historico h 
                LEFT JOIN usuarios u ON u.id = h.usuario_id 
                WHERE h.candidatura_id = ? 
                ORDER BY h.created_at DESC';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute([$candidaturaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function addHistorico(int $candidaturaId, ?string $statusAnterior, string $statusNovo, ?string $observacoes, ?int $usuarioId): void
    {
        $sql = 'INSERT INTO candidatura_historico (candidatura_id, status_anterior, status_novo, observacoes, usuario_id) VALUES (?, ?, ?, ?, ?)';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute([$candidaturaId, $statusAnterior, $statusNovo, $observacoes, $usuarioId]);
    }

    private static function ensureHistoricoTable(): void
    {
        try {
            $db = Database::conn();
            $check = $db->prepare('SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $check->execute(['candidatura_historico']);
            $exists = (int)($check->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0) > 0;
            if (!$exists) {
                $db->exec("CREATE TABLE candidatura_historico (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    candidatura_id INT NOT NULL,
                    status_anterior VARCHAR(30) DEFAULT NULL,
                    status_novo VARCHAR(30) NOT NULL,
                    observacoes TEXT DEFAULT NULL,
                    usuario_id INT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_hist_candidatura FOREIGN KEY (candidatura_id) REFERENCES candidaturas(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            }
        } catch (\Throwable $e) {
            // Silencia para não quebrar fluxo caso permissões de ALTER falhem
        }
    }

    private static function ensureObservacoesColumn(): void
    {
        try {
            $db = Database::conn();
            $check = $db->prepare('SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $check->execute(['candidaturas', 'observacoes']);
            $exists = (int)($check->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0) > 0;
            if (!$exists) {
                // MySQL 8+ supports IF NOT EXISTS; para versões anteriores, o SELECT acima evita erro
                $db->exec('ALTER TABLE candidaturas ADD COLUMN observacoes TEXT NULL');
            }
        } catch (\Throwable $e) {
            // Silencia para não quebrar fluxo caso permissões de ALTER falhem; a coluna será simplesmente ignorada
        }
    }

    private static function ensureCpfColumn(): void
    {
        try {
            $db = Database::conn();
            $check = $db->prepare('SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $check->execute(['candidaturas', 'cpf']);
            $exists = (int)($check->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0) > 0;
            if (!$exists) {
                // Para migrações em bancos já existentes, adiciona como NULL para evitar falhas
                $db->exec('ALTER TABLE candidaturas ADD COLUMN cpf VARCHAR(11) NULL');
                // Opcional: tentar criar índice único se possível
                try {
                    $db->exec('ALTER TABLE candidaturas ADD UNIQUE INDEX uq_candidaturas_cpf (cpf)');
                } catch (\Throwable $e2) { /* ignora se já existir ou se houver duplicatas */ }
            }
        } catch (\Throwable $e) {
            // Silencia para não quebrar fluxo
        }
    }

    private static function ensureStageColumn(): void
    {
        try {
            $db = Database::conn();
            $check = $db->prepare('SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $check->execute(['candidaturas', 'stage_id']);
            $exists = (int)($check->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0) > 0;
            if (!$exists) {
                $db->exec('ALTER TABLE candidaturas ADD COLUMN stage_id INT NULL');
                try {
                    $db->exec('ALTER TABLE candidaturas ADD INDEX idx_candidaturas_stage_id (stage_id)');
                } catch (\Throwable $e2) { }
                try {
                    $db->exec('ALTER TABLE candidaturas ADD CONSTRAINT fk_cand_stage FOREIGN KEY (stage_id) REFERENCES pipeline_stages(id) ON DELETE SET NULL');
                } catch (\Throwable $e3) { }
            }
        } catch (\Throwable $e) {
        }
    }

    // Helpers de apresentação de status
    public static function statusMap(): array
    {
        return [
            'novo' => ['label' => 'Novo', 'bg' => '#3e5c76', 'text' => '#ffffff'],
            'em_analise' => ['label' => 'Em análise', 'bg' => '#1d2d44', 'text' => '#ffffff'],
            'entrevista' => ['label' => 'Entrevista', 'bg' => '#0d1321', 'text' => '#ffffff'],
            'aprovado' => ['label' => 'Aprovado', 'bg' => '#1d2d44', 'text' => '#ffffff'],
            'dispensado' => ['label' => 'Dispensado', 'bg' => '#0d1321', 'text' => '#ffffff'],
        ];
    }

    public static function statusLabel(string $code): string
    {
        $m = self::statusMap();
        return $m[$code]['label'] ?? $code;
    }

    public static function statusBg(string $code): string
    {
        $m = self::statusMap();
        return $m[$code]['bg'] ?? '#e5e7eb';
    }

    public static function statusTextColor(string $code): string
    {
        $m = self::statusMap();
        return $m[$code]['text'] ?? '#111111';
    }

    public static function cpfExists(string $cpf): bool
    {
        self::ensureCpfColumn();
        $sql = 'SELECT COUNT(*) as count FROM candidaturas WHERE cpf = ? LIMIT 1';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute([$cpf]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($result['count'] ?? 0) > 0;
    }

    public static function updateIndicacaoColaborador(int $id, bool $indicado, ?string $colaboradorNome = null): bool
    {
        self::ensureIndicacaoColumns();
        $candidatura = self::find($id);
        if (!$candidatura) {
            return false;
        }
        $pdo = Database::conn();
        $hasNomeIndicador = self::hasColumn('candidaturas', 'indicacao_colaborador_nome');
        if (!$indicado) {
            if ($hasNomeIndicador) {
                $sql = 'UPDATE candidaturas SET indicacao_colaborador = 0, indicacao_colaborador_nome = NULL, indicacao_data_contratacao = NULL, indicacao_data_fim_experiencia = NULL, indicacao_pagamento_realizado = 0, indicacao_pagamento_status = ?, indicacao_data_pagamento = NULL, indicacao_metodo_pagamento = NULL WHERE id = ?';
            } else {
                $sql = 'UPDATE candidaturas SET indicacao_colaborador = 0, indicacao_data_contratacao = NULL, indicacao_data_fim_experiencia = NULL, indicacao_pagamento_realizado = 0, indicacao_pagamento_status = ?, indicacao_data_pagamento = NULL WHERE id = ?';
            }
            $stmt = $pdo->prepare($sql);
            return $stmt->execute(['pendente', $id]);
        }
        $nome = trim((string)$colaboradorNome);
        $nomeAnterior = trim((string)($candidatura['indicacao_colaborador_nome'] ?? ''));
        if ($nome === '') {
            $nome = $nomeAnterior !== '' ? $nomeAnterior : 'Não informado';
        }
        if ($hasNomeIndicador) {
            $sql = 'UPDATE candidaturas SET indicacao_colaborador = 1, indicacao_colaborador_nome = ?, indicacao_pagamento_status = COALESCE(NULLIF(indicacao_pagamento_status, \'\'), ?) WHERE id = ?';
            $stmt = $pdo->prepare($sql);
            $ok = $stmt->execute([$nome, 'pendente', $id]);
        } else {
            $sql = 'UPDATE candidaturas SET indicacao_colaborador = 1, indicacao_pagamento_status = COALESCE(NULLIF(indicacao_pagamento_status, \'\'), ?) WHERE id = ?';
            $stmt = $pdo->prepare($sql);
            $ok = $stmt->execute(['pendente', $id]);
        }
        if (!$ok) {
            return false;
        }
        $stageName = (string)($candidatura['stage_nome'] ?? '');
        $normalized = trim(mb_strtolower($stageName, 'UTF-8'));
        $normalized = strtr($normalized, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c']);
        if ($normalized === 'contratado') {
            $stmt = $pdo->prepare('UPDATE candidaturas SET indicacao_data_contratacao = COALESCE(indicacao_data_contratacao, NOW()), indicacao_data_fim_experiencia = COALESCE(indicacao_data_fim_experiencia, DATE_ADD(CURDATE(), INTERVAL 90 DAY)) WHERE id = ?');
            $stmt->execute([$id]);
        }
        return true;
    }

    public static function markIndicacaoPagamento(int $id, string $paymentDateBr, ?int $actorUserId = null, ?string $paymentMethod = null): array
    {
        self::ensureIndicacaoColumns();
        self::ensureIndicacaoPaymentAuditTable();
        $cand = self::find($id);
        if (!$cand || (int)($cand['indicacao_colaborador'] ?? 0) !== 1) {
            return ['ok' => false, 'error' => 'Candidatura indicada não encontrada.'];
        }
        $iso = self::parseBrDate($paymentDateBr);
        if ($iso === null) {
            return ['ok' => false, 'error' => 'Data de pagamento inválida.'];
        }
        $validation = self::validatePaymentDate($iso, $cand);
        if (!($validation['ok'] ?? false)) {
            return $validation;
        }
        $method = trim(Security::sanitizeString((string)$paymentMethod));
        if ($method === '') {
            $method = 'Não informado';
        }
        $sql = 'UPDATE candidaturas SET indicacao_pagamento_realizado = 1, indicacao_pagamento_status = ?, indicacao_data_pagamento = ?, indicacao_metodo_pagamento = ?, indicacao_pagamento_registrado_em = NOW() WHERE id = ? AND indicacao_colaborador = 1 AND indicacao_pagamento_realizado = 0';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute(['pago', $iso, $method, $id]);
        if ($stmt->rowCount() < 1) {
            return ['ok' => false, 'error' => 'Pagamento já registrado por outro usuário.'];
        }
        self::addIndicacaoPaymentAudit($id, null, $iso, 'Registro inicial do pagamento', $actorUserId);
        return ['ok' => true];
    }

    public static function updateIndicacaoPaymentDate(int $id, string $newDateBr, string $reason, int $actorUserId): array
    {
        self::ensureIndicacaoColumns();
        self::ensureIndicacaoPaymentAuditTable();
        $cand = self::find($id);
        if (!$cand || (int)($cand['indicacao_colaborador'] ?? 0) !== 1) {
            return ['ok' => false, 'error' => 'Candidatura indicada não encontrada.'];
        }
        if ((int)($cand['indicacao_pagamento_realizado'] ?? 0) !== 1 || empty($cand['indicacao_data_pagamento'])) {
            return ['ok' => false, 'error' => 'Não há pagamento registrado para edição.'];
        }
        $reasonSanitized = trim(Security::sanitizeString($reason));
        if ($reasonSanitized === '') {
            return ['ok' => false, 'error' => 'Informe o motivo da alteração da data.'];
        }
        $iso = self::parseBrDate($newDateBr);
        if ($iso === null) {
            return ['ok' => false, 'error' => 'Data de pagamento inválida.'];
        }
        $validation = self::validatePaymentDate($iso, $cand);
        if (!$validation['ok']) {
            return $validation;
        }
        $oldDate = (string)($cand['indicacao_data_pagamento'] ?? '');
        if ($oldDate === $iso) {
            return ['ok' => false, 'error' => 'A nova data é igual à data atual.'];
        }
        $sql = 'UPDATE candidaturas SET indicacao_data_pagamento = ?, indicacao_pagamento_registrado_em = NOW(), indicacao_pagamento_realizado = 1, indicacao_pagamento_status = ? WHERE id = ? AND indicacao_colaborador = 1 AND indicacao_data_pagamento = ?';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute([$iso, 'pago', $id, $oldDate]);
        if ($stmt->rowCount() < 1) {
            return ['ok' => false, 'error' => 'Data já alterada por outro usuário. Recarregue a página e tente novamente.'];
        }
        self::addIndicacaoPaymentAudit($id, $oldDate, $iso, $reasonSanitized, $actorUserId);
        return ['ok' => true, 'old_date' => $oldDate, 'new_date' => $iso];
    }

    public static function paginateIndicacoes(array $filters = [], int $page = 1, int $perPage = 15): array
    {
        self::ensureStageColumn();
        self::ensureIndicacaoColumns();
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $where = ['c.indicacao_colaborador = 1'];
        $params = [];

        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(c.nome LIKE ? OR v.titulo LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $payment = self::normalizePaymentStatus((string)($filters['pagamento'] ?? ''));
        if ($payment !== '') {
            $where[] = 'LOWER(CASE WHEN c.indicacao_data_pagamento IS NOT NULL THEN \'pago\' WHEN c.indicacao_pagamento_status IS NOT NULL AND c.indicacao_pagamento_status <> \'\' THEN c.indicacao_pagamento_status WHEN c.indicacao_pagamento_realizado = 1 THEN \'pago\' ELSE \'pendente\' END) = ?';
            $params[] = $payment;
        }

        $exp = trim((string)($filters['experiencia'] ?? ''));
        if ($exp === 'em_experiencia') {
            $where[] = 'c.indicacao_data_contratacao IS NOT NULL AND CURDATE() <= c.indicacao_data_fim_experiencia';
        } elseif ($exp === 'concluida') {
            $where[] = 'c.indicacao_data_contratacao IS NOT NULL AND CURDATE() > c.indicacao_data_fim_experiencia';
        } elseif ($exp === 'nao_contratado') {
            $where[] = 'c.indicacao_data_contratacao IS NULL';
        }

        $indicador = trim((string)($filters['indicador'] ?? ''));
        if ($indicador !== '') {
            $where[] = 'c.indicacao_colaborador_nome LIKE ?';
            $params[] = '%' . $indicador . '%';
        }

        $dataDe = trim((string)($filters['data_de'] ?? ''));
        if ($dataDe !== '') {
            $where[] = 'DATE(c.created_at) >= ?';
            $params[] = $dataDe;
        }
        $dataAte = trim((string)($filters['data_ate'] ?? ''));
        if ($dataAte !== '') {
            $where[] = 'DATE(c.created_at) <= ?';
            $params[] = $dataAte;
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);
        $countSql = 'SELECT COUNT(*) FROM candidaturas c LEFT JOIN vagas v ON v.id = c.vaga_id LEFT JOIN pipeline_stages s ON s.id = c.stage_id' . $whereSql;
        $countStmt = Database::conn()->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $pages = max(1, (int)ceil($total / $perPage));
        if ($page > $pages) {
            $page = $pages;
            $offset = ($page - 1) * $perPage;
        }

        $sql = 'SELECT c.*, v.titulo AS vaga_titulo, s.nome AS stage_nome, s.cor AS stage_cor, CASE WHEN c.indicacao_data_contratacao IS NOT NULL THEN DATEDIFF(CURDATE(), DATE(c.indicacao_data_contratacao)) ELSE NULL END AS dias_desde_contratacao FROM candidaturas c LEFT JOIN vagas v ON v.id = c.vaga_id LEFT JOIN pipeline_stages s ON s.id = c.stage_id'
            . $whereSql
            . ' ORDER BY c.created_at DESC LIMIT ? OFFSET ?';
        $stmt = Database::conn()->prepare($sql);
        $queryParams = $params;
        $queryParams[] = $perPage;
        $queryParams[] = $offset;
        $stmt->execute($queryParams);
        return [
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages
        ];
    }

    public static function financialIndicacoesDataset(array $filters = []): array
    {
        self::ensureStageColumn();
        self::ensureIndicacaoColumns();
        $where = ['c.indicacao_colaborador = 1'];
        $params = [];
        $payment = trim((string)($filters['pagamento'] ?? ''));
        if ($payment === 'pendente') {
            $where[] = 'c.indicacao_pagamento_realizado = 0';
        } elseif ($payment === 'pago') {
            $where[] = 'c.indicacao_pagamento_realizado = 1';
        }
        $whereSql = ' WHERE ' . implode(' AND ', $where);
        $sql = 'SELECT c.id, c.nome, c.indicacao_colaborador_nome, c.indicacao_pagamento_realizado, c.indicacao_data_pagamento, c.indicacao_pagamento_registrado_em, c.indicacao_data_contratacao, c.indicacao_data_fim_experiencia, v.titulo AS vaga_titulo FROM candidaturas c LEFT JOIN vagas v ON v.id = c.vaga_id' . $whereSql . ' ORDER BY c.created_at DESC';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function reportIndicacoes(array $filters = []): array
    {
        self::ensureStageColumn();
        self::ensureIndicacaoColumns();
        $where = ['c.indicacao_colaborador = 1'];
        $params = [];

        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(c.nome LIKE ? OR v.titulo LIKE ? OR c.indicacao_colaborador_nome LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $pagamento = self::normalizePaymentStatus((string)($filters['pagamento'] ?? ''));
        if ($pagamento !== '') {
            $where[] = 'LOWER(CASE WHEN c.indicacao_data_pagamento IS NOT NULL THEN \'pago\' WHEN c.indicacao_pagamento_status IS NOT NULL AND c.indicacao_pagamento_status <> \'\' THEN c.indicacao_pagamento_status WHEN c.indicacao_pagamento_realizado = 1 THEN \'pago\' ELSE \'pendente\' END) = ?';
            $params[] = $pagamento;
        }

        $exp = trim((string)($filters['experiencia'] ?? ''));
        if ($exp === 'em_experiencia') {
            $where[] = 'c.indicacao_data_contratacao IS NOT NULL AND CURDATE() <= c.indicacao_data_fim_experiencia';
        } elseif ($exp === 'concluida') {
            $where[] = 'c.indicacao_data_contratacao IS NOT NULL AND CURDATE() > c.indicacao_data_fim_experiencia';
        } elseif ($exp === 'nao_contratado') {
            $where[] = 'c.indicacao_data_contratacao IS NULL';
        }

        $indicador = trim((string)($filters['indicador'] ?? ''));
        if ($indicador !== '') {
            $where[] = 'c.indicacao_colaborador_nome LIKE ?';
            $params[] = '%' . $indicador . '%';
        }

        $dataDe = trim((string)($filters['data_de'] ?? ''));
        if ($dataDe !== '') {
            $where[] = 'DATE(c.created_at) >= ?';
            $params[] = $dataDe;
        }
        $dataAte = trim((string)($filters['data_ate'] ?? ''));
        if ($dataAte !== '') {
            $where[] = 'DATE(c.created_at) <= ?';
            $params[] = $dataAte;
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);
        $sql = 'SELECT c.*, v.titulo AS vaga_titulo, s.nome AS stage_nome, s.cor AS stage_cor FROM candidaturas c LEFT JOIN vagas v ON v.id = c.vaga_id LEFT JOIN pipeline_stages s ON s.id = c.stage_id'
            . $whereSql
            . ' ORDER BY c.created_at DESC';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function reportTotals(array $rows): array
    {
        $total = count($rows);
        $status = ['pendente' => 0, 'pago' => 0, 'cancelado' => 0, 'em processo' => 0];
        $contratados = 0;
        foreach ($rows as $row) {
            $s = self::normalizePaymentStatus((string)($row['indicacao_pagamento_status'] ?? ''));
            if ($s === '') {
                $s = (int)($row['indicacao_pagamento_realizado'] ?? 0) === 1 ? 'pago' : 'pendente';
            }
            if (!isset($status[$s])) {
                $status[$s] = 0;
            }
            $status[$s]++;
            $stage = self::normalizePlain((string)($row['stage_nome'] ?? ''));
            if ($stage === 'contratado') {
                $contratados++;
            }
        }
        $conv = $total > 0 ? round(($contratados / $total) * 100, 2) : 0.0;
        return [
            'total' => $total,
            'status' => $status,
            'contratados' => $contratados,
            'conversao_percentual' => $conv
        ];
    }

    private static function ensureIndicacaoColumns(): void
    {
        try {
            $db = Database::conn();
            $columns = [
                'indicacao_colaborador' => 'ALTER TABLE candidaturas ADD COLUMN indicacao_colaborador TINYINT(1) NOT NULL DEFAULT 0',
                'indicacao_colaborador_nome' => 'ALTER TABLE candidaturas ADD COLUMN indicacao_colaborador_nome VARCHAR(120) NULL',
                'indicacao_data_contratacao' => 'ALTER TABLE candidaturas ADD COLUMN indicacao_data_contratacao DATETIME NULL',
                'indicacao_data_fim_experiencia' => 'ALTER TABLE candidaturas ADD COLUMN indicacao_data_fim_experiencia DATE NULL',
                'indicacao_pagamento_realizado' => 'ALTER TABLE candidaturas ADD COLUMN indicacao_pagamento_realizado TINYINT(1) NOT NULL DEFAULT 0',
                'indicacao_data_pagamento' => 'ALTER TABLE candidaturas ADD COLUMN indicacao_data_pagamento DATE NULL',
                'indicacao_pagamento_registrado_em' => 'ALTER TABLE candidaturas ADD COLUMN indicacao_pagamento_registrado_em DATETIME NULL',
                'indicacao_valor_comissao' => 'ALTER TABLE candidaturas ADD COLUMN indicacao_valor_comissao DECIMAL(10,2) NULL',
                'indicacao_metodo_pagamento' => 'ALTER TABLE candidaturas ADD COLUMN indicacao_metodo_pagamento VARCHAR(50) NULL',
                'indicacao_pagamento_status' => 'ALTER TABLE candidaturas ADD COLUMN indicacao_pagamento_status VARCHAR(20) NOT NULL DEFAULT \'pendente\''
            ];
            foreach ($columns as $column => $ddl) {
                $check = $db->prepare('SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
                $check->execute(['candidaturas', $column]);
                $exists = (int)($check->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0) > 0;
                if (!$exists) {
                    $db->exec($ddl);
                }
            }
        } catch (\Throwable $e) {
        }
    }

    private static function ensureIndicacaoPaymentAuditTable(): void
    {
        try {
            $db = Database::conn();
            $check = $db->prepare('SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $check->execute(['indicacao_pagamento_auditoria']);
            $exists = (int)($check->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0) > 0;
            if (!$exists) {
                $db->exec('CREATE TABLE indicacao_pagamento_auditoria (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    candidatura_id INT NOT NULL,
                    data_anterior DATE NULL,
                    data_nova DATE NOT NULL,
                    motivo VARCHAR(255) NOT NULL,
                    usuario_id INT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_ind_pag_cand FOREIGN KEY (candidatura_id) REFERENCES candidaturas(id) ON DELETE CASCADE,
                    CONSTRAINT fk_ind_pag_user FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            }
        } catch (\Throwable $e) {
        }
    }

    public static function paymentSignal(array $cand, ?DateTimeImmutable $today = null): array
    {
        $todayObj = $today ?? new DateTimeImmutable('today');
        $pago = (int)($cand['indicacao_pagamento_realizado'] ?? 0) === 1;
        if ($pago) {
            return ['color' => 'ctgreen', 'label' => 'Pago', 'dot' => 'bg-ctgreen', 'text' => 'text-ctgreen'];
        }
        $fimExpRaw = trim((string)($cand['indicacao_data_fim_experiencia'] ?? ''));
        if ($fimExpRaw === '') {
            return ['color' => 'ctlight', 'label' => 'Pendente', 'dot' => 'bg-ctlight', 'text' => 'text-ctlight'];
        }
        $fimExp = DateTimeImmutable::createFromFormat('Y-m-d', $fimExpRaw);
        if (!$fimExp) {
            return ['color' => 'ctlight', 'label' => 'Pendente', 'dot' => 'bg-ctlight', 'text' => 'text-ctlight'];
        }
        if ($todayObj <= $fimExp) {
            return ['color' => 'ctlight', 'label' => 'Pendente', 'dot' => 'bg-ctlight', 'text' => 'text-ctlight'];
        }
        return ['color' => 'ctdark', 'label' => 'Pendente', 'dot' => 'bg-ctdark', 'text' => 'text-ctdark'];
    }

    private static function parseBrDate(string $value): ?string
    {
        $raw = trim($value);
        if (!preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $raw)) {
            return null;
        }
        [$d, $m, $y] = explode('/', $raw);
        if (!checkdate((int)$m, (int)$d, (int)$y)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', (int)$y, (int)$m, (int)$d);
    }

    private static function normalizePaymentStatus(string $value): string
    {
        $status = self::normalizePlain($value);
        if ($status === 'em_processo' || $status === 'em processo') {
            return 'em processo';
        }
        if (in_array($status, ['pendente', 'pago', 'cancelado'], true)) {
            return $status;
        }
        return '';
    }

    private static function normalizePlain(string $value): string
    {
        $clean = trim(mb_strtolower($value, 'UTF-8'));
        $replace = ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c'];
        $clean = strtr($clean, $replace);
        return preg_replace('/\s+/', ' ', $clean) ?? $clean;
    }

    private static function validatePaymentDate(string $isoDate, array $cand): array
    {
        $today = date('Y-m-d');
        if ($isoDate > $today) {
            return ['ok' => false, 'error' => 'A data de pagamento não pode ser futura.'];
        }
        if ($isoDate < date('Y-m-d', strtotime('-90 days'))) {
            return ['ok' => false, 'error' => 'A data de pagamento não pode ser superior a 90 dias no passado.'];
        }
        if (!empty($cand['indicacao_data_contratacao'])) {
            $admissao = date('Y-m-d', strtotime((string)$cand['indicacao_data_contratacao']));
            if ($isoDate < $admissao) {
                return ['ok' => false, 'error' => 'A data de pagamento não pode ser anterior à data de contratação.'];
            }
        }
        return ['ok' => true];
    }

    private static function addIndicacaoPaymentAudit(int $candidaturaId, ?string $oldDate, string $newDate, string $reason, ?int $actorUserId): void
    {
        if (($actorUserId ?? 0) <= 0) {
            return;
        }
        try {
            $sql = 'INSERT INTO indicacao_pagamento_auditoria (candidatura_id, data_anterior, data_nova, motivo, usuario_id) VALUES (?, ?, ?, ?, ?)';
            $stmt = Database::conn()->prepare($sql);
            $stmt->execute([$candidaturaId, $oldDate !== '' ? $oldDate : null, $newDate, $reason, $actorUserId]);
        } catch (\Throwable $e) {
        }
    }

    private static function hasColumn(string $table, string $column): bool
    {
        try {
            $db = Database::conn();
            $check = $db->prepare('SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $check->execute([$table, $column]);
            return (int)($check->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
