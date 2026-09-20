-- Rollback: 2026-09-24-usuarios-gestor-imediato-rollback.sql
-- ATENÇÃO: DESTRUTIVO — descarta a hierarquia de Gestor Imediato cadastrada (a coluna é removida). Só executar com aprovação
-- explícita. Não afeta `aprovador_usuario_id`. Idempotente (cada passo só roda se o objeto existir).

SET @fk_existe := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME = 'fk_usuarios_gestor'
);
SET @sql := IF(@fk_existe > 0, 'ALTER TABLE usuarios DROP FOREIGN KEY fk_usuarios_gestor', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_existe := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND INDEX_NAME = 'idx_usuarios_gestor'
);
SET @sql := IF(@idx_existe > 0, 'ALTER TABLE usuarios DROP INDEX idx_usuarios_gestor', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_existe := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'gestor_usuario_id'
);
SET @sql := IF(@col_existe > 0, 'ALTER TABLE usuarios DROP COLUMN gestor_usuario_id', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
