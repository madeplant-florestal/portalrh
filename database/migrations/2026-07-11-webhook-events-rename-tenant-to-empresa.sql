-- Migration: renomeia webhook_events.tenant_id para empresa_id
--
-- Contexto: coluna puramente informativa (sem FK), usada apenas para saber qual empresa
-- estava associada ao evento no momento do disparo. Com a configuração de webhook agora
-- global (ver 2026-07-11-recruitment-webhooks-global-settings.sql), o termo "tenant" deixa
-- de fazer sentido no domínio — renomeado para manter a nomenclatura consistente com o
-- restante do sistema (`empresa_id`, como em `vagas`, `colaboradores`, etc.). Nenhum dado
-- é alterado ou perdido, apenas o nome da coluna.

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_rename_webhook_events_tenant_to_empresa $$
CREATE PROCEDURE sp_rename_webhook_events_tenant_to_empresa()
BEGIN
    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webhook_events' AND COLUMN_NAME = 'tenant_id'
    ) AND NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webhook_events' AND COLUMN_NAME = 'empresa_id'
    ) THEN
        ALTER TABLE webhook_events CHANGE COLUMN tenant_id empresa_id INT NULL;
    END IF;
END $$

CALL sp_rename_webhook_events_tenant_to_empresa() $$
DROP PROCEDURE IF EXISTS sp_rename_webhook_events_tenant_to_empresa $$

DELIMITER ;
