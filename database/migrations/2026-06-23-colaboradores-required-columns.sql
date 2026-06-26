-- Migration: 2026-06-23-colaboradores-required-columns.sql
-- Objetivo:
--   - Garantir que a tabela colaboradores tenha os atributos obrigatórios
--     para COD, COLABORADOR, EMPRESA, CPF, ADMISSÃO, NASC., CARGO,
--     DEMISSÃO e MOTIVO RESCISÃO.
--   - Preservar o campo id como chave primária e índice principal.
--   - Manter compatibilidade com bases já importadas do sistema legado.
--
-- Mapeamento lógico adotado:
--   - COD              -> codigo
--   - COLABORADOR      -> nome
--   - EMPRESA          -> empresa_id (relacional) / empresas.nome via JOIN
--   - CPF              -> cpf
--   - ADMISSÃO         -> data_admissao
--   - NASC.            -> data_nascimento
--   - CARGO            -> cargo_id (relacional) / cargos.nome via JOIN
--   - DEMISSÃO         -> data_demissao
--   - MOTIVO RESCISÃO  -> motivo_rescisao

ALTER TABLE colaboradores ADD COLUMN IF NOT EXISTS matricula VARCHAR(30) NULL AFTER nome;
ALTER TABLE colaboradores ADD COLUMN IF NOT EXISTS codigo VARCHAR(30) NULL AFTER matricula;
ALTER TABLE colaboradores ADD COLUMN IF NOT EXISTS cpf VARCHAR(11) NULL AFTER codigo;
ALTER TABLE colaboradores ADD COLUMN IF NOT EXISTS salario_atual DECIMAL(12,2) NULL AFTER setor_id;
ALTER TABLE colaboradores ADD COLUMN IF NOT EXISTS data_admissao DATE NULL AFTER salario_atual;
ALTER TABLE colaboradores ADD COLUMN IF NOT EXISTS data_inicio_cargo DATE NULL AFTER data_admissao;
ALTER TABLE colaboradores ADD COLUMN IF NOT EXISTS data_nascimento DATE NULL AFTER data_inicio_cargo;
ALTER TABLE colaboradores ADD COLUMN IF NOT EXISTS data_demissao DATE NULL AFTER data_nascimento;
ALTER TABLE colaboradores ADD COLUMN IF NOT EXISTS motivo_rescisao VARCHAR(255) NULL AFTER data_demissao;

ALTER TABLE colaboradores ADD INDEX IF NOT EXISTS idx_colaboradores_codigo (codigo);
ALTER TABLE colaboradores ADD INDEX IF NOT EXISTS idx_colaboradores_cpf (cpf);
ALTER TABLE colaboradores ADD INDEX IF NOT EXISTS idx_colaboradores_data_admissao (data_admissao);
ALTER TABLE colaboradores ADD INDEX IF NOT EXISTS idx_colaboradores_data_demissao (data_demissao);

-- Preenche COD com a matrícula existente quando disponível.
UPDATE colaboradores
SET codigo = NULLIF(TRIM(matricula), '')
WHERE (codigo IS NULL OR codigo = '')
  AND matricula IS NOT NULL
  AND TRIM(matricula) <> '';

-- Para registros sem matrícula histórica, gera um código técnico estável.
UPDATE colaboradores
SET codigo = CONCAT('COL', LPAD(id, 6, '0'))
WHERE codigo IS NULL OR codigo = '';

-- Normaliza CPF já existente, removendo caracteres não numéricos.
UPDATE colaboradores
SET cpf = REGEXP_REPLACE(cpf, '[^0-9]', '')
WHERE cpf IS NOT NULL AND cpf <> '';

-- Invalida CPFs fora do padrão de 11 dígitos, preservando integridade sem bloquear a migration.
UPDATE colaboradores
SET cpf = NULL
WHERE cpf IS NOT NULL AND cpf <> '' AND CHAR_LENGTH(cpf) <> 11;

-- Consultas de conferência pós-migração:
-- 1. Estrutura obrigatória:
--    SELECT COLUMN_NAME
--    FROM INFORMATION_SCHEMA.COLUMNS
--    WHERE TABLE_SCHEMA = DATABASE()
--      AND TABLE_NAME = 'colaboradores'
--      AND COLUMN_NAME IN ('id','nome','empresa_id','cargo_id','codigo','cpf','data_admissao','data_nascimento','data_demissao','motivo_rescisao');
--
-- 2. PK preservada em id:
--    SHOW KEYS FROM colaboradores WHERE Key_name = 'PRIMARY';
--
-- 3. Registros sem COD após backfill:
--    SELECT COUNT(*) FROM colaboradores WHERE codigo IS NULL OR codigo = '';
