-- Migration: governanca de relacionamentos entre cargos e setores
-- Objetivo:
-- 1. Garantir a existencia da tabela cargo_setores
-- 2. Garantir colunas obrigatorias
-- 3. Garantir chave primaria composta, indices e foreign keys
-- 4. Manter compatibilidade com MariaDB e execucao idempotente

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_ensure_cargo_setores_schema $$
CREATE PROCEDURE sp_ensure_cargo_setores_schema()
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'cargo_setores'
    ) THEN
        CREATE TABLE cargo_setores (
            cargo_id INT NOT NULL,
            setor_id INT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (cargo_id, setor_id),
            KEY idx_cargo_setores_cargo (cargo_id),
            KEY idx_cargo_setores_setor (setor_id),
            CONSTRAINT fk_cargo_setores_cargo FOREIGN KEY (cargo_id) REFERENCES cargos(id) ON DELETE CASCADE,
            CONSTRAINT fk_cargo_setores_setor FOREIGN KEY (setor_id) REFERENCES setores(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'cargo_setores'
          AND COLUMN_NAME = 'cargo_id'
    ) THEN
        ALTER TABLE cargo_setores ADD COLUMN cargo_id INT NOT NULL FIRST;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'cargo_setores'
          AND COLUMN_NAME = 'setor_id'
    ) THEN
        ALTER TABLE cargo_setores ADD COLUMN setor_id INT NOT NULL AFTER cargo_id;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'cargo_setores'
          AND COLUMN_NAME = 'created_at'
    ) THEN
        ALTER TABLE cargo_setores
            ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER setor_id;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'cargo_setores'
          AND CONSTRAINT_NAME = 'PRIMARY'
          AND CONSTRAINT_TYPE = 'PRIMARY KEY'
    ) THEN
        ALTER TABLE cargo_setores
            ADD PRIMARY KEY (cargo_id, setor_id);
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'cargo_setores'
          AND INDEX_NAME = 'idx_cargo_setores_cargo'
    ) THEN
        ALTER TABLE cargo_setores
            ADD INDEX idx_cargo_setores_cargo (cargo_id);
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'cargo_setores'
          AND INDEX_NAME = 'idx_cargo_setores_setor'
    ) THEN
        ALTER TABLE cargo_setores
            ADD INDEX idx_cargo_setores_setor (setor_id);
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND CONSTRAINT_NAME = 'fk_cargo_setores_cargo'
    ) THEN
        ALTER TABLE cargo_setores
            ADD CONSTRAINT fk_cargo_setores_cargo
            FOREIGN KEY (cargo_id) REFERENCES cargos(id) ON DELETE CASCADE;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND CONSTRAINT_NAME = 'fk_cargo_setores_setor'
    ) THEN
        ALTER TABLE cargo_setores
            ADD CONSTRAINT fk_cargo_setores_setor
            FOREIGN KEY (setor_id) REFERENCES setores(id) ON DELETE CASCADE;
    END IF;
END $$

CALL sp_ensure_cargo_setores_schema() $$
DROP PROCEDURE IF EXISTS sp_ensure_cargo_setores_schema $$

DELIMITER ;
