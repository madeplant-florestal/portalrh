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
        ('Novo', 1, '#3b82f6'), 
        ('Triagem', 2, '#f59e0b'), 
        ('Entrevista', 3, '#8b5cf6'), 
        ('Proposta', 4, '#1d2d44'), 
        ('Contratado', 5, '#1d2d44'), 
        ('Rejeitado', 6, '#ef4444')");
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
