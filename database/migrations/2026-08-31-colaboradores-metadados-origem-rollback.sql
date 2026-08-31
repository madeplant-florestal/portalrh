-- Rollback: 2026-08-31-colaboradores-metadados-origem.sql
-- Remove a coluna de origem e seu índice. Não restaura os 6 registros de RHTESTE removidos
-- separadamente (ver snapshot em storage/reconciliation/saneamento-metadados-rhteste-*.json) —
-- este rollback desfaz só a proteção de origem, não o saneamento de dados.

ALTER TABLE colaboradores_metadados
  DROP INDEX idx_colaboradores_metadados_origem,
  DROP COLUMN origem_metadados;
