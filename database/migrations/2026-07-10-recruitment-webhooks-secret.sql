-- Migration: Segredo de assinatura HMAC por configuração de webhook (Fase 2)
--
-- Contexto: cada configuração de webhook (por tenant ou o escopo `default`) passa a
-- possuir um segredo exclusivo, usado para assinar o payload com HMAC-SHA256 antes do
-- envio. O segredo é armazenado cifrado (AES-256-CBC via `Cipher`, mesmo padrão já usado
-- em `solicitacoes_vaga`/`movimentacoes_pessoal`) — nunca em texto plano no banco, nunca
-- logado, e só exibido ao administrador uma única vez, no momento da geração/regeneração.

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_ensure_recruitment_webhook_secret_schema $$
CREATE PROCEDURE sp_ensure_recruitment_webhook_secret_schema()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recruitment_webhook_settings' AND COLUMN_NAME = 'webhook_secret_encrypted'
    ) THEN
        ALTER TABLE recruitment_webhook_settings ADD COLUMN webhook_secret_encrypted VARCHAR(255) NULL AFTER webhook_url;
    END IF;
END $$

CALL sp_ensure_recruitment_webhook_secret_schema() $$
DROP PROCEDURE IF EXISTS sp_ensure_recruitment_webhook_secret_schema $$

DELIMITER ;
