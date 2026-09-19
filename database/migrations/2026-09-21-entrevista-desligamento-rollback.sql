-- Rollback: 2026-09-21-entrevista-desligamento-rollback.sql
-- ATENÇÃO: DESTRUTIVO — apaga todas as entrevistas de desligamento (links gerados e respostas).
-- Só executar com aprovação explícita. Reverte 2026-09-21-entrevista-desligamento.sql.

DROP TABLE IF EXISTS entrevistas_desligamento_fatores;
DROP TABLE IF EXISTS entrevistas_desligamento;
