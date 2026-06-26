<?php

$host = '127.0.0.1';
$user = 'root';
$pass = '';
$dbname = 'rhmadeplant';

try {
    // 1. Connect without DB
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 2. Create DB
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "Database '$dbname' criada ou já existe.\n";

    // 3. Connect to DB
    $pdo->exec("USE `$dbname`");

    // 4. Import root SQL when available
    $schemaFile = __DIR__ . '/../recrutamento.sql';
    if (!file_exists($schemaFile)) {
        $schemaFile = __DIR__ . '/../database/schema.sql';
    }
    if (file_exists($schemaFile)) {
        $sql = file_get_contents($schemaFile);
        $pdo->exec($sql);
        echo "SQL importado de " . basename($schemaFile) . ".\n";
    } else {
        echo "Aviso: arquivo SQL de instalação não encontrado.\n";
    }

    // 5. Run New Migrations (Pipeline, etc.)
    
    // Tabela Requisitos
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS requisitos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vaga_id INT NOT NULL,
        descricao VARCHAR(255) NOT NULL,
        obrigatorio TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_req_vaga FOREIGN KEY (vaga_id) REFERENCES vagas(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Tabela Pipeline Stages
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS pipeline_stages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(50) NOT NULL,
        ordem INT NOT NULL DEFAULT 0,
        cor VARCHAR(7) DEFAULT '#cccccc',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Seed Stages
    $stmt = $pdo->query("SELECT COUNT(*) FROM pipeline_stages");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO pipeline_stages (nome, ordem, cor) VALUES 
        ('Nova Inscrição', 1, '#3b82f6'),
        ('Triagem RH', 2, '#f59e0b'),
        ('Entrevista RH', 3, '#8b5cf6'),
        ('Entrevista Gestor', 4, '#6366f1'),
        ('Testes', 5, '#0ea5e9'),
        ('Aprovado', 6, '#1d4ed8'),
        ('Admissão', 7, '#059669'),
        ('Banco de Talentos', 8, '#7c3aed'),
        ('Reprovado', 9, '#ef4444')");
        echo "Stages inseridos.\n";
    }

    // Tabela Movimentações
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS pipeline_movements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        candidatura_id INT NOT NULL,
        stage_anterior_id INT DEFAULT NULL,
        stage_novo_id INT NOT NULL,
        usuario_id INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_mov_cand FOREIGN KEY (candidatura_id) REFERENCES candidaturas(id) ON DELETE CASCADE,
        CONSTRAINT fk_mov_stage_ant FOREIGN KEY (stage_anterior_id) REFERENCES pipeline_stages(id) ON DELETE SET NULL,
        CONSTRAINT fk_mov_stage_new FOREIGN KEY (stage_novo_id) REFERENCES pipeline_stages(id) ON DELETE CASCADE,
        CONSTRAINT fk_mov_user FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Tabela Notas
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS notas_recrutador (
        id INT AUTO_INCREMENT PRIMARY KEY,
        candidatura_id INT NOT NULL,
        usuario_id INT NOT NULL,
        nota TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_nota_cand FOREIGN KEY (candidatura_id) REFERENCES candidaturas(id) ON DELETE CASCADE,
        CONSTRAINT fk_nota_user FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $stmt = $pdo->prepare("SHOW COLUMNS FROM vagas LIKE 'empresa_id'");
    $stmt->execute();
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE vagas ADD COLUMN empresa_id INT NULL AFTER local");
        $pdo->exec("ALTER TABLE vagas ADD INDEX idx_vagas_empresa_id (empresa_id)");
        $pdo->exec("ALTER TABLE vagas ADD CONSTRAINT fk_vagas_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE SET NULL ON UPDATE CASCADE");
        echo "Coluna empresa_id adicionada em vagas.\n";
    }

    $colaboradoresRequiredColumns = [
        'matricula' => "ALTER TABLE colaboradores ADD COLUMN matricula VARCHAR(30) NULL AFTER nome",
        'codigo' => "ALTER TABLE colaboradores ADD COLUMN codigo VARCHAR(30) NULL AFTER matricula",
        'cpf' => "ALTER TABLE colaboradores ADD COLUMN cpf VARCHAR(11) NULL AFTER codigo",
        'salario_atual' => "ALTER TABLE colaboradores ADD COLUMN salario_atual DECIMAL(12,2) NULL AFTER setor_id",
        'data_admissao' => "ALTER TABLE colaboradores ADD COLUMN data_admissao DATE NULL AFTER salario_atual",
        'data_inicio_cargo' => "ALTER TABLE colaboradores ADD COLUMN data_inicio_cargo DATE NULL AFTER data_admissao",
        'data_nascimento' => "ALTER TABLE colaboradores ADD COLUMN data_nascimento DATE NULL AFTER data_inicio_cargo",
        'data_demissao' => "ALTER TABLE colaboradores ADD COLUMN data_demissao DATE NULL AFTER data_nascimento",
        'motivo_rescisao' => "ALTER TABLE colaboradores ADD COLUMN motivo_rescisao VARCHAR(255) NULL AFTER data_demissao",
    ];
    foreach ($colaboradoresRequiredColumns as $column => $sql) {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM colaboradores LIKE ?");
        $stmt->execute([$column]);
        if (!$stmt->fetch()) {
            $pdo->exec($sql);
            echo "Coluna {$column} adicionada em colaboradores.\n";
        }
    }

    $colaboradoresRequiredIndexes = [
        'idx_colaboradores_codigo' => 'ALTER TABLE colaboradores ADD INDEX idx_colaboradores_codigo (codigo)',
        'idx_colaboradores_cpf' => 'ALTER TABLE colaboradores ADD INDEX idx_colaboradores_cpf (cpf)',
        'idx_colaboradores_data_admissao' => 'ALTER TABLE colaboradores ADD INDEX idx_colaboradores_data_admissao (data_admissao)',
        'idx_colaboradores_data_demissao' => 'ALTER TABLE colaboradores ADD INDEX idx_colaboradores_data_demissao (data_demissao)',
    ];
    foreach ($colaboradoresRequiredIndexes as $index => $sql) {
        $stmt = $pdo->prepare("SHOW INDEX FROM colaboradores WHERE Key_name = ?");
        $stmt->execute([$index]);
        if (!$stmt->fetch()) {
            $pdo->exec($sql);
            echo "Índice {$index} adicionado em colaboradores.\n";
        }
    }

    $pdo->exec("UPDATE colaboradores
                SET codigo = NULLIF(TRIM(matricula), '')
                WHERE (codigo IS NULL OR codigo = '')
                  AND matricula IS NOT NULL
                  AND TRIM(matricula) <> ''");
    $pdo->exec("UPDATE colaboradores
                SET codigo = CONCAT('COL', LPAD(id, 6, '0'))
                WHERE codigo IS NULL OR codigo = ''");
    $pdo->exec("UPDATE colaboradores
                SET cpf = REGEXP_REPLACE(cpf, '[^0-9]', '')
                WHERE cpf IS NOT NULL AND cpf <> ''");
    $pdo->exec("UPDATE colaboradores
                SET cpf = NULL
                WHERE cpf IS NOT NULL AND cpf <> '' AND CHAR_LENGTH(cpf) <> 11");

    $pdo->exec("
    CREATE TABLE IF NOT EXISTS candidatura_stage_metadata (
        id INT AUTO_INCREMENT PRIMARY KEY,
        candidatura_id INT NOT NULL,
        interview_date DATE NULL,
        interview_time TIME NULL,
        interview_location VARCHAR(255) NULL,
        interview_link VARCHAR(255) NULL,
        admission_date DATE NULL,
        admission_notes TEXT NULL,
        test_name VARCHAR(150) NULL,
        deadline DATE NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_candidatura_stage_metadata_candidatura (candidatura_id),
        KEY idx_candidatura_stage_metadata_deadline (deadline),
        CONSTRAINT fk_candidatura_stage_metadata_candidatura FOREIGN KEY (candidatura_id) REFERENCES candidaturas(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
    CREATE TABLE IF NOT EXISTS recruitment_webhook_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        scope_key VARCHAR(100) NOT NULL,
        empresa_id INT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        webhook_url VARCHAR(500) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_recruitment_webhook_settings_scope (scope_key),
        KEY idx_recruitment_webhook_settings_empresa (empresa_id),
        CONSTRAINT fk_recruitment_webhook_settings_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
    CREATE TABLE IF NOT EXISTS webhook_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NULL,
        event_type VARCHAR(80) NOT NULL,
        payload_json LONGTEXT NOT NULL,
        webhook_url VARCHAR(500) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        response_code INT NULL,
        response_body MEDIUMTEXT NULL,
        last_error TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        processed_at DATETIME NULL,
        retry_count INT NOT NULL DEFAULT 0,
        KEY idx_webhook_events_status (status),
        KEY idx_webhook_events_event_type (event_type),
        KEY idx_webhook_events_tenant (tenant_id),
        KEY idx_webhook_events_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Atualizar Candidaturas com stage_id
    $stmt = $pdo->prepare("SHOW COLUMNS FROM candidaturas LIKE 'stage_id'");
    $stmt->execute();
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE candidaturas ADD COLUMN stage_id INT DEFAULT 1");
        $pdo->exec("ALTER TABLE candidaturas ADD CONSTRAINT fk_cand_stage FOREIGN KEY (stage_id) REFERENCES pipeline_stages(id) ON DELETE SET NULL");
        $pdo->exec("UPDATE candidaturas SET stage_id = 1 WHERE stage_id IS NULL");
        echo "Coluna stage_id adicionada.\n";
    }

    // Create Admin User if not exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE email = ?");
    $stmt->execute(['fabio.ozuna@madeplant.com.br']);
    if ($stmt->fetchColumn() == 0) {
        $passHash = password_hash('23082524', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO usuarios (nome, email, senha_hash, role) VALUES (?, ?, ?, ?)")
            ->execute(['Fabio Ozuna', 'fabio.ozuna@madeplant.com.br', $passHash, 'admin']);
        echo "Usuário administrador criado (fabio.ozuna@madeplant.com.br / 23082524).\n";
    }

    echo "Configuração do banco concluída com sucesso.\n";

} catch (PDOException $e) {
    echo "Erro PDO: " . $e->getMessage() . "\n";
}
