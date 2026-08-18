-- Migration: Configuração global de webhooks de recrutamento
--
-- Contexto: o sistema RH Madeplant é usado internamente por um único grupo empresarial
-- (várias empresas na mesma base), não é um SaaS multi-tenant. A modelagem anterior
-- (uma configuração de webhook por empresa, tabela `recruitment_webhook_settings` com
-- `scope_key`/`empresa_id`) foi substituída por uma única configuração global, válida
-- para todo o recrutamento. A empresa da vaga continua sendo enviada no payload, mas
-- apenas como contexto informativo — nunca mais decide URL ou segredo.
--
-- `recruitment_webhook_settings` (tabela antiga) NÃO é removida nesta migration — fica
-- em desuso, preservada para auditoria/rollback. A remoção definitiva é uma mudança
-- destrutiva separada, a ser aprovada isoladamente no futuro (ver CLAUDE.md §13).

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_ensure_recruitment_webhook_global_settings $$
CREATE PROCEDURE sp_ensure_recruitment_webhook_global_settings()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_webhook_global_settings'
    ) THEN
        CREATE TABLE recruitment_webhook_global_settings (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            webhook_url VARCHAR(500) NULL,
            webhook_secret_encrypted VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    INSERT IGNORE INTO recruitment_webhook_global_settings (id, enabled, webhook_url, webhook_secret_encrypted)
    VALUES (1, 0, NULL, NULL);
END $$

CALL sp_ensure_recruitment_webhook_global_settings() $$
DROP PROCEDURE IF EXISTS sp_ensure_recruitment_webhook_global_settings $$

DELIMITER ;
