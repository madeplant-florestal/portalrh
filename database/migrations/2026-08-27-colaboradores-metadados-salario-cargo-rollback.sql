-- Rollback: reverte database/migrations/2026-08-27-colaboradores-metadados-salario-cargo.sql
-- "DROP COLUMN IF EXISTS" falha com erro de sintaxe no MySQL 8.4.3 deste ambiente (testado
-- empiricamente) — por isso ALTER TABLE puro, sem IF EXISTS.

ALTER TABLE colaboradores_metadados
  DROP COLUMN data_inicio_cargo,
  DROP COLUMN salario_atual;
