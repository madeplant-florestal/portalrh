-- Migration: Webhooks de recrutamento v2 (Fase 1) — slug estável de etapa e identificador
-- estável de evento para o payload padronizado do dispatcher de recrutamento.
--
-- Contexto: o payload atual (`candidate_stage_changed`) usa o nome textual da etapa como
-- identificador lógico, o que quebra se alguém renomear a etapa no catálogo. Esta migration
-- adiciona `pipeline_stages.slug` (identificador estável) e prepara `webhook_events` para
-- idempotência (`event_id` exposto ao consumidor externo, estável em reenvios) e para
-- vincular o evento à movimentação de pipeline que o originou (`movement_id`), além do
-- campo de agendamento de retentativa progressiva (`next_retry_at`, usado nas fases
-- seguintes). Todas as mudanças são aditivas (ADD COLUMN), sem perda de dado.
--
-- O preenchimento dos slugs das etapas já existentes é feito em runtime por
-- PipelineStage::ensureRecruitmentLifecycle() (mesmo mecanismo que já reconcilia
-- nome/ordem/cor por alias), não nesta migration — evita duplicar a lógica de
-- correspondência por alias em SQL.

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_ensure_recruitment_webhooks_v2_schema $$
CREATE PROCEDURE sp_ensure_recruitment_webhooks_v2_schema()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pipeline_stages' AND COLUMN_NAME = 'slug'
    ) THEN
        ALTER TABLE pipeline_stages ADD COLUMN slug VARCHAR(50) NULL AFTER nome;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pipeline_stages' AND INDEX_NAME = 'uk_pipeline_stages_slug'
    ) THEN
        ALTER TABLE pipeline_stages ADD UNIQUE KEY uk_pipeline_stages_slug (slug);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webhook_events' AND COLUMN_NAME = 'event_id'
    ) THEN
        ALTER TABLE webhook_events ADD COLUMN event_id VARCHAR(40) NULL AFTER id;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webhook_events' AND INDEX_NAME = 'uk_webhook_events_event_id'
    ) THEN
        ALTER TABLE webhook_events ADD UNIQUE KEY uk_webhook_events_event_id (event_id);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webhook_events' AND COLUMN_NAME = 'movement_id'
    ) THEN
        ALTER TABLE webhook_events ADD COLUMN movement_id INT NULL AFTER tenant_id;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webhook_events' AND INDEX_NAME = 'idx_webhook_events_movement'
    ) THEN
        ALTER TABLE webhook_events ADD INDEX idx_webhook_events_movement (movement_id);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webhook_events' AND COLUMN_NAME = 'next_retry_at'
    ) THEN
        ALTER TABLE webhook_events ADD COLUMN next_retry_at DATETIME NULL AFTER processed_at;
    END IF;
END $$

CALL sp_ensure_recruitment_webhooks_v2_schema() $$
DROP PROCEDURE IF EXISTS sp_ensure_recruitment_webhooks_v2_schema $$

DELIMITER ;
