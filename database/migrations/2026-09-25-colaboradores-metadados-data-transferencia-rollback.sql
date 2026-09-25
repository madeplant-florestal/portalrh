-- Rollback: 2026-09-25-colaboradores-metadados-data-transferencia-rollback.sql
-- Reverte database/migrations/2026-09-25-colaboradores-metadados-data-transferencia.sql.
-- Idempotente e portável (MariaDB 11.8 / MySQL 8.4): só remove a coluna se ela existir
-- (INFORMATION_SCHEMA + PREPARE/EXECUTE, mesmo padrão da migration irmã).
-- ATENÇÃO: descarta a data de transferência persistida (nunca afeta `admissao`, `demissao`,
-- `ausente_na_origem`/`ausente_desde` nem qualquer outro dado histórico). A classificação de
-- transferência contínua volta a operar sem vigência analítica de precisão de dia (mesmo
-- comportamento anterior a esta migration). Só executar com aprovação explícita.

SET @col_existe := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colaboradores_metadados' AND COLUMN_NAME = 'data_ultima_transferencia'
);
SET @sql := IF(@col_existe > 0, 'ALTER TABLE colaboradores_metadados DROP COLUMN data_ultima_transferencia', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
