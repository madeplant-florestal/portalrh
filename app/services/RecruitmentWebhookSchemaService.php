<?php
class RecruitmentWebhookSchemaService
{
    private static bool $ensured = false;

    public static function ensureSchema(): void
    {
        if (self::$ensured) {
            return;
        }
        self::$ensured = true;

        try {
            $pdo = Database::conn();

            if (self::tableExists($pdo, 'vagas')) {
                if (!self::columnExists($pdo, 'vagas', 'empresa_id')) {
                    $pdo->exec('ALTER TABLE vagas ADD COLUMN empresa_id INT NULL AFTER local');
                }
                if (!self::indexExists($pdo, 'vagas', 'idx_vagas_empresa_id')) {
                    $pdo->exec('ALTER TABLE vagas ADD INDEX idx_vagas_empresa_id (empresa_id)');
                }
                if (self::tableExists($pdo, 'empresas') && !self::constraintExists($pdo, 'vagas', 'fk_vagas_empresa')) {
                    $pdo->exec('ALTER TABLE vagas ADD CONSTRAINT fk_vagas_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE SET NULL ON UPDATE CASCADE');
                }
            }

            if (self::tableExists($pdo, 'candidaturas')) {
                $pdo->exec("CREATE TABLE IF NOT EXISTS candidatura_stage_metadata (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            }

            $pdo->exec("CREATE TABLE IF NOT EXISTS recruitment_webhook_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                scope_key VARCHAR(100) NOT NULL,
                empresa_id INT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 0,
                webhook_url VARCHAR(500) NULL,
                webhook_secret_encrypted VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_recruitment_webhook_settings_scope (scope_key),
                KEY idx_recruitment_webhook_settings_empresa (empresa_id),
                CONSTRAINT fk_recruitment_webhook_settings_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            if (!self::columnExists($pdo, 'recruitment_webhook_settings', 'webhook_secret_encrypted')) {
                $pdo->exec('ALTER TABLE recruitment_webhook_settings ADD COLUMN webhook_secret_encrypted VARCHAR(255) NULL AFTER webhook_url');
            }

            $pdo->exec("CREATE TABLE IF NOT EXISTS webhook_events (
                id INT AUTO_INCREMENT PRIMARY KEY,
                empresa_id INT NULL,
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
                KEY idx_webhook_events_empresa (empresa_id),
                KEY idx_webhook_events_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Upgrade de instalações anteriores: `tenant_id` foi renomeado para `empresa_id`
            // quando a configuração de webhook deixou de ser por empresa e virou global.
            if (self::columnExists($pdo, 'webhook_events', 'tenant_id') && !self::columnExists($pdo, 'webhook_events', 'empresa_id')) {
                $pdo->exec('ALTER TABLE webhook_events CHANGE COLUMN tenant_id empresa_id INT NULL');
            }

            $pdo->exec("CREATE TABLE IF NOT EXISTS recruitment_webhook_global_settings (
                id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                enabled TINYINT(1) NOT NULL DEFAULT 0,
                webhook_url VARCHAR(500) NULL,
                webhook_secret_encrypted VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec('INSERT IGNORE INTO recruitment_webhook_global_settings (id, enabled, webhook_url, webhook_secret_encrypted) VALUES (1, 0, NULL, NULL)');

            if (!self::columnExists($pdo, 'pipeline_stages', 'slug')) {
                $pdo->exec('ALTER TABLE pipeline_stages ADD COLUMN slug VARCHAR(50) NULL AFTER nome');
            }
            if (!self::indexExists($pdo, 'pipeline_stages', 'uk_pipeline_stages_slug')) {
                $pdo->exec('ALTER TABLE pipeline_stages ADD UNIQUE KEY uk_pipeline_stages_slug (slug)');
            }

            if (!self::columnExists($pdo, 'webhook_events', 'event_id')) {
                $pdo->exec('ALTER TABLE webhook_events ADD COLUMN event_id VARCHAR(40) NULL AFTER id');
            }
            if (!self::indexExists($pdo, 'webhook_events', 'uk_webhook_events_event_id')) {
                $pdo->exec('ALTER TABLE webhook_events ADD UNIQUE KEY uk_webhook_events_event_id (event_id)');
            }
            if (!self::columnExists($pdo, 'webhook_events', 'movement_id')) {
                $pdo->exec('ALTER TABLE webhook_events ADD COLUMN movement_id INT NULL AFTER empresa_id');
            }
            if (!self::indexExists($pdo, 'webhook_events', 'idx_webhook_events_movement')) {
                $pdo->exec('ALTER TABLE webhook_events ADD INDEX idx_webhook_events_movement (movement_id)');
            }
            if (!self::columnExists($pdo, 'webhook_events', 'next_retry_at')) {
                $pdo->exec('ALTER TABLE webhook_events ADD COLUMN next_retry_at DATETIME NULL AFTER processed_at');
            }
        } catch (Throwable $e) {
            // Mantém o sistema funcional caso o ambiente não permita DDL em runtime.
        }
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private static function indexExists(PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $stmt->execute([$table, $index]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private static function constraintExists(PDO $pdo, string $table, string $constraint): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?'
        );
        $stmt->execute([$table, $constraint]);
        return (int)$stmt->fetchColumn() > 0;
    }
}
