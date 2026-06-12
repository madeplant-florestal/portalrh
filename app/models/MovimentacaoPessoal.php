<?php
class MovimentacaoPessoal
{
    private const STATUS_RASCUNHO = 'rascunho';
    private const STATUS_PENDENTE_RH = 'pendente_rh';
    private const STATUS_APROVADA = 'aprovada';

    public static function ensureSchema(): void
    {
        if (class_exists('SolicitacaoVaga')) {
            SolicitacaoVaga::ensureSchema();
        }

        $pdo = Database::conn();

        self::addColumnIfMissing('colaboradores', 'matricula', "ALTER TABLE colaboradores ADD COLUMN matricula VARCHAR(30) NULL AFTER nome");
        self::addColumnIfMissing('colaboradores', 'salario_atual', "ALTER TABLE colaboradores ADD COLUMN salario_atual DECIMAL(12,2) NULL AFTER setor_id");
        self::addColumnIfMissing('colaboradores', 'data_admissao', "ALTER TABLE colaboradores ADD COLUMN data_admissao DATE NULL AFTER salario_atual");
        self::addColumnIfMissing('colaboradores', 'data_inicio_cargo', "ALTER TABLE colaboradores ADD COLUMN data_inicio_cargo DATE NULL AFTER data_admissao");

        $pdo->exec("CREATE TABLE IF NOT EXISTS colaborador_avaliacoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            colaborador_id INT NOT NULL,
            titulo VARCHAR(160) NOT NULL,
            nota DECIMAL(5,2) NULL,
            periodo_referencia VARCHAR(80) NULL,
            resumo TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_colaborador_avaliacoes_colaborador (colaborador_id),
            CONSTRAINT fk_colaborador_avaliacoes_colaborador FOREIGN KEY (colaborador_id) REFERENCES colaboradores(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS movimentacoes_pessoal (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tipo_movimentacao ENUM('merito','promocao','transferencia','alteracao_funcao') NOT NULL,
            data_solicitacao DATE NOT NULL,
            gestor_solicitante_usuario_id INT NOT NULL,
            gestor_solicitante_colaborador_id INT NULL,
            setor_id INT NOT NULL,
            colaborador_id INT NOT NULL,
            matricula_snapshot VARCHAR(30) NOT NULL,
            cargo_atual_id INT NOT NULL,
            tempo_cargo_meses INT NOT NULL DEFAULT 0,
            tempo_empresa_meses INT NOT NULL DEFAULT 0,
            salario_atual_snapshot DECIMAL(12,2) NOT NULL DEFAULT 0,
            novo_cargo_id INT NULL,
            nova_area_setor_id INT NULL,
            novo_salario DECIMAL(12,2) NULL,
            percentual_aumento DECIMAL(9,2) NULL,
            data_prevista_mudanca DATE NULL,
            justificativa_encrypted MEDIUMTEXT NULL,
            entregas_ultimos_6_meses_encrypted MEDIUMTEXT NULL,
            resultados_atingidos_encrypted MEDIUMTEXT NULL,
            avaliacao_desempenho_id INT NULL,
            pronto_proximo_nivel_encrypted MEDIUMTEXT NULL,
            competencias_tecnicas_encrypted MEDIUMTEXT NULL,
            competencias_comportamentais_encrypted MEDIUMTEXT NULL,
            pontos_desenvolvimento_encrypted MEDIUMTEXT NULL,
            aumento_mensal DECIMAL(12,2) NULL,
            impacto_anual DECIMAL(12,2) NULL,
            existe_orcamento_aprovado ENUM('sim','nao','em_validacao') NULL,
            posicao_atual_sera ENUM('extinta','substituida') NULL,
            existe_candidato_interno TINYINT(1) NULL,
            necessita_recrutamento_externo TINYINT(1) NULL,
            existe_risco_perda TINYINT(1) NULL,
            impacto_nao_aprovado_encrypted MEDIUMTEXT NULL,
            status_fluxo ENUM('rascunho','pendente_rh','aprovada') NOT NULL DEFAULT 'rascunho',
            gestor_assinatura_hash VARCHAR(64) NULL,
            gestor_assinado_em DATETIME NULL,
            gestor_assinado_por_usuario_id INT NULL,
            rh_assinatura_hash VARCHAR(64) NULL,
            rh_assinado_em DATETIME NULL,
            rh_assinado_por_usuario_id INT NULL,
            data_decisao DATE NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_movimentacoes_status (status_fluxo),
            KEY idx_movimentacoes_gestor (gestor_solicitante_usuario_id),
            KEY idx_movimentacoes_colaborador (colaborador_id),
            CONSTRAINT fk_movimentacoes_gestor_usuario FOREIGN KEY (gestor_solicitante_usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
            CONSTRAINT fk_movimentacoes_gestor_colaborador FOREIGN KEY (gestor_solicitante_colaborador_id) REFERENCES colaboradores(id) ON DELETE SET NULL,
            CONSTRAINT fk_movimentacoes_setor FOREIGN KEY (setor_id) REFERENCES setores(id) ON DELETE RESTRICT,
            CONSTRAINT fk_movimentacoes_colaborador FOREIGN KEY (colaborador_id) REFERENCES colaboradores(id) ON DELETE RESTRICT,
            CONSTRAINT fk_movimentacoes_cargo_atual FOREIGN KEY (cargo_atual_id) REFERENCES cargos(id) ON DELETE RESTRICT,
            CONSTRAINT fk_movimentacoes_novo_cargo FOREIGN KEY (novo_cargo_id) REFERENCES cargos(id) ON DELETE SET NULL,
            CONSTRAINT fk_movimentacoes_nova_area FOREIGN KEY (nova_area_setor_id) REFERENCES setores(id) ON DELETE SET NULL,
            CONSTRAINT fk_movimentacoes_avaliacao FOREIGN KEY (avaliacao_desempenho_id) REFERENCES colaborador_avaliacoes(id) ON DELETE SET NULL,
            CONSTRAINT fk_movimentacoes_gestor_assinante FOREIGN KEY (gestor_assinado_por_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
            CONSTRAINT fk_movimentacoes_rh_assinante FOREIGN KEY (rh_assinado_por_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS movimentacoes_pessoal_auditoria (
            id INT AUTO_INCREMENT PRIMARY KEY,
            movimentacao_id INT NOT NULL,
            actor_usuario_id INT NULL,
            event_type VARCHAR(60) NOT NULL,
            field_name VARCHAR(80) NULL,
            old_value_encrypted TEXT NULL,
            new_value_encrypted TEXT NULL,
            ip VARCHAR(45) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_movimentacao_auditoria_event (event_type),
            CONSTRAINT fk_movimentacao_auditoria_movimentacao FOREIGN KEY (movimentacao_id) REFERENCES movimentacoes_pessoal(id) ON DELETE CASCADE,
            CONSTRAINT fk_movimentacao_auditoria_actor FOREIGN KEY (actor_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        self::seedReferenceData($pdo);
    }

    public static function formDependencies(?int $currentUserId = null): array
    {
        self::ensureSchema();
        $pdo = Database::conn();

        $setores = $pdo->query("SELECT id, nome FROM setores WHERE ativo = 1 ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
        $cargos = $pdo->query("SELECT c.id, c.nome, COALESCE(fs.salario_min, 0) AS salario_min, COALESCE(fs.salario_max, 0) AS salario_max
                               FROM cargos c
                               LEFT JOIN cargo_faixas_salariais fs ON fs.cargo_id = c.id AND fs.ativo = 1
                               WHERE c.ativo = 1
                               ORDER BY c.nome ASC")->fetchAll(PDO::FETCH_ASSOC);
        $gestores = $pdo->query("SELECT uc.usuario_id, uc.colaborador_id, uc.is_gestor, uc.is_rh,
                                        c.nome AS colaborador_nome, c.setor_id, c.cargo_id, c.matricula,
                                        cg.nome AS cargo_nome, u.nome AS usuario_nome, u.email, u.role AS usuario_role
                                 FROM usuario_colaboradores uc
                                 INNER JOIN colaboradores c ON c.id = uc.colaborador_id
                                 INNER JOIN cargos cg ON cg.id = c.cargo_id
                                 INNER JOIN usuarios u ON u.id = uc.usuario_id
                                 WHERE uc.ativo = 1 AND uc.is_gestor = 1 AND c.ativo = 1
                                 ORDER BY c.nome ASC")->fetchAll(PDO::FETCH_ASSOC);
        $colaboradores = $pdo->query("SELECT c.id, c.nome, c.matricula, c.salario_atual, c.data_admissao, c.data_inicio_cargo,
                                             c.ativo, c.setor_id, c.cargo_id, cg.nome AS cargo_nome, s.nome AS setor_nome, e.nome AS empresa_nome
                                      FROM colaboradores c
                                      INNER JOIN cargos cg ON cg.id = c.cargo_id
                                      LEFT JOIN setores s ON s.id = c.setor_id
                                      LEFT JOIN empresas e ON e.id = c.empresa_id
                                      WHERE c.ativo = 1
                                      ORDER BY c.nome ASC")->fetchAll(PDO::FETCH_ASSOC);
        $avaliacoes = $pdo->query("SELECT id, colaborador_id, titulo, nota, periodo_referencia, created_at
                                   FROM colaborador_avaliacoes
                                   ORDER BY created_at DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);

        $avaliacoesByColaborador = [];
        foreach ($avaliacoes as $avaliacao) {
            $avaliacoesByColaborador[(int)$avaliacao['colaborador_id']][] = [
                'id' => (int)$avaliacao['id'],
                'titulo' => $avaliacao['titulo'],
                'nota' => $avaliacao['nota'] !== null ? (float)$avaliacao['nota'] : null,
                'periodo_referencia' => $avaliacao['periodo_referencia'],
                'created_at' => $avaliacao['created_at'],
            ];
        }

        return [
            'setores' => array_map(static fn(array $row): array => ['id' => (int)$row['id'], 'nome' => $row['nome']], $setores),
            'cargos' => array_map(static fn(array $row): array => [
                'id' => (int)$row['id'],
                'nome' => $row['nome'],
                'salario_min' => (float)$row['salario_min'],
                'salario_max' => (float)$row['salario_max'],
            ], $cargos),
            'gestores' => array_map(static fn(array $row): array => [
                'usuario_id' => (int)$row['usuario_id'],
                'colaborador_id' => (int)$row['colaborador_id'],
                'nome' => $row['colaborador_nome'],
                'usuario_nome' => $row['usuario_nome'],
                'email' => $row['email'],
                'setor_id' => $row['setor_id'] !== null ? (int)$row['setor_id'] : null,
                'cargo_id' => (int)$row['cargo_id'],
                'cargo_nome' => $row['cargo_nome'],
                'matricula' => $row['matricula'],
                'usuario_role' => $row['usuario_role'],
            ], $gestores),
            'colaboradores' => array_map(function (array $row) use ($avaliacoesByColaborador): array {
                $tempoEmpresa = self::monthsSince($row['data_admissao'] ?? null);
                $tempoCargo = self::monthsSince($row['data_inicio_cargo'] ?? null);
                return [
                    'id' => (int)$row['id'],
                    'nome' => $row['nome'],
                    'matricula' => $row['matricula'],
                    'salario_atual' => (float)($row['salario_atual'] ?? 0),
                    'data_admissao' => $row['data_admissao'],
                    'data_inicio_cargo' => $row['data_inicio_cargo'],
                    'tempo_empresa_meses' => $tempoEmpresa,
                    'tempo_cargo_meses' => $tempoCargo,
                    'tempo_empresa_label' => self::formatMonthsLabel($tempoEmpresa),
                    'tempo_cargo_label' => self::formatMonthsLabel($tempoCargo),
                    'setor_id' => $row['setor_id'] !== null ? (int)$row['setor_id'] : null,
                    'cargo_id' => (int)$row['cargo_id'],
                    'cargo_nome' => $row['cargo_nome'],
                    'setor_nome' => $row['setor_nome'],
                    'empresa_nome' => $row['empresa_nome'],
                    'avaliacoes' => $avaliacoesByColaborador[(int)$row['id']] ?? [],
                ];
            }, $colaboradores),
            'current_access' => $currentUserId ? self::userAccessProfile($currentUserId) : null,
        ];
    }

    public static function listForUser(?int $currentUserId, ?string $role, bool $isSupervisor): array
    {
        self::ensureSchema();
        $pdo = Database::conn();
        $sql = "SELECT mp.id, mp.tipo_movimentacao, mp.data_solicitacao, mp.data_prevista_mudanca, mp.status_fluxo,
                       mp.salario_atual_snapshot, mp.novo_salario, mp.data_decisao, mp.created_at,
                       g.nome AS gestor_nome, c.nome AS colaborador_nome, ca.nome AS cargo_atual_nome,
                       nc.nome AS novo_cargo_nome, s.nome AS setor_nome, ns.nome AS nova_area_nome
                FROM movimentacoes_pessoal mp
                INNER JOIN usuarios gu ON gu.id = mp.gestor_solicitante_usuario_id
                LEFT JOIN colaboradores g ON g.id = mp.gestor_solicitante_colaborador_id
                INNER JOIN colaboradores c ON c.id = mp.colaborador_id
                INNER JOIN cargos ca ON ca.id = mp.cargo_atual_id
                LEFT JOIN cargos nc ON nc.id = mp.novo_cargo_id
                INNER JOIN setores s ON s.id = mp.setor_id
                LEFT JOIN setores ns ON ns.id = mp.nova_area_setor_id
                WHERE 1=1";
        $params = [];
        $role = strtolower(trim((string)$role));
        if (!$isSupervisor && !in_array($role, ['admin', 'rh'], true)) {
            $sql .= " AND mp.gestor_solicitante_usuario_id = ?";
            $params[] = (int)$currentUserId;
        }
        $sql .= " ORDER BY mp.created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function findAccessible(int $id, ?int $currentUserId, ?string $role, bool $isSupervisor): ?array
    {
        self::ensureSchema();
        $pdo = Database::conn();
        $sql = "SELECT mp.*, u.nome AS gestor_usuario_nome, u.email AS gestor_email,
                       g.nome AS gestor_colaborador_nome, c.nome AS colaborador_nome,
                       ca.nome AS cargo_atual_nome, nc.nome AS novo_cargo_nome,
                       s.nome AS setor_nome, ns.nome AS nova_area_nome,
                       rh.nome AS rh_assinante_nome, ga.nome AS gestor_assinante_nome,
                       av.titulo AS avaliacao_titulo, av.nota AS avaliacao_nota, av.periodo_referencia
                FROM movimentacoes_pessoal mp
                INNER JOIN usuarios u ON u.id = mp.gestor_solicitante_usuario_id
                LEFT JOIN colaboradores g ON g.id = mp.gestor_solicitante_colaborador_id
                INNER JOIN colaboradores c ON c.id = mp.colaborador_id
                INNER JOIN cargos ca ON ca.id = mp.cargo_atual_id
                LEFT JOIN cargos nc ON nc.id = mp.novo_cargo_id
                INNER JOIN setores s ON s.id = mp.setor_id
                LEFT JOIN setores ns ON ns.id = mp.nova_area_setor_id
                LEFT JOIN usuarios rh ON rh.id = mp.rh_assinado_por_usuario_id
                LEFT JOIN usuarios ga ON ga.id = mp.gestor_assinado_por_usuario_id
                LEFT JOIN colaborador_avaliacoes av ON av.id = mp.avaliacao_desempenho_id
                WHERE mp.id = ?";
        $params = [$id];
        $role = strtolower(trim((string)$role));
        if (!$isSupervisor && !in_array($role, ['admin', 'rh'], true)) {
            $sql .= " AND mp.gestor_solicitante_usuario_id = ?";
            $params[] = (int)$currentUserId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row = self::hydrateRecord($row);
        $row['auditoria'] = self::auditTrail((int)$row['id']);
        return $row;
    }

    public static function saveDraft(array $input, int $actorUserId, ?int $id = null, ?string $ip = null): array
    {
        self::ensureSchema();
        $normalized = self::normalizeDraftInput($input, $actorUserId);
        $existing = $id ? self::findRaw($id) : null;
        if ($existing && (string)$existing['status_fluxo'] !== self::STATUS_RASCUNHO) {
            return ['ok' => false, 'error' => 'A movimentação não pode mais ser alterada porque já saiu do status de rascunho.'];
        }
        if ($existing && !self::canEditDraft($existing, $actorUserId)) {
            return ['ok' => false, 'error' => 'Você não possui permissão para editar este rascunho.'];
        }

        $pdo = Database::conn();
        $pdo->beginTransaction();
        try {
            if ($existing) {
                $stmt = $pdo->prepare("UPDATE movimentacoes_pessoal SET
                    tipo_movimentacao = ?, data_solicitacao = ?, gestor_solicitante_usuario_id = ?, gestor_solicitante_colaborador_id = ?,
                    setor_id = ?, colaborador_id = ?, matricula_snapshot = ?, cargo_atual_id = ?, tempo_cargo_meses = ?, tempo_empresa_meses = ?,
                    salario_atual_snapshot = ?, novo_cargo_id = ?, nova_area_setor_id = ?, novo_salario = ?, percentual_aumento = ?,
                    data_prevista_mudanca = ?, justificativa_encrypted = ?, entregas_ultimos_6_meses_encrypted = ?, resultados_atingidos_encrypted = ?,
                    avaliacao_desempenho_id = ?, pronto_proximo_nivel_encrypted = ?, competencias_tecnicas_encrypted = ?, competencias_comportamentais_encrypted = ?,
                    pontos_desenvolvimento_encrypted = ?, aumento_mensal = ?, impacto_anual = ?, existe_orcamento_aprovado = ?, posicao_atual_sera = ?,
                    existe_candidato_interno = ?, necessita_recrutamento_externo = ?, existe_risco_perda = ?, impacto_nao_aprovado_encrypted = ?, status_fluxo = 'rascunho'
                    WHERE id = ?");
                $params = self::persistParams($normalized);
                $params[] = $id;
                $stmt->execute($params);
                self::logAudit((int)$id, $actorUserId, 'draft_saved', null, null, 'Rascunho atualizado', $ip);
                $savedId = (int)$id;
            } else {
                $stmt = $pdo->prepare("INSERT INTO movimentacoes_pessoal (
                    tipo_movimentacao, data_solicitacao, gestor_solicitante_usuario_id, gestor_solicitante_colaborador_id,
                    setor_id, colaborador_id, matricula_snapshot, cargo_atual_id, tempo_cargo_meses, tempo_empresa_meses,
                    salario_atual_snapshot, novo_cargo_id, nova_area_setor_id, novo_salario, percentual_aumento, data_prevista_mudanca,
                    justificativa_encrypted, entregas_ultimos_6_meses_encrypted, resultados_atingidos_encrypted, avaliacao_desempenho_id,
                    pronto_proximo_nivel_encrypted, competencias_tecnicas_encrypted, competencias_comportamentais_encrypted, pontos_desenvolvimento_encrypted,
                    aumento_mensal, impacto_anual, existe_orcamento_aprovado, posicao_atual_sera, existe_candidato_interno,
                    necessita_recrutamento_externo, existe_risco_perda, impacto_nao_aprovado_encrypted, status_fluxo
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'rascunho')");
                $stmt->execute(self::persistParams($normalized));
                $savedId = (int)$pdo->lastInsertId();
                self::logAudit($savedId, $actorUserId, 'draft_created', null, null, 'Rascunho criado', $ip);
            }
            $pdo->commit();

            self::notifyManagerIfNecessary($savedId, $normalized, $actorUserId);

            return ['ok' => true, 'id' => $savedId];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public static function signManager(array $input, int $actorUserId, bool $isSupervisor, ?int $id = null, ?string $ip = null): array
    {
        $draftResult = self::saveDraft($input, $actorUserId, $id, $ip);
        if (!($draftResult['ok'] ?? false)) {
            return $draftResult;
        }

        $movimentacaoId = (int)$draftResult['id'];
        $record = self::findRaw($movimentacaoId);
        if (!$record) {
            return ['ok' => false, 'error' => 'Rascunho de movimentação não encontrado após o salvamento.'];
        }

        $normalized = self::validateCompleteInput(self::hydrateRecord($record), $actorUserId);
        if (!$isSupervisor && (int)$record['gestor_solicitante_usuario_id'] !== $actorUserId) {
            return ['ok' => false, 'error' => 'A assinatura do gestor imediato é restrita ao gestor solicitante vinculado ao formulário.'];
        }

        $stmt = Database::conn()->prepare("UPDATE movimentacoes_pessoal
                                           SET status_fluxo = ?, gestor_assinatura_hash = ?, gestor_assinado_em = NOW(), gestor_assinado_por_usuario_id = ?
                                           WHERE id = ?");
        $stmt->execute([
            self::STATUS_PENDENTE_RH,
            self::signatureHash($movimentacaoId, 'gestor', $actorUserId),
            $actorUserId,
            $movimentacaoId,
        ]);

        self::logAudit($movimentacaoId, $actorUserId, 'manager_signed', 'status_fluxo', self::STATUS_RASCUNHO, self::STATUS_PENDENTE_RH, $ip);
        self::notifyRh($movimentacaoId, $normalized);

        return ['ok' => true, 'id' => $movimentacaoId];
    }

    public static function signRh(int $id, int $actorUserId, bool $canEditRh, ?string $ip = null): array
    {
        self::ensureSchema();
        if (!$canEditRh) {
            return ['ok' => false, 'error' => 'A assinatura do RH é restrita aos usuários com perfil RH ou administrador.'];
        }

        $record = self::findRaw($id);
        if (!$record) {
            return ['ok' => false, 'error' => 'Movimentação não encontrada.'];
        }
        if ((string)$record['status_fluxo'] !== self::STATUS_PENDENTE_RH || empty($record['gestor_assinado_em'])) {
            return ['ok' => false, 'error' => 'A movimentação precisa estar assinada pelo gestor e pendente de RH.'];
        }

        $stmt = Database::conn()->prepare("UPDATE movimentacoes_pessoal
                                           SET status_fluxo = ?, rh_assinatura_hash = ?, rh_assinado_em = NOW(), rh_assinado_por_usuario_id = ?, data_decisao = CURDATE()
                                           WHERE id = ?");
        $stmt->execute([
            self::STATUS_APROVADA,
            self::signatureHash($id, 'rh', $actorUserId),
            $actorUserId,
            $id,
        ]);

        self::logAudit($id, $actorUserId, 'rh_signed', 'status_fluxo', self::STATUS_PENDENTE_RH, self::STATUS_APROVADA, $ip);
        self::notifyManagerApproved($id);

        return ['ok' => true, 'id' => $id];
    }

    public static function userCanCreate(?string $role, bool $isSupervisor): bool
    {
        $role = strtolower(trim((string)$role));
        return $isSupervisor || in_array($role, ['admin', 'rh', 'viewer'], true);
    }

    public static function userCanEditRh(?string $role, bool $isSupervisor): bool
    {
        $role = strtolower(trim((string)$role));
        return $isSupervisor || in_array($role, ['admin', 'rh'], true);
    }

    private static function persistParams(array $normalized): array
    {
        return [
            $normalized['tipo_movimentacao'],
            $normalized['data_solicitacao'],
            $normalized['gestor_solicitante_usuario_id'],
            $normalized['gestor_solicitante_colaborador_id'],
            $normalized['setor_id'],
            $normalized['colaborador_id'],
            $normalized['matricula_snapshot'],
            $normalized['cargo_atual_id'],
            $normalized['tempo_cargo_meses'],
            $normalized['tempo_empresa_meses'],
            $normalized['salario_atual_snapshot'],
            $normalized['novo_cargo_id'],
            $normalized['nova_area_setor_id'],
            $normalized['novo_salario'],
            $normalized['percentual_aumento'],
            $normalized['data_prevista_mudanca'],
            self::encryptNullable($normalized['justificativa']),
            self::encryptNullable($normalized['entregas_ultimos_6_meses']),
            self::encryptNullable($normalized['resultados_atingidos']),
            $normalized['avaliacao_desempenho_id'],
            self::encryptNullable($normalized['pronto_proximo_nivel']),
            self::encryptNullable($normalized['competencias_tecnicas']),
            self::encryptNullable($normalized['competencias_comportamentais']),
            self::encryptNullable($normalized['pontos_desenvolvimento']),
            $normalized['aumento_mensal'],
            $normalized['impacto_anual'],
            $normalized['existe_orcamento_aprovado'],
            $normalized['posicao_atual_sera'],
            $normalized['existe_candidato_interno'],
            $normalized['necessita_recrutamento_externo'],
            $normalized['existe_risco_perda'],
            self::encryptNullable($normalized['impacto_nao_aprovado']),
        ];
    }

    private static function normalizeDraftInput(array $input, int $actorUserId): array
    {
        $dependencies = self::formDependencies($actorUserId);
        $currentAccess = $dependencies['current_access'] ?? null;

        $tipoMovimentacao = self::nullableEnum($input['tipo_movimentacao'] ?? '', ['merito', 'promocao', 'transferencia', 'alteracao_funcao']) ?? 'merito';
        $dataSolicitacao = DateHelper::toDatabaseDate($input['data_solicitacao'] ?? '') ?? date('Y-m-d');

        $gestorUsuarioId = self::nullableInt($input['gestor_solicitante_usuario_id'] ?? null)
            ?: (($currentAccess['usuario_id'] ?? null) ? (int)$currentAccess['usuario_id'] : $actorUserId);
        $gestorLink = self::userAccessProfile($gestorUsuarioId);
        $gestorColaboradorId = $gestorLink ? (int)$gestorLink['colaborador_id'] : null;

        $setorId = self::nullableInt($input['setor_id'] ?? null)
            ?: ($gestorLink && !empty($gestorLink['setor_id']) ? (int)$gestorLink['setor_id'] : null);
        $colaboradorId = self::nullableInt($input['colaborador_id'] ?? null);
        $colaborador = $colaboradorId ? self::findColaborador($colaboradorId) : null;
        $cargoAtualId = $colaborador ? (int)$colaborador['cargo_id'] : 0;
        $matricula = $colaborador['matricula'] ?? '';
        $salarioAtual = $colaborador ? (float)$colaborador['salario_atual'] : 0.0;
        $tempoEmpresa = $colaborador ? self::monthsSince($colaborador['data_admissao'] ?? null) : 0;
        $tempoCargo = $colaborador ? self::monthsSince($colaborador['data_inicio_cargo'] ?? null) : 0;

        $novoCargoId = self::nullableInt($input['novo_cargo_id'] ?? null);
        $novaAreaId = self::nullableInt($input['nova_area_setor_id'] ?? null);
        $novoSalario = self::parseMoney($input['novo_salario'] ?? '');
        $aumentoMensal = null;
        $impactoAnual = null;
        $percentualAumento = null;
        if ($novoSalario !== null && $salarioAtual > 0) {
            $aumentoMensal = round($novoSalario - $salarioAtual, 2);
            $impactoAnual = round($aumentoMensal * 12, 2);
            $percentualAumento = round(($aumentoMensal / $salarioAtual) * 100, 2);
        }

        $avaliacaoId = self::nullableInt($input['avaliacao_desempenho_id'] ?? null);
        if ($colaboradorId && !$avaliacaoId) {
            $avaliacoes = self::evaluationsForColaborador($colaboradorId);
            $avaliacaoId = !empty($avaliacoes) ? (int)$avaliacoes[0]['id'] : null;
        }

        return [
            'tipo_movimentacao' => $tipoMovimentacao,
            'data_solicitacao' => $dataSolicitacao,
            'gestor_solicitante_usuario_id' => $gestorUsuarioId,
            'gestor_solicitante_colaborador_id' => $gestorColaboradorId,
            'setor_id' => $setorId,
            'colaborador_id' => $colaboradorId,
            'matricula_snapshot' => $matricula,
            'cargo_atual_id' => $cargoAtualId,
            'tempo_cargo_meses' => $tempoCargo,
            'tempo_empresa_meses' => $tempoEmpresa,
            'salario_atual_snapshot' => $salarioAtual,
            'novo_cargo_id' => $novoCargoId,
            'nova_area_setor_id' => $novaAreaId,
            'novo_salario' => $novoSalario,
            'percentual_aumento' => $percentualAumento,
            'data_prevista_mudanca' => DateHelper::toDatabaseDate($input['data_prevista_mudanca'] ?? ''),
            'justificativa' => self::cleanText($input['justificativa'] ?? ''),
            'entregas_ultimos_6_meses' => self::cleanText($input['entregas_ultimos_6_meses'] ?? ''),
            'resultados_atingidos' => self::cleanText($input['resultados_atingidos'] ?? ''),
            'avaliacao_desempenho_id' => $avaliacaoId,
            'pronto_proximo_nivel' => self::cleanText($input['pronto_proximo_nivel'] ?? ''),
            'competencias_tecnicas' => self::cleanText($input['competencias_tecnicas'] ?? ''),
            'competencias_comportamentais' => self::cleanText($input['competencias_comportamentais'] ?? ''),
            'pontos_desenvolvimento' => self::cleanText($input['pontos_desenvolvimento'] ?? ''),
            'aumento_mensal' => $aumentoMensal,
            'impacto_anual' => $impactoAnual,
            'existe_orcamento_aprovado' => self::nullableEnum($input['existe_orcamento_aprovado'] ?? '', ['sim', 'nao', 'em_validacao']),
            'posicao_atual_sera' => self::nullableEnum($input['posicao_atual_sera'] ?? '', ['extinta', 'substituida']),
            'existe_candidato_interno' => self::nullableBool($input['existe_candidato_interno'] ?? null),
            'necessita_recrutamento_externo' => self::nullableBool($input['necessita_recrutamento_externo'] ?? null),
            'existe_risco_perda' => self::nullableBool($input['existe_risco_perda'] ?? null),
            'impacto_nao_aprovado' => self::cleanText($input['impacto_nao_aprovado'] ?? ''),
        ];
    }

    private static function validateCompleteInput(array $normalized, int $actorUserId): array
    {
        $requiredEnum = static function (?string $value, string $label, array $allowed): string {
            if (!$value || !in_array($value, $allowed, true)) {
                throw new InvalidArgumentException(sprintf('Selecione uma opção válida para "%s".', $label));
            }
            return $value;
        };

        $tipo = $requiredEnum($normalized['tipo_movimentacao'] ?? null, 'Tipo de movimentação', ['merito', 'promocao', 'transferencia', 'alteracao_funcao']);
        $gestorUserId = (int)($normalized['gestor_solicitante_usuario_id'] ?? 0);
        $setorId = (int)($normalized['setor_id'] ?? 0);
        $colaboradorId = (int)($normalized['colaborador_id'] ?? 0);
        $dataPrevista = (string)($normalized['data_prevista_mudanca'] ?? '');

        if ($gestorUserId <= 0 || !self::findUser($gestorUserId)) {
            throw new InvalidArgumentException('Selecione um gestor solicitante válido.');
        }
        if ($setorId <= 0 || !self::findSimple('setores', $setorId)) {
            throw new InvalidArgumentException('Selecione uma área/unidade válida.');
        }
        if ($colaboradorId <= 0) {
            throw new InvalidArgumentException('Selecione o colaborador da movimentação.');
        }
        $colaborador = self::findColaborador($colaboradorId);
        if (!$colaborador) {
            throw new InvalidArgumentException('O colaborador selecionado não foi encontrado.');
        }

        if (($normalized['matricula_snapshot'] ?? '') === '') {
            throw new InvalidArgumentException('A matrícula do colaborador não está disponível.');
        }
        if ((int)($normalized['cargo_atual_id'] ?? 0) <= 0) {
            throw new InvalidArgumentException('O cargo atual do colaborador não está disponível.');
        }
        if (!$dataPrevista) {
            throw new InvalidArgumentException('Informe a data prevista da mudança no formato DD/MM/AAAA.');
        }
        if ($dataPrevista <= date('Y-m-d')) {
            throw new InvalidArgumentException('A data prevista da mudança deve ser futura.');
        }

        if (in_array($tipo, ['promocao', 'alteracao_funcao'], true) && (int)($normalized['novo_cargo_id'] ?? 0) <= 0) {
            throw new InvalidArgumentException('Selecione o novo cargo para a movimentação proposta.');
        }
        if ($tipo === 'transferencia' && (int)($normalized['nova_area_setor_id'] ?? 0) <= 0) {
            throw new InvalidArgumentException('Selecione a nova área/unidade para a transferência.');
        }
        if (in_array($tipo, ['merito', 'promocao'], true) && ($normalized['novo_salario'] ?? null) === null) {
            throw new InvalidArgumentException('Informe o novo salário para movimentações por mérito ou promoção.');
        }

        $justificativa = trim((string)($normalized['justificativa'] ?? ''));
        if ($justificativa === '') {
            throw new InvalidArgumentException('Preencha a justificativa da solicitação.');
        }
        if (mb_strlen($justificativa) > 2000) {
            throw new InvalidArgumentException('A justificativa da solicitação deve ter no máximo 2000 caracteres.');
        }
        if (trim((string)($normalized['entregas_ultimos_6_meses'] ?? '')) === '') {
            throw new InvalidArgumentException('Preencha as principais entregas dos últimos 6 meses.');
        }
        if (trim((string)($normalized['resultados_atingidos'] ?? '')) === '') {
            throw new InvalidArgumentException('Preencha os resultados atingidos.');
        }
        if ((int)($normalized['avaliacao_desempenho_id'] ?? 0) <= 0) {
            throw new InvalidArgumentException('Selecione a avaliação de desempenho mais recente do colaborador.');
        }
        if (trim((string)($normalized['pronto_proximo_nivel'] ?? '')) === '') {
            throw new InvalidArgumentException('Preencha a justificativa sobre a prontidão para o próximo nível.');
        }
        $requiredEnum($normalized['existe_orcamento_aprovado'] ?? null, 'Existe orçamento aprovado?', ['sim', 'nao', 'em_validacao']);
        $estrutura = $requiredEnum($normalized['posicao_atual_sera'] ?? null, 'A posição atual será', ['extinta', 'substituida']);
        if ($estrutura === 'substituida') {
            if ($normalized['existe_candidato_interno'] === null) {
                throw new InvalidArgumentException('Informe se já existe candidato interno.');
            }
            if ($normalized['necessita_recrutamento_externo'] === null) {
                throw new InvalidArgumentException('Informe se há necessidade de recrutamento externo.');
            }
        }
        if ($normalized['existe_risco_perda'] === null) {
            throw new InvalidArgumentException('Informe se existe risco de perda do colaborador.');
        }
        if ((int)$normalized['existe_risco_perda'] === 1 && trim((string)($normalized['impacto_nao_aprovado'] ?? '')) === '') {
            throw new InvalidArgumentException('Descreva o impacto caso a movimentação não seja aprovada.');
        }

        $avaliacao = self::findSimple('colaborador_avaliacoes', (int)$normalized['avaliacao_desempenho_id']);
        if (!$avaliacao || (int)$avaliacao['colaborador_id'] !== $colaboradorId) {
            throw new InvalidArgumentException('A avaliação selecionada não pertence ao colaborador informado.');
        }

        $currentSalary = (float)($normalized['salario_atual_snapshot'] ?? 0);
        $newSalary = $normalized['novo_salario'] !== null ? (float)$normalized['novo_salario'] : null;
        if ($newSalary !== null && $currentSalary > 0) {
            $normalized['aumento_mensal'] = round($newSalary - $currentSalary, 2);
            $normalized['impacto_anual'] = round($normalized['aumento_mensal'] * 12, 2);
            $normalized['percentual_aumento'] = round((($newSalary - $currentSalary) / $currentSalary) * 100, 2);
        }

        return $normalized;
    }

    private static function hydrateRecord(array $row): array
    {
        $row['justificativa'] = Cipher::decrypt($row['justificativa_encrypted'] ?? null) ?? '';
        $row['entregas_ultimos_6_meses'] = Cipher::decrypt($row['entregas_ultimos_6_meses_encrypted'] ?? null) ?? '';
        $row['resultados_atingidos'] = Cipher::decrypt($row['resultados_atingidos_encrypted'] ?? null) ?? '';
        $row['pronto_proximo_nivel'] = Cipher::decrypt($row['pronto_proximo_nivel_encrypted'] ?? null) ?? '';
        $row['competencias_tecnicas'] = Cipher::decrypt($row['competencias_tecnicas_encrypted'] ?? null) ?? '';
        $row['competencias_comportamentais'] = Cipher::decrypt($row['competencias_comportamentais_encrypted'] ?? null) ?? '';
        $row['pontos_desenvolvimento'] = Cipher::decrypt($row['pontos_desenvolvimento_encrypted'] ?? null) ?? '';
        $row['impacto_nao_aprovado'] = Cipher::decrypt($row['impacto_nao_aprovado_encrypted'] ?? null) ?? '';
        $row['data_solicitacao_br'] = DateHelper::formatBrazilianDate($row['data_solicitacao'] ?? '');
        $row['data_prevista_mudanca_br'] = DateHelper::formatBrazilianDate($row['data_prevista_mudanca'] ?? '');
        $row['data_decisao_br'] = DateHelper::formatBrazilianDate($row['data_decisao'] ?? '');
        $row['tempo_cargo_label'] = self::formatMonthsLabel((int)($row['tempo_cargo_meses'] ?? 0));
        $row['tempo_empresa_label'] = self::formatMonthsLabel((int)($row['tempo_empresa_meses'] ?? 0));
        return $row;
    }

    private static function seedReferenceData(PDO $pdo): void
    {
        $pdo->exec("UPDATE colaboradores
                    SET matricula = CONCAT('MP', LPAD(id, 6, '0'))
                    WHERE (matricula IS NULL OR matricula = '')");

        $pdo->exec("UPDATE colaboradores c
                    LEFT JOIN cargo_faixas_salariais fs ON fs.cargo_id = c.cargo_id AND fs.ativo = 1
                    SET c.salario_atual = COALESCE(c.salario_atual, fs.salario_min, 2500.00)
                    WHERE c.salario_atual IS NULL");

        $pdo->exec("UPDATE colaboradores
                    SET data_admissao = DATE_SUB(COALESCE(DATE(created_at), CURDATE()), INTERVAL (12 + (id % 48)) MONTH)
                    WHERE data_admissao IS NULL");

        $pdo->exec("UPDATE colaboradores
                    SET data_inicio_cargo = DATE_ADD(data_admissao, INTERVAL (2 + (id % 12)) MONTH)
                    WHERE data_inicio_cargo IS NULL");

        $count = (int)$pdo->query("SELECT COUNT(*) FROM colaborador_avaliacoes")->fetchColumn();
        if ($count === 0) {
            $colaboradores = $pdo->query("SELECT id, nome FROM colaboradores WHERE ativo = 1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $pdo->prepare("INSERT INTO colaborador_avaliacoes (colaborador_id, titulo, nota, periodo_referencia, resumo)
                                   VALUES (?, ?, ?, ?, ?)");
            foreach ($colaboradores as $index => $colaborador) {
                $nota = 7.5 + (($index % 20) / 10);
                $stmt->execute([
                    (int)$colaborador['id'],
                    'Avaliação de desempenho mais recente',
                    min($nota, 10),
                    'Últimos 12 meses',
                    'Registro inicial gerado automaticamente para suportar o fluxo de movimentação de pessoal.',
                ]);
            }
        }
    }

    private static function notifyManagerIfNecessary(int $movimentacaoId, array $normalized, int $actorUserId): void
    {
        $gestorUserId = (int)($normalized['gestor_solicitante_usuario_id'] ?? 0);
        if ($gestorUserId <= 0 || $gestorUserId === $actorUserId) {
            return;
        }
        $user = self::findUser($gestorUserId);
        if (!$user || trim((string)$user['email']) === '') {
            return;
        }

        $cfg = Config::app();
        $baseUrl = rtrim((string)($cfg['app']['base_url'] ?? ''), '/');
        $url = $baseUrl !== '' ? $baseUrl . '/admin/movimentacoes-pessoal/' . $movimentacaoId : '/admin/movimentacoes-pessoal/' . $movimentacaoId;
        $subject = 'Rascunho de movimentação de pessoal disponível para assinatura';
        $message = "Um rascunho de movimentação de pessoal foi salvo e está vinculado ao seu perfil gestor.\n\nAcesse: {$url}\n";
        Mailer::sendTo((string)$user['email'], $subject, $message);
    }

    private static function notifyRh(int $movimentacaoId, array $normalized): void
    {
        $subject = 'Movimentação de pessoal aguardando assinatura do RH';
        $message = "A movimentação de pessoal #{$movimentacaoId} foi assinada pelo gestor e está pendente de RH.\n"
            . "Tipo: " . strtoupper(str_replace('_', ' ', $normalized['tipo_movimentacao'])) . "\n";
        Mailer::notifyHR($subject, $message);
    }

    private static function notifyManagerApproved(int $movimentacaoId): void
    {
        $record = self::findRaw($movimentacaoId);
        if (!$record) {
            return;
        }
        $user = self::findUser((int)$record['gestor_solicitante_usuario_id']);
        if (!$user || trim((string)$user['email']) === '') {
            return;
        }
        $subject = 'Movimentação de pessoal aprovada pelo RH';
        $message = "A movimentação de pessoal #{$movimentacaoId} foi aprovada pelo RH e está concluída.\n";
        Mailer::sendTo((string)$user['email'], $subject, $message);
    }

    private static function logAudit(int $movimentacaoId, int $actorUserId, string $eventType, ?string $fieldName, $oldValue, $newValue, ?string $ip): void
    {
        $stmt = Database::conn()->prepare("INSERT INTO movimentacoes_pessoal_auditoria
            (movimentacao_id, actor_usuario_id, event_type, field_name, old_value_encrypted, new_value_encrypted, ip)
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $movimentacaoId,
            $actorUserId,
            $eventType,
            $fieldName,
            self::encryptNullable(self::stringifyAuditValue($oldValue)),
            self::encryptNullable(self::stringifyAuditValue($newValue)),
            $ip,
        ]);
    }

    private static function auditTrail(int $movimentacaoId): array
    {
        $stmt = Database::conn()->prepare("SELECT a.*, u.nome AS actor_nome
                                           FROM movimentacoes_pessoal_auditoria a
                                           LEFT JOIN usuarios u ON u.id = a.actor_usuario_id
                                           WHERE a.movimentacao_id = ?
                                           ORDER BY a.created_at DESC");
        $stmt->execute([$movimentacaoId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['old_value'] = Cipher::decrypt($row['old_value_encrypted'] ?? null);
            $row['new_value'] = Cipher::decrypt($row['new_value_encrypted'] ?? null);
        }
        unset($row);
        return $rows;
    }

    private static function canEditDraft(array $record, int $actorUserId): bool
    {
        return (int)$record['gestor_solicitante_usuario_id'] === $actorUserId || self::isRhOrAdmin($actorUserId);
    }

    private static function userAccessProfile(int $userId): ?array
    {
        $stmt = Database::conn()->prepare("SELECT uc.*, c.nome AS colaborador_nome, c.setor_id, c.cargo_id, c.matricula,
                                                  cg.nome AS cargo_nome, u.email, u.role AS usuario_role
                                           FROM usuario_colaboradores uc
                                           INNER JOIN colaboradores c ON c.id = uc.colaborador_id
                                           INNER JOIN cargos cg ON cg.id = c.cargo_id
                                           INNER JOIN usuarios u ON u.id = uc.usuario_id
                                           WHERE uc.usuario_id = ? AND uc.ativo = 1
                                           LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function evaluationsForColaborador(int $colaboradorId): array
    {
        $stmt = Database::conn()->prepare("SELECT id, titulo FROM colaborador_avaliacoes WHERE colaborador_id = ? ORDER BY created_at DESC, id DESC");
        $stmt->execute([$colaboradorId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function findColaborador(int $id): ?array
    {
        $stmt = Database::conn()->prepare("SELECT * FROM colaboradores WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function findUser(int $id): ?array
    {
        $stmt = Database::conn()->prepare("SELECT * FROM usuarios WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function findRaw(int $id): ?array
    {
        $stmt = Database::conn()->prepare("SELECT * FROM movimentacoes_pessoal WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function findSimple(string $table, int $id): ?array
    {
        $stmt = Database::conn()->prepare(sprintf("SELECT * FROM %s WHERE id = ? LIMIT 1", $table));
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function addColumnIfMissing(string $table, string $column, string $ddl): void
    {
        if (!self::columnExists($table, $column)) {
            Database::conn()->exec($ddl);
        }
    }

    private static function columnExists(string $table, string $column): bool
    {
        $stmt = Database::conn()->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
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

    private static function nullableInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $int = (int)$value;
        return $int > 0 ? $int : null;
    }

    private static function nullableBool($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (in_array((string)$value, ['1', 'sim', 'true'], true)) {
            return 1;
        }
        if (in_array((string)$value, ['0', 'nao', 'não', 'false'], true)) {
            return 0;
        }
        return null;
    }

    private static function nullableEnum($value, array $allowed): ?string
    {
        $value = strtolower(trim((string)$value));
        if ($value === '') {
            return null;
        }
        return in_array($value, $allowed, true) ? $value : null;
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

    private static function cleanText($value): string
    {
        return trim((string)$value);
    }

    private static function signatureHash(int $recordId, string $stage, int $userId): string
    {
        return hash('sha256', implode('|', [$recordId, $stage, $userId, gmdate('c')]));
    }

    private static function encryptNullable(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);
        return $value === null || $value === '' ? null : Cipher::encrypt($value);
    }

    private static function stringifyAuditValue($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_scalar($value)) {
            return (string)$value;
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function isRhOrAdmin(int $userId): bool
    {
        $user = self::findUser($userId);
        if (!$user) {
            return false;
        }
        return (int)($user['is_supervisor'] ?? 0) === 1 || in_array(strtolower((string)$user['role']), ['admin', 'rh'], true);
    }
}
