-- Rollback: 2026-09-04-colaboradores-metadados-codigos-oficiais.sql
-- Remove os códigos oficiais de setor/cargo/centro de custo e seus índices do espelho.
-- Seguro: colunas puramente aditivas, as colunas textuais (setor/cargo/centro_custo) não são
-- tocadas. Descarta os códigos já sincronizados — a próxima sincronização os repovoa.

ALTER TABLE colaboradores_metadados
  DROP INDEX idx_colaboradores_metadados_codigo_cc,
  DROP INDEX idx_colaboradores_metadados_codigo_cargo,
  DROP INDEX idx_colaboradores_metadados_codigo_setor,
  DROP COLUMN codigo_centro_custo,
  DROP COLUMN codigo_cargo,
  DROP COLUMN codigo_setor;
