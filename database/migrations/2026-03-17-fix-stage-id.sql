SET @col_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'candidaturas'
    AND COLUMN_NAME = 'stage_id'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE candidaturas ADD COLUMN stage_id INT NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'candidaturas'
    AND INDEX_NAME = 'idx_candidaturas_stage_id'
);
SET @sql := IF(@idx_exists = 0, 'ALTER TABLE candidaturas ADD INDEX idx_candidaturas_stage_id (stage_id)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND CONSTRAINT_NAME = 'fk_cand_stage'
);
SET @sql := IF(@fk_exists = 0, 'ALTER TABLE candidaturas ADD CONSTRAINT fk_cand_stage FOREIGN KEY (stage_id) REFERENCES pipeline_stages(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
