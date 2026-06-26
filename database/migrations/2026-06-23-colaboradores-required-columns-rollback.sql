-- Rollback: 2026-06-23-colaboradores-required-columns-rollback.sql
-- Remove apenas as colunas e índices introduzidos por esta migration.
-- O campo id permanece como chave primária da tabela colaboradores.

ALTER TABLE colaboradores DROP INDEX IF EXISTS idx_colaboradores_data_demissao;
ALTER TABLE colaboradores DROP INDEX IF EXISTS idx_colaboradores_data_admissao;
ALTER TABLE colaboradores DROP INDEX IF EXISTS idx_colaboradores_cpf;
ALTER TABLE colaboradores DROP INDEX IF EXISTS idx_colaboradores_codigo;

ALTER TABLE colaboradores DROP COLUMN IF EXISTS motivo_rescisao;
ALTER TABLE colaboradores DROP COLUMN IF EXISTS data_demissao;
ALTER TABLE colaboradores DROP COLUMN IF EXISTS data_nascimento;
ALTER TABLE colaboradores DROP COLUMN IF EXISTS cpf;
ALTER TABLE colaboradores DROP COLUMN IF EXISTS codigo;
