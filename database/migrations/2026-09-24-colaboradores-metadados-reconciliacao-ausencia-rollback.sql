-- Rollback: 2026-09-24-colaboradores-metadados-reconciliacao-ausencia-rollback.sql
-- Reverte database/migrations/2026-09-24-colaboradores-metadados-reconciliacao-ausencia.sql.
-- Idempotente e portável (MariaDB 11.8 / MySQL 8.4): só remove cada coluna se ela existir
-- (INFORMATION_SCHEMA + PREPARE/EXECUTE, mesmo padrão das migrations irmãs desta rodada).
-- ATENÇÃO: descarta a sinalização de ausência de todo o espelho (não afeta `ativo`, `demissao`
-- nem qualquer outro dado histórico). Só executar com aprovação explícita.

SET @col_existe := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colaboradores_metadados' AND COLUMN_NAME = 'ausente_desde'
);
SET @sql := IF(@col_existe > 0, 'ALTER TABLE colaboradores_metadados DROP COLUMN ausente_desde', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_existe := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colaboradores_metadados' AND COLUMN_NAME = 'ausente_na_origem'
);
SET @sql := IF(@col_existe > 0, 'ALTER TABLE colaboradores_metadados DROP COLUMN ausente_na_origem', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
