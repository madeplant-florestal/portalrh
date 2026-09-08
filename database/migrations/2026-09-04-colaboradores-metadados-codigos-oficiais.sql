-- Migration: 2026-09-04-colaboradores-metadados-codigos-oficiais.sql
-- Objetivo:
--   Fase 5.1A — enriquece o espelho `colaboradores_metadados` com os CÓDIGOS oficiais de setor,
--   cargo e centro de custo que já vêm direto de RHCONTRATOS (colunas SETOR, CARGO, CENTROCUSTO1).
--   Não precisamos ter terminado a investigação das tabelas de catálogo RHSETORES/RHCARGOS/
--   RHCENTROSCUSTO1 para começar a GUARDAR esses códigos — eles são a ponte para a reconstrução
--   definitiva dessas dimensões na próxima fase.
--
--   As colunas TEXTUAIS existentes (`setor`, `cargo`, `centro_custo`, vindas de DESCRICAO40) são
--   MANTIDAS — nada é removido. Elas só serão depreciadas depois que as dimensões oficiais
--   estiverem completamente implementadas e as telas migradas.
--
--   Puramente aditiva. Compatibilidade: MySQL 8.4.3 não aceita `ADD COLUMN IF NOT EXISTS`
--   (erro 1064) — colunas novas usam `ALTER TABLE` puro.
--   NÃO aplicar em produção nesta execução.

ALTER TABLE colaboradores_metadados
  ADD COLUMN codigo_setor        VARCHAR(20) NULL AFTER setor,
  ADD COLUMN codigo_cargo        VARCHAR(20) NULL AFTER cargo,
  ADD COLUMN codigo_centro_custo VARCHAR(20) NULL AFTER centro_custo,
  ADD INDEX idx_colaboradores_metadados_codigo_setor (codigo_setor),
  ADD INDEX idx_colaboradores_metadados_codigo_cargo (codigo_cargo),
  ADD INDEX idx_colaboradores_metadados_codigo_cc (codigo_centro_custo);
