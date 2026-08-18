-- Rollback: Webhooks de recrutamento v2 (Fase 1)
-- Remove apenas as colunas/índices adicionados por 2026-07-10-recruitment-webhooks-v2.sql,
-- preservando todos os dados legados de pipeline_stages e webhook_events.

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_rollback_recruitment_webhooks_v2_schema $$
CREATE PROCEDURE sp_rollback_recruitment_webhooks_v2_schema()
BEGIN
    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webhook_events' AND COLUMN_NAME = 'next_retry_at'
    ) THEN
        ALTER TABLE webhook_events DROP COLUMN next_retry_at;
    END IF;

    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webhook_events' AND INDEX_NAME = 'idx_webhook_events_movement'
    ) THEN
        ALTER TABLE webhook_events DROP INDEX idx_webhook_events_movement;
    END IF;

    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webhook_events' AND COLUMN_NAME = 'movement_id'
    ) THEN
        ALTER TABLE webhook_events DROP COLUMN movement_id;
    END IF;

    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webhook_events' AND INDEX_NAME = 'uk_webhook_events_event_id'
    ) THEN
        ALTER TABLE webhook_events DROP INDEX uk_webhook_events_event_id;
    END IF;

    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webhook_events' AND COLUMN_NAME = 'event_id'
    ) THEN
        ALTER TABLE webhook_events DROP COLUMN event_id;
    END IF;

    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pipeline_stages' AND INDEX_NAME = 'uk_pipeline_stages_slug'
    ) THEN
        ALTER TABLE pipeline_stages DROP INDEX uk_pipeline_stages_slug;
    END IF;

    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pipeline_stages' AND COLUMN_NAME = 'slug'
    ) THEN
        ALTER TABLE pipeline_stages DROP COLUMN slug;
    END IF;
END $$

CALL sp_rollback_recruitment_webhooks_v2_schema() $$
DROP PROCEDURE IF EXISTS sp_rollback_recruitment_webhooks_v2_schema $$

DELIMITER ;
