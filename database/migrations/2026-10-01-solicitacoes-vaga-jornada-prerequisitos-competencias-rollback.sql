-- Rollback: 2026-10-01-solicitacoes-vaga-jornada-prerequisitos-competencias.sql
-- Reverte a FK e as colunas novas de solicitacoes_vaga e remove jornadas_trabalho.
-- NÃO restaura texto livre de jornada a partir do snapshot (a coluna `jornada_trabalho` já
-- preserva o snapshot e não é alterada por esta migration nem pelo rollback).

ALTER TABLE solicitacoes_vaga DROP FOREIGN KEY fk_solicitacoes_jornada_trabalho;

ALTER TABLE solicitacoes_vaga DROP COLUMN IF EXISTS competencias_comportamentais_encrypted;
ALTER TABLE solicitacoes_vaga DROP COLUMN IF EXISTS competencias_tecnicas_encrypted;
ALTER TABLE solicitacoes_vaga DROP COLUMN IF EXISTS pre_requisitos_encrypted;
ALTER TABLE solicitacoes_vaga DROP COLUMN IF EXISTS jornada_trabalho_id;

DROP TABLE IF EXISTS jornadas_trabalho;
