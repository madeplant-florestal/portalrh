-- Rollback: Configuração global de webhooks de recrutamento
-- Remove apenas a tabela nova. `recruitment_webhook_settings` (antiga, por empresa)
-- nunca foi tocada por esta migration e permanece intacta.

DROP TABLE IF EXISTS recruitment_webhook_global_settings;
