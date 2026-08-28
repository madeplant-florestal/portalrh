-- Rollback: reverte database/migrations/2026-08-28-colaboradores-metadados-id.sql

ALTER TABLE colaboradores
  DROP FOREIGN KEY fk_colaboradores_metadados,
  DROP KEY uk_colaboradores_metadados_id,
  DROP COLUMN metadados_id;
