-- Rollback: Event Dispatcher e fila de webhooks do Kanban de recrutamento
-- Remove apenas as estruturas novas, preservando dados legados do fluxo principal.

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_rollback_recruitment_webhooks_schema $$
CREATE PROCEDURE sp_rollback_recruitment_webhooks_schema()
BEGIN
    IF EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND CONSTRAINT_NAME = 'fk_recruitment_webhook_settings_empresa'
    ) THEN
        ALTER TABLE recruitment_webhook_settings
            DROP FOREIGN KEY fk_recruitment_webhook_settings_empresa;
    END IF;

    IF EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND CONSTRAINT_NAME = 'fk_candidatura_stage_metadata_candidatura'
    ) THEN
        ALTER TABLE candidatura_stage_metadata
            DROP FOREIGN KEY fk_candidatura_stage_metadata_candidatura;
    END IF;

    IF EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND CONSTRAINT_NAME = 'fk_vagas_empresa'
    ) THEN
        ALTER TABLE vagas
            DROP FOREIGN KEY fk_vagas_empresa;
    END IF;

    IF EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'vagas'
          AND INDEX_NAME = 'idx_vagas_empresa_id'
    ) THEN
        ALTER TABLE vagas
            DROP INDEX idx_vagas_empresa_id;
    END IF;

    IF EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'vagas'
          AND COLUMN_NAME = 'empresa_id'
    ) THEN
        ALTER TABLE vagas
            DROP COLUMN empresa_id;
    END IF;

    IF EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'webhook_events'
    ) THEN
        DROP TABLE webhook_events;
    END IF;

    IF EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'recruitment_webhook_settings'
    ) THEN
        DROP TABLE recruitment_webhook_settings;
    END IF;

    IF EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'candidatura_stage_metadata'
    ) THEN
        DROP TABLE candidatura_stage_metadata;
    END IF;
END $$

CALL sp_rollback_recruitment_webhooks_schema() $$
DROP PROCEDURE IF EXISTS sp_rollback_recruitment_webhooks_schema $$

DELIMITER ;
