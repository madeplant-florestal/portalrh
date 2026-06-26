-- Migration: Event Dispatcher e fila de webhooks do Kanban de recrutamento
-- Objetivos:
-- 1. Vincular vagas a empresas/tenants sem quebrar legados
-- 2. Persistir dados adicionais de etapa por candidatura
-- 3. Criar configuracao de webhooks por tenant com escopo padrao
-- 4. Criar fila de eventos HTTP preparada para reenvio e crescimento futuro

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_ensure_recruitment_webhooks_schema $$
CREATE PROCEDURE sp_ensure_recruitment_webhooks_schema()
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'vagas'
          AND COLUMN_NAME = 'empresa_id'
    ) THEN
        ALTER TABLE vagas
            ADD COLUMN empresa_id INT NULL AFTER local;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'vagas'
          AND INDEX_NAME = 'idx_vagas_empresa_id'
    ) THEN
        ALTER TABLE vagas
            ADD INDEX idx_vagas_empresa_id (empresa_id);
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND CONSTRAINT_NAME = 'fk_vagas_empresa'
    ) THEN
        ALTER TABLE vagas
            ADD CONSTRAINT fk_vagas_empresa
            FOREIGN KEY (empresa_id) REFERENCES empresas(id)
            ON DELETE SET NULL
            ON UPDATE CASCADE;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'candidatura_stage_metadata'
    ) THEN
        CREATE TABLE candidatura_stage_metadata (
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
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'recruitment_webhook_settings'
    ) THEN
        CREATE TABLE recruitment_webhook_settings (
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
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'webhook_events'
    ) THEN
        CREATE TABLE webhook_events (
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
    END IF;
END $$

CALL sp_ensure_recruitment_webhooks_schema() $$
DROP PROCEDURE IF EXISTS sp_ensure_recruitment_webhooks_schema $$

DELIMITER ;
