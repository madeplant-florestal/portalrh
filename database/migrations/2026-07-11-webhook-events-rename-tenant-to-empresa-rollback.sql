-- Rollback: renomeia webhook_events.empresa_id de volta para tenant_id

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_rollback_webhook_events_tenant_rename $$
CREATE PROCEDURE sp_rollback_webhook_events_tenant_rename()
BEGIN
    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webhook_events' AND COLUMN_NAME = 'empresa_id'
    ) AND NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webhook_events' AND COLUMN_NAME = 'tenant_id'
    ) THEN
        ALTER TABLE webhook_events CHANGE COLUMN empresa_id tenant_id INT NULL;
    END IF;
END $$

CALL sp_rollback_webhook_events_tenant_rename() $$
DROP PROCEDURE IF EXISTS sp_rollback_webhook_events_tenant_rename $$

DELIMITER ;
