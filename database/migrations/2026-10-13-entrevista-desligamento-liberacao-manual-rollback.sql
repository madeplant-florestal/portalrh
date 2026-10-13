-- Rollback: 2026-10-13-entrevista-desligamento-liberacao-manual.sql
-- ATENÇÃO: o MODIFY COLUMN abaixo falha se existir alguma entrevista com snap_demissao NULL
-- (ou seja, gerada via liberação manual, com o contrato ainda ativo) — resolva/remova essas linhas
-- antes de reverter, ou o rollback será recusado pelo próprio banco (preferível a truncar dado).

ALTER TABLE entrevistas_desligamento MODIFY COLUMN snap_demissao DATE NOT NULL;

DROP TABLE IF EXISTS entrevista_desligamento_liberacoes;
