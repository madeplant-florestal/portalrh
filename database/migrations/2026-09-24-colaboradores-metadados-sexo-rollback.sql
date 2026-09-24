-- Rollback: 2026-09-24-colaboradores-metadados-sexo-rollback.sql
-- Reverte database/migrations/2026-09-24-colaboradores-metadados-sexo.sql. Idempotente e portável
-- (MariaDB 11.8 / MySQL 8.4): só remove a coluna se ela existir (INFORMATION_SCHEMA +
-- PREPARE/EXECUTE, mesmo padrão de 2026-09-24-usuarios-gestor-imediato-rollback.sql).
-- ATENÇÃO: descarta o valor de `sexo` de todo o espelho. Só executar com aprovação explícita.

SET @col_existe := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colaboradores_metadados' AND COLUMN_NAME = 'sexo'
);
SET @sql := IF(@col_existe > 0, 'ALTER TABLE colaboradores_metadados DROP COLUMN sexo', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
