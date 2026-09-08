-- Rollback: 2026-09-04-metadados-sync-execucoes-dimensao.sql
-- Remove a coluna `dimensao` e seu índice. Seguro: coluna puramente aditiva, nada depende dela.
-- O backfill 'colaboradores' é descartado junto (a informação era derivável do contexto histórico).

ALTER TABLE metadados_sync_execucoes
  DROP INDEX idx_mse_dimensao,
  DROP COLUMN dimensao;
