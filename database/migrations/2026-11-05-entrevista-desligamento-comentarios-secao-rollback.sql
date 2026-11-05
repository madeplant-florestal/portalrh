-- Rollback: 2026-11-05-entrevista-desligamento-comentarios-secao.sql

ALTER TABLE entrevistas_desligamento
  DROP COLUMN IF EXISTS exp_comentario,
  DROP COLUMN IF EXISTS lid_comentario,
  DROP COLUMN IF EXISTS cul_comentario,
  DROP COLUMN IF EXISTS int_comentario,
  DROP COLUMN IF EXISTS experiencia_geral_comentario;
