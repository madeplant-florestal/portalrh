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
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_recruitment_webhook_settings_scope (scope_key),
                KEY idx_recruitment_webhook_settings_empresa (empresa_id),
                CONSTRAINT fk_recruitment_webhook_settings_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS webhook_events (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
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
