-- Rollback controlado da feature Empresa -> Setor
-- Remove foreign key, indice e coluna empresa_id caso existam.
-- Use somente se nao houver dependencias da funcionalidade em producao.

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_rollback_setores_empresa $$
CREATE PROCEDURE sp_rollback_setores_empresa()
BEGIN
    IF EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND CONSTRAINT_NAME = 'fk_setores_empresa'
    ) THEN
        ALTER TABLE setores
            DROP FOREIGN KEY fk_setores_empresa;
    END IF;

    IF EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'setores'
          AND INDEX_NAME = 'idx_setores_empresa'
    ) THEN
        ALTER TABLE setores
            DROP INDEX idx_setores_empresa;
    END IF;

    IF EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'setores'
          AND COLUMN_NAME = 'empresa_id'
    ) THEN
        ALTER TABLE setores
            DROP COLUMN empresa_id;
    END IF;
END $$

CALL sp_rollback_setores_empresa() $$
DROP PROCEDURE IF EXISTS sp_rollback_setores_empresa $$

DELIMITER ;
