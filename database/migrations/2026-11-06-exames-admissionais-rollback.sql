-- Rollback: 2026-11-06-exames-admissionais.sql
-- Ordem inversa (histórico depende de exames_admissionais via FK).

DROP TABLE IF EXISTS exame_admissional_historico;
DROP TABLE IF EXISTS exames_admissionais;
