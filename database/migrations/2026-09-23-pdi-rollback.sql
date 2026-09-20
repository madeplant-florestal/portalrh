-- Rollback: 2026-09-23-pdi-rollback.sql
-- ATENÇÃO: DESTRUTIVO — apaga todos os PDIs, ações, competências, acompanhamentos e a trilha de auditoria.
-- Só executar com aprovação explícita. Reverte 2026-09-23-pdi.sql (ordem inversa das dependências).

DROP TABLE IF EXISTS pdi_eventos;
DROP TABLE IF EXISTS pdi_acompanhamentos;
DROP TABLE IF EXISTS pdi_acoes;
DROP TABLE IF EXISTS pdi_competencias;
DROP TABLE IF EXISTS pdis;
