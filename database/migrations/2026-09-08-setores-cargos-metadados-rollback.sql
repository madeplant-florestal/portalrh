-- Rollback: 2026-09-08-setores-cargos-metadados.sql
-- Remove as colunas oficiais + UNIQUE de `setores` e `cargos`. Seguro: colunas puramente
-- aditivas, nenhuma FK nova, `id`/`nome`/`slug`/`ativo`/`empresa_id` e todas as FKs existentes
-- não são tocadas. Descarta os vínculos `codigo_setor`/`codigo_cargo` já sincronizados/adotados —
-- a próxima sincronização os repovoa (re-adotando por nome).

ALTER TABLE setores
  DROP KEY uk_setores_codigo,
  DROP COLUMN sincronizado_em,
  DROP COLUMN origem_metadados,
  DROP COLUMN situacao_metadados,
  DROP COLUMN descricao_oficial,
  DROP COLUMN codigo_setor;

ALTER TABLE cargos
  DROP KEY uk_cargos_codigo,
  DROP COLUMN sincronizado_em,
  DROP COLUMN origem_metadados,
  DROP COLUMN situacao_metadados,
  DROP COLUMN descricao_oficial,
  DROP COLUMN codigo_cargo;
