<?php
class SolicitacaoVaga
{
    private const STATUS_PENDENTE_LIDER = 'pendente_lider';
    private const STATUS_PENDENTE_RH = 'pendente_rh';
    private const STATUS_APROVADA = 'aprovada';
    private const STATUS_REPROVADA_LIDER = 'reprovada_lider';
    private const STATUS_REPROVADA_RH = 'reprovada_rh';
    private const STATUS_CONCLUIDA = 'concluida';

    private const TIPO_COMPETENCIA_TECNICA = 'tecnica';
    private const TIPO_COMPETENCIA_COMPORTAMENTAL = 'comportamental';

    public static function ensureSchema(): void
    {
        $pdo = Database::conn();

        if (!self::tableExists('beneficios')) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS beneficios (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nome VARCHAR(120) NOT NULL,
                descricao TEXT NULL,
                parceiro VARCHAR(120) NULL,
                logo_path VARCHAR(255) NULL,
                ativo TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS centros_custo (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setor_id INT NOT NULL,
            codigo VARCHAR(30) NOT NULL,
            nome VARCHAR(120) NOT NULL,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_centros_custo_codigo (codigo),
            KEY idx_centros_custo_setor (setor_id),
            CONSTRAINT fk_centros_custo_setor FOREIGN KEY (setor_id) REFERENCES setores(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS cargo_setores (
            cargo_id INT NOT NULL,
            setor_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (cargo_id, setor_id),
            KEY idx_cargo_setores_setor (setor_id),
            CONSTRAINT fk_cargo_setores_cargo FOREIGN KEY (cargo_id) REFERENCES cargos(id) ON DELETE CASCADE,
            CONSTRAINT fk_cargo_setores_setor FOREIGN KEY (setor_id) REFERENCES setores(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS cargo_faixas_salariais (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cargo_id INT NOT NULL,
            salario_min DECIMAL(12,2) NOT NULL,
            salario_max DECIMAL(12,2) NOT NULL,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_cargo_faixa (cargo_id),
            CONSTRAINT fk_cargo_faixas_salariais_cargo FOREIGN KEY (cargo_id) REFERENCES cargos(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS cargo_beneficios (
            cargo_id INT NOT NULL,
            beneficio_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (cargo_id, beneficio_id),
            KEY idx_cargo_beneficios_beneficio (beneficio_id),
            CONSTRAINT fk_cargo_beneficios_cargo FOREIGN KEY (cargo_id) REFERENCES cargos(id) ON DELETE CASCADE,
            CONSTRAINT fk_cargo_beneficios_beneficio FOREIGN KEY (beneficio_id) REFERENCES beneficios(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS competencias (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(120) NOT NULL,
            tipo ENUM('tecnica','comportamental') NOT NULL,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_competencias_nome_tipo (nome, tipo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS usuario_colaboradores (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            colaborador_id INT NOT NULL,
            is_gestor TINYINT(1) NOT NULL DEFAULT 0,
            is_rh TINYINT(1) NOT NULL DEFAULT 0,
            lider_colaborador_id INT NULL,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_usuario_colaborador_usuario (usuario_id),
            UNIQUE KEY uniq_usuario_colaborador_colaborador (colaborador_id),
            KEY idx_usuario_colaboradores_lider (lider_colaborador_id),
            CONSTRAINT fk_usuario_colaboradores_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
            CONSTRAINT fk_usuario_colaboradores_colaborador FOREIGN KEY (colaborador_id) REFERENCES colaboradores(id) ON DELETE CASCADE,
            CONSTRAINT fk_usuario_colaboradores_lider FOREIGN KEY (lider_colaborador_id) REFERENCES colaboradores(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS solicitacoes_vaga (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setor_id INT NOT NULL,
            quantidade_vagas INT NOT NULL,
            cargo_id INT NOT NULL,
            maquina_operada_encrypted TEXT NULL,
            gestor_solicitante_colaborador_id INT NOT NULL,
            solicitante_usuario_id INT NOT NULL,
            tipo_vaga ENUM('nova_posicao','substituicao','aumento_quadro','projeto_temporario') NOT NULL,
            colaborador_substituido_id INT NULL,
            data_desligamento DATE NULL,
            motivo_saida ENUM('desligamento','promocao','transferencia','outros') NULL,
            motivo_saida_outros_encrypted TEXT NULL,
            tipo_contratacao ENUM('clt','temporario','terceiro','pj') NOT NULL,
            salario_previsto DECIMAL(12,2) NOT NULL,
            centro_custo_id INT NOT NULL,
            previsto_orcamento TINYINT(1) NOT NULL,
            justificativa_orcamento_encrypted TEXT NULL,
            jornada_trabalho VARCHAR(80) NOT NULL,
            escala_encrypted TEXT NULL,
            turno ENUM('diurno','noturno','misto') NULL,
            escolaridade_minima ENUM('fundamental','medio','tecnico','superior_incompleto','superior_completo','pos_graduacao') NOT NULL,
            formacao_academica_encrypted TEXT NULL,
            experiencia_necessaria_encrypted TEXT NULL,
            entregas_esperadas_encrypted MEDIUMTEXT NOT NULL,
            nivel_responsabilidade ENUM('operacional','tecnico','analitico','estrategico') NOT NULL,
            data_prevista_inicio DATE NOT NULL,
            urgencia ENUM('baixa','media','alta','critica') NOT NULL,
            data_limite_fechamento DATE NULL,
            lider_imediato_colaborador_id INT NULL,
            lider_imediato_usuario_id INT NULL,
            status_fluxo ENUM('pendente_lider','pendente_rh','aprovada','reprovada_lider','reprovada_rh','concluida') NOT NULL DEFAULT 'pendente_lider',
            nome_contratado_colaborador_id INT NULL,
            data_admissao DATE NULL,
            tempo_fechamento_dias INT NULL,
            avaliacao_90_dias ENUM('atendeu_plenamente','atendeu_parcialmente','nao_atendeu') NULL,
            observacoes_rh_encrypted TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_solicitacoes_status (status_fluxo),
            KEY idx_solicitacoes_setor (setor_id),
            KEY idx_solicitacoes_cargo (cargo_id),
            KEY idx_solicitacoes_usuario (solicitante_usuario_id),
            CONSTRAINT fk_solicitacoes_setor FOREIGN KEY (setor_id) REFERENCES setores(id) ON DELETE RESTRICT,
            CONSTRAINT fk_solicitacoes_cargo FOREIGN KEY (cargo_id) REFERENCES cargos(id) ON DELETE RESTRICT,
            CONSTRAINT fk_solicitacoes_gestor_colaborador FOREIGN KEY (gestor_solicitante_colaborador_id) REFERENCES colaboradores(id) ON DELETE RESTRICT,
            CONSTRAINT fk_solicitacoes_solicitante_usuario FOREIGN KEY (solicitante_usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
            CONSTRAINT fk_solicitacoes_colaborador_substituido FOREIGN KEY (colaborador_substituido_id) REFERENCES colaboradores(id) ON DELETE SET NULL,
            CONSTRAINT fk_solicitacoes_centro_custo FOREIGN KEY (centro_custo_id) REFERENCES centros_custo(id) ON DELETE RESTRICT,
            CONSTRAINT fk_solicitacoes_lider_colaborador FOREIGN KEY (lider_imediato_colaborador_id) REFERENCES colaboradores(id) ON DELETE SET NULL,
            CONSTRAINT fk_solicitacoes_lider_usuario FOREIGN KEY (lider_imediato_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
            CONSTRAINT fk_solicitacoes_contratado FOREIGN KEY (nome_contratado_colaborador_id) REFERENCES colaboradores(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS solicitacao_vaga_beneficios (
            solicitacao_id INT NOT NULL,
            beneficio_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (solicitacao_id, beneficio_id),
            KEY idx_solicitacao_beneficios_beneficio (beneficio_id),
            CONSTRAINT fk_solicitacao_beneficios_solicitacao FOREIGN KEY (solicitacao_id) REFERENCES solicitacoes_vaga(id) ON DELETE CASCADE,
            CONSTRAINT fk_solicitacao_beneficios_beneficio FOREIGN KEY (beneficio_id) REFERENCES beneficios(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS solicitacao_vaga_competencias (
            solicitacao_id INT NOT NULL,
            competencia_id INT NOT NULL,
            tipo ENUM('tecnica','comportamental') NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (solicitacao_id, competencia_id),
            KEY idx_solicitacao_competencias_tipo (tipo),
            CONSTRAINT fk_solicitacao_competencias_solicitacao FOREIGN KEY (solicitacao_id) REFERENCES solicitacoes_vaga(id) ON DELETE CASCADE,
            CONSTRAINT fk_solicitacao_competencias_competencia FOREIGN KEY (competencia_id) REFERENCES competencias(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS solicitacao_vaga_aprovacoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            solicitacao_id INT NOT NULL,
            etapa ENUM('lider_imediato','rh') NOT NULL,
            destinatario_usuario_id INT NULL,
            status ENUM('pendente','aprovado','reprovado') NOT NULL DEFAULT 'pendente',
            aprovador_usuario_id INT NULL,
            observacao_encrypted TEXT NULL,
            assinatura_hash VARCHAR(64) NULL,
            aprovado_em DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_solicitacao_etapa (solicitacao_id, etapa),
            KEY idx_solicitacao_aprovacoes_destinatario (destinatario_usuario_id),
            CONSTRAINT fk_solicitacao_aprovacoes_solicitacao FOREIGN KEY (solicitacao_id) REFERENCES solicitacoes_vaga(id) ON DELETE CASCADE,
            CONSTRAINT fk_solicitacao_aprovacoes_destinatario FOREIGN KEY (destinatario_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
            CONSTRAINT fk_solicitacao_aprovacoes_aprovador FOREIGN KEY (aprovador_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS solicitacao_vaga_auditoria (
            id INT AUTO_INCREMENT PRIMARY KEY,
            solicitacao_id INT NOT NULL,
            actor_usuario_id INT NULL,
            event_type VARCHAR(60) NOT NULL,
            field_name VARCHAR(80) NULL,
            old_value_encrypted TEXT NULL,
            new_value_encrypted TEXT NULL,
            ip VARCHAR(45) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_solicitacao_auditoria_event (event_type),
            CONSTRAINT fk_solicitacao_auditoria_solicitacao FOREIGN KEY (solicitacao_id) REFERENCES solicitacoes_vaga(id) ON DELETE CASCADE,
            CONSTRAINT fk_solicitacao_auditoria_actor FOREIGN KEY (actor_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        self::seedReferenceData($pdo);
    }

    public static function formDependencies(?int $currentUserId = null): array
    {
        self::ensureSchema();
        $pdo = Database::conn();

        $setores = $pdo->query("SELECT id, nome FROM setores WHERE ativo = 1 ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
        $cargos = $pdo->query(
            "SELECT c.id, c.nome,
                    COALESCE(fs.salario_min, 0) AS salario_min,
                    COALESCE(fs.salario_max, 0) AS salario_max
             FROM cargos c
             LEFT JOIN cargo_faixas_salariais fs ON fs.cargo_id = c.id AND fs.ativo = 1
             WHERE c.ativo = 1
             ORDER BY c.nome ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $cargoSetores = [];
        $stmtCargoSetores = $pdo->query("SELECT cargo_id, setor_id FROM cargo_setores");
        foreach ($stmtCargoSetores->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cargoId = (int)$row['cargo_id'];
            $cargoSetores[$cargoId] ??= [];
            $cargoSetores[$cargoId][] = (int)$row['setor_id'];
        }

        $centros = $pdo->query(
            "SELECT cc.id, cc.nome, cc.codigo, cc.setor_id
             FROM centros_custo cc
             WHERE cc.ativo = 1
             ORDER BY cc.nome ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $gestores = $pdo->query(
            "SELECT uc.usuario_id, uc.colaborador_id, uc.is_gestor, uc.is_rh, uc.lider_colaborador_id,
                    c.nome AS colaborador_nome, c.setor_id, cg.nome AS cargo_nome,
                    u.nome AS usuario_nome, u.role AS usuario_role
             FROM usuario_colaboradores uc
             INNER JOIN colaboradores c ON c.id = uc.colaborador_id
             INNER JOIN cargos cg ON cg.id = c.cargo_id
             INNER JOIN usuarios u ON u.id = uc.usuario_id
             WHERE uc.ativo = 1 AND uc.is_gestor = 1 AND c.ativo = 1
             ORDER BY c.nome ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $colaboradores = $pdo->query(
            "SELECT c.id, c.nome, c.ativo, c.setor_id, c.cargo_id, cg.nome AS cargo_nome, s.nome AS setor_nome
             FROM colaboradores c
             INNER JOIN cargos cg ON cg.id = c.cargo_id
             LEFT JOIN setores s ON s.id = c.setor_id
             ORDER BY c.nome ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $beneficiosByCargo = [];
        $stmtBeneficios = $pdo->query(
            "SELECT cb.cargo_id, b.id, b.nome
             FROM cargo_beneficios cb
             INNER JOIN beneficios b ON b.id = cb.beneficio_id
             WHERE b.ativo = 1
             ORDER BY b.nome ASC"
        );
        foreach ($stmtBeneficios->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cargoId = (int)$row['cargo_id'];
            $beneficiosByCargo[$cargoId] ??= [];
            $beneficiosByCargo[$cargoId][] = [
                'id' => (int)$row['id'],
                'nome' => $row['nome'],
            ];
        }

        $competencias = $pdo->query(
            "SELECT id, nome, tipo FROM competencias WHERE ativo = 1 ORDER BY nome ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $competenciasByType = [
            self::TIPO_COMPETENCIA_TECNICA => [],
            self::TIPO_COMPETENCIA_COMPORTAMENTAL => [],
        ];
        foreach ($competencias as $competencia) {
            $competenciasByType[$competencia['tipo']][] = [
                'id' => (int)$competencia['id'],
                'nome' => $competencia['nome'],
            ];
        }

        $currentAccess = $currentUserId ? self::userAccessProfile($currentUserId) : null;

        return [
            'setores' => array_map(static function (array $row): array {
                return ['id' => (int)$row['id'], 'nome' => $row['nome']];
            }, $setores),
            'cargos' => array_map(function (array $row) use ($cargoSetores): array {
                $cargoId = (int)$row['id'];
                return [
                    'id' => $cargoId,
                    'nome' => $row['nome'],
                    'setor_ids' => $cargoSetores[$cargoId] ?? [],
                    'salario_min' => (float)$row['salario_min'],
                    'salario_max' => (float)$row['salario_max'],
                    'requires_machine_description' => self::cargoRequiresMachine($row['nome']),
                ];
            }, $cargos),
            'centros_custo' => array_map(static function (array $row): array {
                return [
                    'id' => (int)$row['id'],
                    'nome' => $row['nome'],
                    'codigo' => $row['codigo'],
                    'setor_id' => (int)$row['setor_id'],
                ];
            }, $centros),
            'gestores' => array_map(static function (array $row): array {
                return [
                    'usuario_id' => (int)$row['usuario_id'],
                    'colaborador_id' => (int)$row['colaborador_id'],
                    'nome' => $row['colaborador_nome'],
                    'cargo_nome' => $row['cargo_nome'],
                    'setor_id' => (int)$row['setor_id'],
                    'lider_colaborador_id' => $row['lider_colaborador_id'] !== null ? (int)$row['lider_colaborador_id'] : null,
                    'usuario_role' => $row['usuario_role'],
                ];
            }, $gestores),
            'colaboradores' => array_map(static function (array $row): array {
                return [
                    'id' => (int)$row['id'],
                    'nome' => $row['nome'],
                    'ativo' => (int)$row['ativo'] === 1,
                    'setor_id' => $row['setor_id'] !== null ? (int)$row['setor_id'] : null,
                    'cargo_id' => (int)$row['cargo_id'],
                    'cargo_nome' => $row['cargo_nome'],
                    'setor_nome' => $row['setor_nome'],
                ];
            }, $colaboradores),
            'beneficios_by_cargo' => $beneficiosByCargo,
            'competencias' => $competenciasByType,
            'escolaridades' => [
                'fundamental' => 'Fundamental',
                'medio' => 'Médio',
                'tecnico' => 'Técnico',
                'superior_incompleto' => 'Superior incompleto',
                'superior_completo' => 'Superior completo',
                'pos_graduacao' => 'Pós-graduação',
            ],
            'current_access' => $currentAccess,
        ];
    }

    public static function allForUser(?int $currentUserId, ?string $currentRole, bool $isSupervisor): array
    {
        self::ensureSchema();
        $pdo = Database::conn();

        $sql = "SELECT sv.id, sv.quantidade_vagas, sv.tipo_vaga, sv.tipo_contratacao, sv.salario_previsto,
                       sv.status_fluxo, sv.data_prevista_inicio, sv.data_limite_fechamento, sv.created_at,
                       s.nome AS setor_nome, c.nome AS cargo_nome, g.nome AS gestor_nome,
                       ap_lider.status AS status_lider, ap_rh.status AS status_rh
                FROM solicitacoes_vaga sv
                INNER JOIN setores s ON s.id = sv.setor_id
                INNER JOIN cargos c ON c.id = sv.cargo_id
                INNER JOIN colaboradores g ON g.id = sv.gestor_solicitante_colaborador_id
                LEFT JOIN solicitacao_vaga_aprovacoes ap_lider ON ap_lider.solicitacao_id = sv.id AND ap_lider.etapa = 'lider_imediato'
                LEFT JOIN solicitacao_vaga_aprovacoes ap_rh ON ap_rh.solicitacao_id = sv.id AND ap_rh.etapa = 'rh'
                WHERE 1=1";
        $params = [];

        $currentRole = strtolower(trim((string)$currentRole));
        if (!$isSupervisor && !in_array($currentRole, ['admin', 'rh'], true)) {
            $sql .= " AND (sv.solicitante_usuario_id = ? OR ap_lider.destinatario_usuario_id = ?)";
            $params[] = (int)$currentUserId;
            $params[] = (int)$currentUserId;
        }

        $sql .= " ORDER BY sv.created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return array_map(function (array $row): array {
            return [
                'id' => (int)$row['id'],
                'setor_nome' => $row['setor_nome'],
                'cargo_nome' => $row['cargo_nome'],
                'gestor_nome' => $row['gestor_nome'],
                'quantidade_vagas' => (int)$row['quantidade_vagas'],
                'tipo_vaga' => $row['tipo_vaga'],
                'tipo_contratacao' => $row['tipo_contratacao'],
                'salario_previsto' => (float)$row['salario_previsto'],
                'status_fluxo' => $row['status_fluxo'],
                'status_lider' => $row['status_lider'] ?? 'pendente',
                'status_rh' => $row['status_rh'] ?? 'pendente',
                'data_prevista_inicio' => DateHelper::formatBrazilianDate($row['data_prevista_inicio'] ?? ''),
                'data_limite_fechamento' => DateHelper::formatBrazilianDate($row['data_limite_fechamento'] ?? ''),
                'created_at' => $row['created_at'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function findAccessible(int $id, ?int $currentUserId, ?string $currentRole, bool $isSupervisor): ?array
    {
        self::ensureSchema();
        $pdo = Database::conn();

        $sql = "SELECT sv.*, s.nome AS setor_nome, c.nome AS cargo_nome, g.nome AS gestor_nome,
                       cc.nome AS centro_custo_nome, cc.codigo AS centro_custo_codigo,
                       sub.nome AS substituido_nome, lider.nome AS lider_nome, contratado.nome AS contratado_nome
                FROM solicitacoes_vaga sv
                INNER JOIN setores s ON s.id = sv.setor_id
                INNER JOIN cargos c ON c.id = sv.cargo_id
                INNER JOIN colaboradores g ON g.id = sv.gestor_solicitante_colaborador_id
                INNER JOIN centros_custo cc ON cc.id = sv.centro_custo_id
                LEFT JOIN colaboradores sub ON sub.id = sv.colaborador_substituido_id
                LEFT JOIN colaboradores lider ON lider.id = sv.lider_imediato_colaborador_id
                LEFT JOIN colaboradores contratado ON contratado.id = sv.nome_contratado_colaborador_id
                WHERE sv.id = ?";
        $params = [$id];

        $currentRole = strtolower(trim((string)$currentRole));
        if (!$isSupervisor && !in_array($currentRole, ['admin', 'rh'], true)) {
            $sql .= " AND (
                sv.solicitante_usuario_id = ?
                OR EXISTS (
                    SELECT 1
                    FROM solicitacao_vaga_aprovacoes sa
                    WHERE sa.solicitacao_id = sv.id AND sa.destinatario_usuario_id = ?
                )
            )";
            $params[] = (int)$currentUserId;
            $params[] = (int)$currentUserId;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $row['beneficios'] = self::selectedBenefits((int)$row['id']);
        $row['competencias_tecnicas'] = self::selectedCompetencies((int)$row['id'], self::TIPO_COMPETENCIA_TECNICA);
        $row['competencias_comportamentais'] = self::selectedCompetencies((int)$row['id'], self::TIPO_COMPETENCIA_COMPORTAMENTAL);
        $row['aprovacoes'] = self::approvalStatus((int)$row['id']);
        $row['auditoria'] = self::auditTrail((int)$row['id']);

        return self::hydrateRecord($row);
    }

    public static function create(array $input, int $actorUserId, ?string $ip = null): int
    {
        self::ensureSchema();
        $normalized = self::validateForSubmission($input, $actorUserId);
        $leader = self::resolveLeaderAssignment($normalized['gestor_solicitante_colaborador_id'], $normalized['setor_id']);

        $pdo = Database::conn();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO solicitacoes_vaga (
                    setor_id, quantidade_vagas, cargo_id, maquina_operada_encrypted,
                    gestor_solicitante_colaborador_id, solicitante_usuario_id, tipo_vaga,
                    colaborador_substituido_id, data_desligamento, motivo_saida, motivo_saida_outros_encrypted,
                    tipo_contratacao, salario_previsto, centro_custo_id, previsto_orcamento,
                    justificativa_orcamento_encrypted, jornada_trabalho, escala_encrypted, turno,
                    escolaridade_minima, formacao_academica_encrypted, experiencia_necessaria_encrypted,
                    entregas_esperadas_encrypted, nivel_responsabilidade, data_prevista_inicio,
                    urgencia, data_limite_fechamento, lider_imediato_colaborador_id, lider_imediato_usuario_id,
                    status_fluxo
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );

            $stmt->execute([
                $normalized['setor_id'],
                $normalized['quantidade_vagas'],
                $normalized['cargo_id'],
                self::encryptNullable($normalized['maquina_operada']),
                $normalized['gestor_solicitante_colaborador_id'],
                $actorUserId,
                $normalized['tipo_vaga'],
                $normalized['colaborador_substituido_id'],
                $normalized['data_desligamento'],
                $normalized['motivo_saida'],
                self::encryptNullable($normalized['motivo_saida_outros']),
                $normalized['tipo_contratacao'],
                $normalized['salario_previsto'],
                $normalized['centro_custo_id'],
                $normalized['previsto_orcamento'],
                self::encryptNullable($normalized['justificativa_orcamento']),
                $normalized['jornada_trabalho'],
                self::encryptNullable($normalized['escala']),
                $normalized['turno'],
                $normalized['escolaridade_minima'],
                self::encryptNullable($normalized['formacao_academica']),
                self::encryptNullable($normalized['experiencia_necessaria']),
                self::encryptRequired($normalized['entregas_esperadas']),
                $normalized['nivel_responsabilidade'],
                $normalized['data_prevista_inicio'],
                $normalized['urgencia'],
                $normalized['data_limite_fechamento'],
                $leader['colaborador_id'],
                $leader['usuario_id'],
                self::STATUS_PENDENTE_LIDER,
            ]);

            $solicitacaoId = (int)$pdo->lastInsertId();
            self::syncBenefits($solicitacaoId, $normalized['beneficio_ids']);
            self::syncCompetencies($solicitacaoId, $normalized['competencia_tecnica_ids'], self::TIPO_COMPETENCIA_TECNICA);
            self::syncCompetencies($solicitacaoId, $normalized['competencia_comportamental_ids'], self::TIPO_COMPETENCIA_COMPORTAMENTAL);
            self::seedApprovalRows($solicitacaoId, $leader['usuario_id']);

            self::logAudit($solicitacaoId, $actorUserId, 'created', null, null, 'Solicitação criada', $ip);
            $pdo->commit();

            return $solicitacaoId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function approve(int $solicitacaoId, string $step, int $actorUserId, bool $actorIsRh, bool $isSupervisor, string $decision, ?string $comment, ?string $ip = null): array
    {
        self::ensureSchema();
        $pdo = Database::conn();

        if (!in_array($step, ['lider_imediato', 'rh'], true)) {
            return ['ok' => false, 'error' => 'Etapa de aprovação inválida.'];
        }
        if (!in_array($decision, ['aprovado', 'reprovado'], true)) {
            return ['ok' => false, 'error' => 'Decisão de aprovação inválida.'];
        }

        $solicitacao = self::findRaw($solicitacaoId);
        if (!$solicitacao) {
            return ['ok' => false, 'error' => 'Solicitação de vaga não encontrada.'];
        }

        $approval = self::approvalRow($solicitacaoId, $step);
        if (!$approval) {
            return ['ok' => false, 'error' => 'Etapa de aprovação não configurada.'];
        }
        if (($approval['status'] ?? 'pendente') !== 'pendente') {
            return ['ok' => false, 'error' => 'Esta etapa já foi registrada anteriormente.'];
        }

        if ($step === 'lider_imediato' && !$isSupervisor && (int)($approval['destinatario_usuario_id'] ?? 0) !== $actorUserId) {
            return ['ok' => false, 'error' => 'Apenas o líder imediato designado pode aprovar esta etapa.'];
        }
        if ($step === 'rh' && !$isSupervisor && !$actorIsRh) {
            return ['ok' => false, 'error' => 'A aprovação de RH é restrita ao perfil de RH.'];
        }
        if ($step === 'rh') {
            $leaderApproval = self::approvalRow($solicitacaoId, 'lider_imediato');
            if (($leaderApproval['status'] ?? 'pendente') !== 'aprovado') {
                return ['ok' => false, 'error' => 'A etapa do líder imediato precisa ser aprovada antes do RH.'];
            }
        }

        $newStatus = match ($step) {
            'lider_imediato' => $decision === 'aprovado' ? self::STATUS_PENDENTE_RH : self::STATUS_REPROVADA_LIDER,
            'rh' => $decision === 'aprovado' ? self::STATUS_APROVADA : self::STATUS_REPROVADA_RH,
            default => self::STATUS_PENDENTE_LIDER,
        };

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "UPDATE solicitacao_vaga_aprovacoes
                 SET status = ?, aprovador_usuario_id = ?, observacao_encrypted = ?, assinatura_hash = ?, aprovado_em = NOW()
                 WHERE solicitacao_id = ? AND etapa = ?"
            );
            $stmt->execute([
                $decision,
                $actorUserId,
                self::encryptNullable($comment),
                hash('sha256', $solicitacaoId . '|' . $step . '|' . $actorUserId . '|' . gmdate('c')),
                $solicitacaoId,
                $step,
            ]);

            $stmtMain = $pdo->prepare("UPDATE solicitacoes_vaga SET status_fluxo = ? WHERE id = ?");
            $stmtMain->execute([$newStatus, $solicitacaoId]);

            self::logAudit(
                $solicitacaoId,
                $actorUserId,
                'approval_' . $step,
                'status_fluxo',
                $solicitacao['status_fluxo'] ?? null,
                $newStatus,
                $ip
            );

            $pdo->commit();
            return ['ok' => true, 'status' => $newStatus];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public static function saveRhControl(int $solicitacaoId, array $input, int $actorUserId, ?string $ip = null): array
    {
        self::ensureSchema();
        $record = self::findRaw($solicitacaoId);
        if (!$record) {
            return ['ok' => false, 'error' => 'Solicitação não encontrada.'];
        }
        if (!in_array((string)$record['status_fluxo'], [self::STATUS_APROVADA, self::STATUS_CONCLUIDA], true)) {
            return ['ok' => false, 'error' => 'O controle interno de RH só pode ser preenchido após as aprovações.'];
        }

        $nomeContratadoId = self::nullableInt($input['nome_contratado_colaborador_id'] ?? null);
        $dataAdmissao = DateHelper::toDatabaseDate($input['data_admissao'] ?? '');
        $avaliacao = self::nullableEnum(
            $input['avaliacao_90_dias'] ?? '',
            ['atendeu_plenamente', 'atendeu_parcialmente', 'nao_atendeu']
        );
        $observacoes = trim((string)($input['observacoes_rh'] ?? ''));

        if (!$nomeContratadoId) {
            return ['ok' => false, 'error' => 'Selecione o colaborador contratado no controle interno de RH.'];
        }
        if (!$dataAdmissao) {
            return ['ok' => false, 'error' => 'Informe a data de admissão no formato DD/MM/AAAA.'];
        }

        $colaborador = self::findSimple('colaboradores', $nomeContratadoId);
        if (!$colaborador) {
            return ['ok' => false, 'error' => 'Colaborador contratado não encontrado.'];
        }

        $tempoFechamento = DateHelper::daysBetween((string)$record['created_at'], $dataAdmissao);
        if ($tempoFechamento === null) {
            return ['ok' => false, 'error' => 'Não foi possível calcular o tempo de fechamento da vaga.'];
        }

        $pdo = Database::conn();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "UPDATE solicitacoes_vaga
                 SET nome_contratado_colaborador_id = ?, data_admissao = ?, tempo_fechamento_dias = ?,
                     avaliacao_90_dias = ?, observacoes_rh_encrypted = ?, status_fluxo = ?
                 WHERE id = ?"
            );
            $stmt->execute([
                $nomeContratadoId,
                $dataAdmissao,
                $tempoFechamento,
                $avaliacao,
                self::encryptNullable($observacoes),
                self::STATUS_CONCLUIDA,
                $solicitacaoId,
            ]);

            self::logAudit($solicitacaoId, $actorUserId, 'rh_control_update', 'data_admissao', $record['data_admissao'] ?? null, $dataAdmissao, $ip);
            self::logAudit($solicitacaoId, $actorUserId, 'rh_control_update', 'nome_contratado_colaborador_id', $record['nome_contratado_colaborador_id'] ?? null, (string)$nomeContratadoId, $ip);
            self::logAudit($solicitacaoId, $actorUserId, 'rh_control_update', 'tempo_fechamento_dias', $record['tempo_fechamento_dias'] ?? null, (string)$tempoFechamento, $ip);

            $pdo->commit();
            return ['ok' => true];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public static function userCanEditRh(?string $role, bool $isSupervisor): bool
    {
        $role = strtolower(trim((string)$role));
        return $isSupervisor || in_array($role, ['admin', 'rh'], true);
    }

    public static function userCanCreate(?string $role, bool $isSupervisor): bool
    {
        $role = strtolower(trim((string)$role));
        return $isSupervisor || in_array($role, ['admin', 'rh', 'viewer'], true);
    }

    private static function validateForSubmission(array $input, int $actorUserId): array
    {
        $setorId = self::requiredEntityId($input['setor_id'] ?? null, 'setores', 'Área / Departamento');
        $cargoId = self::requiredEntityId($input['cargo_id'] ?? null, 'cargos', 'Cargo');
        $gestorColaboradorId = self::requiredEntityId($input['gestor_solicitante_colaborador_id'] ?? null, 'colaboradores', 'Gestor solicitante');
        $centroCustoId = self::requiredEntityId($input['centro_custo_id'] ?? null, 'centros_custo', 'Centro de custo');

        $quantidadeVagas = max(0, (int)($input['quantidade_vagas'] ?? 0));
        if ($quantidadeVagas < 1) {
            throw new InvalidArgumentException('A quantidade de vagas deve ser maior ou igual a 1.');
        }

        $tipoVaga = self::requiredEnum(
            $input['tipo_vaga'] ?? '',
            ['nova_posicao', 'substituicao', 'aumento_quadro', 'projeto_temporario'],
            'Tipo de vaga'
        );
        $tipoContratacao = self::requiredEnum(
            $input['tipo_contratacao'] ?? '',
            ['clt', 'temporario', 'terceiro', 'pj'],
            'Tipo de contratação'
        );
        $previstoOrcamento = self::requiredEnum($input['previsto_orcamento'] ?? '', ['1', '0'], 'Previsto no orçamento anual') === '1' ? 1 : 0;
        $escolaridade = self::requiredEnum(
            $input['escolaridade_minima'] ?? '',
            ['fundamental', 'medio', 'tecnico', 'superior_incompleto', 'superior_completo', 'pos_graduacao'],
            'Escolaridade mínima'
        );
        $urgencia = self::requiredEnum($input['urgencia'] ?? '', ['baixa', 'media', 'alta', 'critica'], 'Urgência');
        $nivelResponsabilidade = self::requiredEnum(
            $input['nivel_responsabilidade'] ?? '',
            ['operacional', 'tecnico', 'analitico', 'estrategico'],
            'Nível de responsabilidade'
        );
        $turno = self::nullableEnum($input['turno'] ?? '', ['diurno', 'noturno', 'misto']);

        $jornada = trim((string)($input['jornada_trabalho'] ?? ''));
        if ($jornada === '') {
            throw new InvalidArgumentException('Informe a jornada de trabalho.');
        }

        $salarioPrevisto = self::parseMoney($input['salario_previsto'] ?? '');
        if ($salarioPrevisto === null) {
            throw new InvalidArgumentException('Informe o salário previsto em formato monetário válido.');
        }

        $setor = self::findSimple('setores', $setorId);
        $cargo = self::findSimple('cargos', $cargoId);
        $gestor = self::findUserColaboradorByColaboradorId($gestorColaboradorId);
        $centro = self::findSimple('centros_custo', $centroCustoId);
        if (!$setor || !$cargo || !$gestor || !$centro) {
            throw new InvalidArgumentException('Não foi possível validar os vínculos obrigatórios do formulário.');
        }

        if (!self::cargoBelongsToSetor($cargoId, $setorId)) {
            throw new InvalidArgumentException('O cargo selecionado não pertence à área/departamento informado.');
        }
        if ((int)$gestor['is_gestor'] !== 1 || (int)$gestor['setor_id'] !== $setorId) {
            throw new InvalidArgumentException('O gestor solicitante informado não pertence à área selecionada ou não possui perfil de gestor.');
        }
        if ((int)$centro['setor_id'] !== $setorId) {
            throw new InvalidArgumentException('O centro de custo informado não pertence à área selecionada.');
        }
        if ((int)$gestor['usuario_id'] !== $actorUserId && !self::isRhOrAdmin($actorUserId)) {
            throw new InvalidArgumentException('Você só pode abrir solicitações vinculadas ao seu próprio perfil gestor.');
        }

        $faixa = self::salaryRangeForCargo($cargoId);
        if ($faixa && ($salarioPrevisto < (float)$faixa['salario_min'] || $salarioPrevisto > (float)$faixa['salario_max'])) {
            throw new InvalidArgumentException(sprintf(
                'O salário previsto está fora da faixa permitida para o cargo selecionado (R$ %s a R$ %s).',
                self::formatMoney((float)$faixa['salario_min']),
                self::formatMoney((float)$faixa['salario_max'])
            ));
        }

        $maquinaOperada = trim((string)($input['maquina_operada'] ?? ''));
        if (self::cargoRequiresMachine((string)$cargo['nome']) && $maquinaOperada === '') {
            throw new InvalidArgumentException('Descreva a máquina a ser operada para cargos de Operador de Máquinas Florestais.');
        }
        if (!self::cargoRequiresMachine((string)$cargo['nome'])) {
            $maquinaOperada = '';
        }

        $dataPrevistaInicio = DateHelper::toDatabaseDate($input['data_prevista_inicio'] ?? '');
        $dataLimiteFechamento = DateHelper::toDatabaseDate($input['data_limite_fechamento'] ?? '');
        if (!$dataPrevistaInicio) {
            throw new InvalidArgumentException('Informe a data prevista para início no formato DD/MM/AAAA.');
        }
        if ($dataLimiteFechamento && $dataLimiteFechamento <= $dataPrevistaInicio) {
            throw new InvalidArgumentException('A data limite desejada para fechamento deve ser posterior à data prevista para início.');
        }
        if ($dataPrevistaInicio <= date('Y-m-d')) {
            throw new InvalidArgumentException('A data prevista para início deve ser futura.');
        }

        $justificativaOrcamento = trim((string)($input['justificativa_orcamento'] ?? ''));
        if ($previstoOrcamento === 0 && $justificativaOrcamento === '') {
            throw new InvalidArgumentException('Informe a justificativa quando a vaga não estiver prevista no orçamento anual.');
        }
        if ($previstoOrcamento === 1) {
            $justificativaOrcamento = '';
        }

        $entregasEsperadas = trim((string)($input['entregas_esperadas'] ?? ''));
        if (mb_strlen($entregasEsperadas) < 100) {
            throw new InvalidArgumentException('Descreva as entregas esperadas da função com pelo menos 100 caracteres.');
        }

        $beneficioIds = self::filterSelectedIds($input['beneficio_ids'] ?? []);
        $competenciaTecnicaIds = self::filterSelectedIds($input['competencia_tecnica_ids'] ?? []);
        $competenciaComportamentalIds = self::filterSelectedIds($input['competencia_comportamental_ids'] ?? []);

        self::assertBenefitSelection($cargoId, $beneficioIds);
        self::assertCompetencySelection(self::TIPO_COMPETENCIA_TECNICA, $competenciaTecnicaIds);
        self::assertCompetencySelection(self::TIPO_COMPETENCIA_COMPORTAMENTAL, $competenciaComportamentalIds);

        $colaboradorSubstituidoId = null;
        $dataDesligamento = null;
        $motivoSaida = null;
        $motivoSaidaOutros = '';
        if ($tipoVaga === 'substituicao') {
            $colaboradorSubstituidoId = self::requiredEntityId($input['colaborador_substituido_id'] ?? null, 'colaboradores', 'Nome do colaborador substituído');
            $dataDesligamento = DateHelper::toDatabaseDate($input['data_desligamento'] ?? '');
            if (!$dataDesligamento) {
                throw new InvalidArgumentException('Informe a data do desligamento no formato DD/MM/AAAA.');
            }
            if ($dataDesligamento >= $dataPrevistaInicio) {
                throw new InvalidArgumentException('A data de desligamento do colaborador substituído deve ser anterior à data prevista de início.');
            }
            $motivoSaida = self::requiredEnum(
                $input['motivo_saida'] ?? '',
                ['desligamento', 'promocao', 'transferencia', 'outros'],
                'Motivo da saída'
            );
            $motivoSaidaOutros = trim((string)($input['motivo_saida_outros'] ?? ''));
            if ($motivoSaida === 'outros' && $motivoSaidaOutros === '') {
                throw new InvalidArgumentException('Descreva o motivo da saída quando a opção "Outros" for selecionada.');
            }
            if ($motivoSaida !== 'outros') {
                $motivoSaidaOutros = '';
            }
        }

        return [
            'setor_id' => $setorId,
            'quantidade_vagas' => $quantidadeVagas,
            'cargo_id' => $cargoId,
            'maquina_operada' => $maquinaOperada,
            'gestor_solicitante_colaborador_id' => $gestorColaboradorId,
            'tipo_vaga' => $tipoVaga,
            'colaborador_substituido_id' => $colaboradorSubstituidoId,
            'data_desligamento' => $dataDesligamento,
            'motivo_saida' => $motivoSaida,
            'motivo_saida_outros' => $motivoSaidaOutros,
            'tipo_contratacao' => $tipoContratacao,
            'salario_previsto' => $salarioPrevisto,
            'beneficio_ids' => $beneficioIds,
            'centro_custo_id' => $centroCustoId,
            'previsto_orcamento' => $previstoOrcamento,
            'justificativa_orcamento' => $justificativaOrcamento,
            'jornada_trabalho' => $jornada,
            'escala' => trim((string)($input['escala'] ?? '')),
            'turno' => $turno,
            'escolaridade_minima' => $escolaridade,
            'formacao_academica' => trim((string)($input['formacao_academica'] ?? '')),
            'experiencia_necessaria' => trim((string)($input['experiencia_necessaria'] ?? '')),
            'entregas_esperadas' => $entregasEsperadas,
            'competencia_tecnica_ids' => $competenciaTecnicaIds,
            'competencia_comportamental_ids' => $competenciaComportamentalIds,
            'nivel_responsabilidade' => $nivelResponsabilidade,
            'data_prevista_inicio' => $dataPrevistaInicio,
            'urgencia' => $urgencia,
            'data_limite_fechamento' => $dataLimiteFechamento,
        ];
    }

    private static function seedReferenceData(PDO $pdo): void
    {
        $pdo->exec(
            "INSERT IGNORE INTO cargo_setores (cargo_id, setor_id)
             SELECT DISTINCT cargo_id, setor_id
             FROM colaboradores
             WHERE cargo_id IS NOT NULL AND setor_id IS NOT NULL"
        );

        $totalCentros = (int)$pdo->query("SELECT COUNT(*) FROM centros_custo")->fetchColumn();
        if ($totalCentros === 0) {
            $setores = $pdo->query("SELECT id, nome FROM setores WHERE ativo = 1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $pdo->prepare("INSERT INTO centros_custo (setor_id, codigo, nome, ativo) VALUES (?,?,?,1)");
            foreach ($setores as $setor) {
                $stmt->execute([
                    (int)$setor['id'],
                    sprintf('CC-%03d', (int)$setor['id']),
                    'Centro de custo ' . $setor['nome'],
                ]);
            }
        }

        $totalCompetencias = (int)$pdo->query("SELECT COUNT(*) FROM competencias")->fetchColumn();
        if ($totalCompetencias === 0) {
            $defaults = [
                ['Operação de máquinas', self::TIPO_COMPETENCIA_TECNICA],
                ['Legislação trabalhista', self::TIPO_COMPETENCIA_TECNICA],
                ['Gestão de indicadores', self::TIPO_COMPETENCIA_TECNICA],
                ['Excel avançado', self::TIPO_COMPETENCIA_TECNICA],
                ['Comunicação assertiva', self::TIPO_COMPETENCIA_COMPORTAMENTAL],
                ['Liderança', self::TIPO_COMPETENCIA_COMPORTAMENTAL],
                ['Senso de urgência', self::TIPO_COMPETENCIA_COMPORTAMENTAL],
                ['Trabalho em equipe', self::TIPO_COMPETENCIA_COMPORTAMENTAL],
            ];
            $stmt = $pdo->prepare("INSERT INTO competencias (nome, tipo, ativo) VALUES (?,?,1)");
            foreach ($defaults as [$nome, $tipo]) {
                $stmt->execute([$nome, $tipo]);
            }
        }

        $totalFaixas = (int)$pdo->query("SELECT COUNT(*) FROM cargo_faixas_salariais")->fetchColumn();
        if ($totalFaixas === 0) {
            $cargos = $pdo->query("SELECT id, nome FROM cargos ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
            $stmtFaixa = $pdo->prepare("INSERT INTO cargo_faixas_salariais (cargo_id, salario_min, salario_max, ativo) VALUES (?,?,?,1)");
            foreach ($cargos as $cargo) {
                [$min, $max] = self::estimateSalaryRange((string)$cargo['nome']);
                $stmtFaixa->execute([(int)$cargo['id'], $min, $max]);
            }
        }

        $totalBeneficiosVinculados = (int)$pdo->query("SELECT COUNT(*) FROM cargo_beneficios")->fetchColumn();
        if ($totalBeneficiosVinculados === 0 && self::tableExists('beneficios')) {
            $cargos = $pdo->query("SELECT id FROM cargos WHERE ativo = 1")->fetchAll(PDO::FETCH_COLUMN);
            $beneficios = $pdo->query("SELECT id FROM beneficios WHERE ativo = 1")->fetchAll(PDO::FETCH_COLUMN);
            $stmt = $pdo->prepare("INSERT IGNORE INTO cargo_beneficios (cargo_id, beneficio_id) VALUES (?, ?)");
            foreach ($cargos as $cargoId) {
                foreach ($beneficios as $beneficioId) {
                    $stmt->execute([(int)$cargoId, (int)$beneficioId]);
                }
            }
        }

        $existingLinks = (int)$pdo->query("SELECT COUNT(*) FROM usuario_colaboradores")->fetchColumn();
        if ($existingLinks === 0) {
            $users = $pdo->query("SELECT id, nome, role FROM usuarios")->fetchAll(PDO::FETCH_ASSOC);
            $colaboradores = $pdo->query("SELECT c.id, c.nome, c.setor_id, cg.nome AS cargo_nome FROM colaboradores c INNER JOIN cargos cg ON cg.id = c.cargo_id")->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $pdo->prepare(
                "INSERT INTO usuario_colaboradores (usuario_id, colaborador_id, is_gestor, is_rh, lider_colaborador_id, ativo)
                 VALUES (?,?,?,?,?,1)"
            );
            foreach ($users as $user) {
                $match = self::bestMatchingColaborador((string)$user['nome'], $colaboradores);
                if (!$match) {
                    continue;
                }
                $isRh = strtolower(trim((string)$user['role'])) === 'rh' ? 1 : 0;
                $isGestor = self::isLeadershipTitle((string)$match['cargo_nome']) || strtolower(trim((string)$user['role'])) === 'admin' ? 1 : 0;
                $leaderColaboradorId = self::inferLeaderColaboradorId((int)$match['id'], (int)($match['setor_id'] ?? 0), $colaboradores);
                $stmt->execute([
                    (int)$user['id'],
                    (int)$match['id'],
                    $isGestor,
                    $isRh,
                    $leaderColaboradorId,
                ]);
            }
        }

        $countGestores = (int)$pdo->query("SELECT COUNT(*) FROM usuario_colaboradores WHERE ativo = 1 AND is_gestor = 1")->fetchColumn();
        if ($countGestores === 0) {
            $fallbackUser = $pdo->query(
                "SELECT id, role
                 FROM usuarios
                 ORDER BY is_supervisor DESC, FIELD(role, 'admin', 'rh', 'viewer'), id ASC
                 LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);

            if ($fallbackUser) {
                $existingLink = $pdo->prepare("SELECT * FROM usuario_colaboradores WHERE usuario_id = ? LIMIT 1");
                $existingLink->execute([(int)$fallbackUser['id']]);
                $currentLink = $existingLink->fetch(PDO::FETCH_ASSOC);

                if ($currentLink) {
                    $pdo->prepare(
                        "UPDATE usuario_colaboradores
                         SET is_gestor = 1, is_rh = ?, ativo = 1
                         WHERE usuario_id = ?"
                    )->execute([
                        strtolower((string)$fallbackUser['role']) === 'rh' ? 1 : 0,
                        (int)$fallbackUser['id'],
                    ]);
                } else {
                    $leadershipCandidates = $pdo->query(
                        "SELECT c.id, c.setor_id, cg.nome AS cargo_nome
                         FROM colaboradores c
                         INNER JOIN cargos cg ON cg.id = c.cargo_id
                         WHERE c.ativo = 1
                         ORDER BY c.nome ASC"
                    )->fetchAll(PDO::FETCH_ASSOC);

                    usort($leadershipCandidates, static function (array $left, array $right): int {
                        return self::hierarchyScore((string)$right['cargo_nome']) <=> self::hierarchyScore((string)$left['cargo_nome']);
                    });

                    $leaderCandidate = null;
                    foreach ($leadershipCandidates as $candidate) {
                        if (self::hierarchyScore((string)$candidate['cargo_nome']) > 0) {
                            $leaderCandidate = $candidate;
                            break;
                        }
                    }

                    if ($leaderCandidate) {
                        $pdo->prepare(
                            "INSERT IGNORE INTO usuario_colaboradores (usuario_id, colaborador_id, is_gestor, is_rh, lider_colaborador_id, ativo)
                             VALUES (?, ?, 1, ?, NULL, 1)"
                        )->execute([
                            (int)$fallbackUser['id'],
                            (int)$leaderCandidate['id'],
                            strtolower((string)$fallbackUser['role']) === 'rh' ? 1 : 0,
                        ]);
                    }
                }
            }
        }
    }

    private static function approvalStatus(int $solicitacaoId): array
    {
        $stmt = Database::conn()->prepare(
            "SELECT sa.etapa, sa.status, sa.aprovado_em, sa.destinatario_usuario_id, sa.aprovador_usuario_id,
                    u_dest.nome AS destinatario_nome, u_apr.nome AS aprovador_nome
             FROM solicitacao_vaga_aprovacoes sa
             LEFT JOIN usuarios u_dest ON u_dest.id = sa.destinatario_usuario_id
             LEFT JOIN usuarios u_apr ON u_apr.id = sa.aprovador_usuario_id
             WHERE sa.solicitacao_id = ?
             ORDER BY sa.id ASC"
        );
        $stmt->execute([$solicitacaoId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function auditTrail(int $solicitacaoId): array
    {
        $stmt = Database::conn()->prepare(
            "SELECT sa.*, u.nome AS actor_nome
             FROM solicitacao_vaga_auditoria sa
             LEFT JOIN usuarios u ON u.id = sa.actor_usuario_id
             WHERE sa.solicitacao_id = ?
             ORDER BY sa.created_at DESC"
        );
        $stmt->execute([$solicitacaoId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static function (array $row): array {
            $row['old_value'] = Cipher::decrypt($row['old_value_encrypted'] ?? null);
            $row['new_value'] = Cipher::decrypt($row['new_value_encrypted'] ?? null);
            return $row;
        }, $rows);
    }

    private static function selectedBenefits(int $solicitacaoId): array
    {
        $stmt = Database::conn()->prepare(
            "SELECT b.id, b.nome
             FROM solicitacao_vaga_beneficios sb
             INNER JOIN beneficios b ON b.id = sb.beneficio_id
             WHERE sb.solicitacao_id = ?
             ORDER BY b.nome ASC"
        );
        $stmt->execute([$solicitacaoId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function selectedCompetencies(int $solicitacaoId, string $type): array
    {
        $stmt = Database::conn()->prepare(
            "SELECT c.id, c.nome
             FROM solicitacao_vaga_competencias sc
             INNER JOIN competencias c ON c.id = sc.competencia_id
             WHERE sc.solicitacao_id = ? AND sc.tipo = ?
             ORDER BY c.nome ASC"
        );
        $stmt->execute([$solicitacaoId, $type]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function seedApprovalRows(int $solicitacaoId, ?int $leaderUserId): void
    {
        $stmt = Database::conn()->prepare(
            "INSERT INTO solicitacao_vaga_aprovacoes (solicitacao_id, etapa, destinatario_usuario_id, status)
             VALUES (?, ?, ?, 'pendente')"
        );
        $stmt->execute([$solicitacaoId, 'lider_imediato', $leaderUserId]);
        $stmt->execute([$solicitacaoId, 'rh', null]);
    }

    private static function syncBenefits(int $solicitacaoId, array $beneficioIds): void
    {
        $pdo = Database::conn();
        $pdo->prepare("DELETE FROM solicitacao_vaga_beneficios WHERE solicitacao_id = ?")->execute([$solicitacaoId]);
        if ($beneficioIds === []) {
            return;
        }

        $stmt = $pdo->prepare("INSERT INTO solicitacao_vaga_beneficios (solicitacao_id, beneficio_id) VALUES (?, ?)");
        foreach ($beneficioIds as $beneficioId) {
            $stmt->execute([$solicitacaoId, $beneficioId]);
        }
    }

    private static function syncCompetencies(int $solicitacaoId, array $competenciaIds, string $type): void
    {
        $pdo = Database::conn();
        $pdo->prepare("DELETE FROM solicitacao_vaga_competencias WHERE solicitacao_id = ? AND tipo = ?")->execute([$solicitacaoId, $type]);
        if ($competenciaIds === []) {
            return;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO solicitacao_vaga_competencias (solicitacao_id, competencia_id, tipo) VALUES (?, ?, ?)"
        );
        foreach ($competenciaIds as $competenciaId) {
            $stmt->execute([$solicitacaoId, $competenciaId, $type]);
        }
    }

    private static function resolveLeaderAssignment(int $gestorColaboradorId, int $setorId): array
    {
        $gestorLink = self::findUserColaboradorByColaboradorId($gestorColaboradorId);
        if ($gestorLink && !empty($gestorLink['lider_colaborador_id'])) {
            $leaderLink = self::findUserColaboradorByColaboradorId((int)$gestorLink['lider_colaborador_id']);
            if ($leaderLink) {
                return [
                    'colaborador_id' => (int)$leaderLink['colaborador_id'],
                    'usuario_id' => (int)$leaderLink['usuario_id'],
                ];
            }
        }

        $fallback = self::findFallbackLeaderBySetor($setorId, $gestorColaboradorId);
        if ($fallback) {
            return $fallback;
        }

        if ($gestorLink) {
            return [
                'colaborador_id' => (int)$gestorLink['colaborador_id'],
                'usuario_id' => (int)$gestorLink['usuario_id'],
            ];
        }

        throw new InvalidArgumentException('Não foi possível identificar o líder imediato do gestor solicitante. Configure o vínculo gestor/líder na base de usuários.');
    }

    private static function findFallbackLeaderBySetor(int $setorId, int $excludeColaboradorId): ?array
    {
        $stmt = Database::conn()->prepare(
            "SELECT uc.usuario_id, uc.colaborador_id, cg.nome AS cargo_nome
             FROM usuario_colaboradores uc
             INNER JOIN colaboradores c ON c.id = uc.colaborador_id
             INNER JOIN cargos cg ON cg.id = c.cargo_id
             WHERE uc.ativo = 1 AND c.setor_id = ? AND c.id <> ?
             ORDER BY c.nome ASC"
        );
        $stmt->execute([$setorId, $excludeColaboradorId]);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        usort($candidates, static function (array $left, array $right): int {
            return self::hierarchyScore((string)$right['cargo_nome']) <=> self::hierarchyScore((string)$left['cargo_nome']);
        });

        foreach ($candidates as $candidate) {
            if (self::hierarchyScore((string)$candidate['cargo_nome']) > 0) {
                return [
                    'usuario_id' => (int)$candidate['usuario_id'],
                    'colaborador_id' => (int)$candidate['colaborador_id'],
                ];
            }
        }

        return null;
    }

    private static function userAccessProfile(int $userId): ?array
    {
        $stmt = Database::conn()->prepare(
            "SELECT uc.*, c.nome AS colaborador_nome, c.setor_id, cg.nome AS cargo_nome
             FROM usuario_colaboradores uc
             INNER JOIN colaboradores c ON c.id = uc.colaborador_id
             INNER JOIN cargos cg ON cg.id = c.cargo_id
             WHERE uc.usuario_id = ? AND uc.ativo = 1
             LIMIT 1"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function findUserColaboradorByColaboradorId(int $colaboradorId): ?array
    {
        $stmt = Database::conn()->prepare(
            "SELECT uc.*, c.setor_id
             FROM usuario_colaboradores uc
             INNER JOIN colaboradores c ON c.id = uc.colaborador_id
             WHERE uc.colaborador_id = ? AND uc.ativo = 1
             LIMIT 1"
        );
        $stmt->execute([$colaboradorId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function findRaw(int $id): ?array
    {
        $stmt = Database::conn()->prepare("SELECT * FROM solicitacoes_vaga WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function approvalRow(int $solicitacaoId, string $step): ?array
    {
        $stmt = Database::conn()->prepare(
            "SELECT * FROM solicitacao_vaga_aprovacoes WHERE solicitacao_id = ? AND etapa = ? LIMIT 1"
        );
        $stmt->execute([$solicitacaoId, $step]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function hydrateRecord(array $row): array
    {
        $row['maquina_operada'] = Cipher::decrypt($row['maquina_operada_encrypted'] ?? null) ?? '';
        $row['motivo_saida_outros'] = Cipher::decrypt($row['motivo_saida_outros_encrypted'] ?? null) ?? '';
        $row['justificativa_orcamento'] = Cipher::decrypt($row['justificativa_orcamento_encrypted'] ?? null) ?? '';
        $row['escala'] = Cipher::decrypt($row['escala_encrypted'] ?? null) ?? '';
        $row['formacao_academica'] = Cipher::decrypt($row['formacao_academica_encrypted'] ?? null) ?? '';
        $row['experiencia_necessaria'] = Cipher::decrypt($row['experiencia_necessaria_encrypted'] ?? null) ?? '';
        $row['entregas_esperadas'] = Cipher::decrypt($row['entregas_esperadas_encrypted'] ?? null) ?? '';
        $row['observacoes_rh'] = Cipher::decrypt($row['observacoes_rh_encrypted'] ?? null) ?? '';
        $row['data_prevista_inicio_br'] = DateHelper::formatBrazilianDate($row['data_prevista_inicio'] ?? '');
        $row['data_limite_fechamento_br'] = DateHelper::formatBrazilianDate($row['data_limite_fechamento'] ?? '');
        $row['data_desligamento_br'] = DateHelper::formatBrazilianDate($row['data_desligamento'] ?? '');
        $row['data_admissao_br'] = DateHelper::formatBrazilianDate($row['data_admissao'] ?? '');
        return $row;
    }

    private static function logAudit(int $solicitacaoId, int $actorUserId, string $eventType, ?string $fieldName, $oldValue, $newValue, ?string $ip): void
    {
        $stmt = Database::conn()->prepare(
            "INSERT INTO solicitacao_vaga_auditoria
                (solicitacao_id, actor_usuario_id, event_type, field_name, old_value_encrypted, new_value_encrypted, ip)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $solicitacaoId,
            $actorUserId,
            $eventType,
            $fieldName,
            self::encryptNullable(self::stringifyAuditValue($oldValue)),
            self::encryptNullable(self::stringifyAuditValue($newValue)),
            $ip,
        ]);
    }

    private static function stringifyAuditValue($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_scalar($value)) {
            return (string)$value;
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function assertBenefitSelection(int $cargoId, array $selectedIds): void
    {
        if ($selectedIds === []) {
            return;
        }

        $available = array_column(self::availableBenefitsForCargo($cargoId), 'id');
        foreach ($selectedIds as $beneficioId) {
            if (!in_array($beneficioId, $available, true)) {
                throw new InvalidArgumentException('Foi selecionado um benefício que não está vinculado ao cargo informado.');
            }
        }
    }

    private static function assertCompetencySelection(string $type, array $selectedIds): void
    {
        if ($selectedIds === []) {
            return;
        }

        $stmt = Database::conn()->prepare("SELECT id FROM competencias WHERE tipo = ? AND ativo = 1");
        $stmt->execute([$type]);
        $available = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        foreach ($selectedIds as $competenciaId) {
            if (!in_array($competenciaId, $available, true)) {
                throw new InvalidArgumentException('Foi selecionada uma competência não cadastrada ou incompatível com o tipo informado.');
            }
        }
    }

    private static function availableBenefitsForCargo(int $cargoId): array
    {
        $stmt = Database::conn()->prepare(
            "SELECT b.id, b.nome
             FROM cargo_beneficios cb
             INNER JOIN beneficios b ON b.id = cb.beneficio_id
             WHERE cb.cargo_id = ? AND b.ativo = 1
             ORDER BY b.nome ASC"
        );
        $stmt->execute([$cargoId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($items !== []) {
            return array_map(static function (array $row): array {
                return ['id' => (int)$row['id'], 'nome' => $row['nome']];
            }, $items);
        }

        if (!self::tableExists('beneficios')) {
            return [];
        }

        return array_map(static function (array $row): array {
            return ['id' => (int)$row['id'], 'nome' => $row['nome']];
        }, Beneficio::allActive());
    }

    private static function cargoBelongsToSetor(int $cargoId, int $setorId): bool
    {
        $stmt = Database::conn()->prepare(
            "SELECT COUNT(*) FROM cargo_setores WHERE cargo_id = ? AND setor_id = ?"
        );
        $stmt->execute([$cargoId, $setorId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private static function salaryRangeForCargo(int $cargoId): ?array
    {
        $stmt = Database::conn()->prepare(
            "SELECT salario_min, salario_max FROM cargo_faixas_salariais WHERE cargo_id = ? AND ativo = 1 LIMIT 1"
        );
        $stmt->execute([$cargoId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function requiredEntityId($value, string $table, string $label): int
    {
        $id = self::nullableInt($value);
        if (!$id || !self::findSimple($table, $id)) {
            throw new InvalidArgumentException(sprintf('Selecione um valor válido para "%s".', $label));
        }
        return $id;
    }

    private static function requiredEnum($value, array $allowed, string $label): string
    {
        $value = strtolower(trim((string)$value));
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException(sprintf('Selecione uma opção válida para "%s".', $label));
        }
        return $value;
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

        if (!is_numeric($normalized)) {
            return null;
        }

        return round((float)$normalized, 2);
    }

    private static function formatMoney(float $value): string
    {
        return number_format($value, 2, ',', '.');
    }

    private static function filterSelectedIds($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $item) {
            $id = self::nullableInt($item);
            if ($id) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private static function nullableInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = (int)$value;
        return $id > 0 ? $id : null;
    }

    private static function findSimple(string $table, int $id): ?array
    {
        $stmt = Database::conn()->prepare(sprintf("SELECT * FROM %s WHERE id = ? LIMIT 1", $table));
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function tableExists(string $table): bool
    {
        $stmt = Database::conn()->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private static function cargoRequiresMachine(string $cargoName): bool
    {
        $normalized = self::normalize($cargoName);
        return str_contains($normalized, 'operador de maquina florestal')
            || str_contains($normalized, 'operador de maquinas florestais');
    }

    private static function encryptNullable(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);
        return $value === null || $value === '' ? null : Cipher::encrypt($value);
    }

    private static function encryptRequired(string $value): string
    {
        return Cipher::encrypt($value) ?? '';
    }

    private static function isRhOrAdmin(int $userId): bool
    {
        $stmt = Database::conn()->prepare("SELECT role, is_supervisor FROM usuarios WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        return (int)($row['is_supervisor'] ?? 0) === 1 || in_array(strtolower((string)$row['role']), ['admin', 'rh'], true);
    }

    private static function bestMatchingColaborador(string $userName, array $colaboradores): ?array
    {
        $normalizedUser = self::normalize($userName);
        $best = null;
        $bestScore = 0;

        foreach ($colaboradores as $colaborador) {
            $normalizedColaborador = self::normalize((string)$colaborador['nome']);
            similar_text($normalizedUser, $normalizedColaborador, $score);
            if ($normalizedUser === $normalizedColaborador) {
                return $colaborador;
            }
            if ($score > $bestScore) {
                $best = $colaborador;
                $bestScore = (int)$score;
            }
        }

        return $bestScore >= 68 ? $best : null;
    }

    private static function inferLeaderColaboradorId(int $colaboradorId, int $setorId, array $colaboradores): ?int
    {
        $bestId = null;
        $bestScore = 0;
        foreach ($colaboradores as $colaborador) {
            if ((int)$colaborador['id'] === $colaboradorId || (int)($colaborador['setor_id'] ?? 0) !== $setorId) {
                continue;
            }
            $score = self::hierarchyScore((string)$colaborador['cargo_nome']);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestId = (int)$colaborador['id'];
            }
        }
        return $bestId;
    }

    private static function estimateSalaryRange(string $cargoName): array
    {
        $normalized = self::normalize($cargoName);
        if (str_contains($normalized, 'diretor')) {
            return [12000.00, 30000.00];
        }
        if (str_contains($normalized, 'gerente')) {
            return [8000.00, 18000.00];
        }
        if (str_contains($normalized, 'supervisor') || str_contains($normalized, 'coordenador') || str_contains($normalized, 'lider')) {
            return [4500.00, 12000.00];
        }
        if (str_contains($normalized, 'analista') || str_contains($normalized, 'contador') || str_contains($normalized, 'controller')) {
            return [3500.00, 9500.00];
        }
        if (str_contains($normalized, 'tecnico') || str_contains($normalized, 'programador')) {
            return [3000.00, 8000.00];
        }
        if (str_contains($normalized, 'operador') || str_contains($normalized, 'motorista') || str_contains($normalized, 'mecanico')) {
            return [2200.00, 6500.00];
        }
        if (str_contains($normalized, 'assistente') || str_contains($normalized, 'auxiliar')) {
            return [1800.00, 4500.00];
        }
        return [1800.00, 9000.00];
    }

    private static function isLeadershipTitle(string $cargoName): bool
    {
        return self::hierarchyScore($cargoName) > 0;
    }

    private static function hierarchyScore(string $cargoName): int
    {
        $normalized = self::normalize($cargoName);
        if (str_contains($normalized, 'diretor')) {
            return 100;
        }
        if (str_contains($normalized, 'gerente')) {
            return 90;
        }
        if (str_contains($normalized, 'coordenador')) {
            return 80;
        }
        if (str_contains($normalized, 'supervisor')) {
            return 70;
        }
        if (str_contains($normalized, 'lider')) {
            return 60;
        }
        if (str_contains($normalized, 'encarregado')) {
            return 50;
        }
        return 0;
    }

    private static function normalize(string $value): string
    {
        $value = trim(mb_strtolower($value, 'UTF-8'));
        $replace = [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c',
        ];
        return strtr($value, $replace);
    }
}
