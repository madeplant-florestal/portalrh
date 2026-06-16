-- Migration: relacionamento Empresa (1) -> (N) Setores
-- Observacao importante:
-- Ha um conflito entre:
-- 1. exigir empresa_id NOT NULL
-- 2. manter compatibilidade com setores legados sem empresa e saneamento posterior
--
-- Para nao quebrar ambientes existentes, esta migration cria a coluna de forma
-- retrocompativel, com enforce funcional no backend/admin. Apos saneamento dos
-- legados, a coluna pode ser endurecida para NOT NULL em janela controlada.

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_ensure_setores_empresa $$
CREATE PROCEDURE sp_ensure_setores_empresa()
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'setores'
          AND COLUMN_NAME = 'empresa_id'
    ) THEN
        ALTER TABLE setores
            ADD COLUMN empresa_id INT NULL AFTER slug;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'setores'
          AND INDEX_NAME = 'idx_setores_empresa'
    ) THEN
        ALTER TABLE setores
            ADD INDEX idx_setores_empresa (empresa_id);
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND CONSTRAINT_NAME = 'fk_setores_empresa'
    ) THEN
        ALTER TABLE setores
            ADD CONSTRAINT fk_setores_empresa
            FOREIGN KEY (empresa_id) REFERENCES empresas(id)
            ON DELETE RESTRICT
            ON UPDATE CASCADE;
    END IF;
END $$

CALL sp_ensure_setores_empresa() $$
DROP PROCEDURE IF EXISTS sp_ensure_setores_empresa $$

DELIMITER ;
