-- Rollback: Segredo de assinatura HMAC por configuração de webhook (Fase 2)

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_rollback_recruitment_webhook_secret_schema $$
CREATE PROCEDURE sp_rollback_recruitment_webhook_secret_schema()
BEGIN
    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_webhook_settings' AND COLUMN_NAME = 'webhook_secret_encrypted'
    ) THEN
        ALTER TABLE recruitment_webhook_settings DROP COLUMN webhook_secret_encrypted;
    END IF;
END $$

CALL sp_rollback_recruitment_webhook_secret_schema() $$
DROP PROCEDURE IF EXISTS sp_rollback_recruitment_webhook_secret_schema $$

DELIMITER ;
