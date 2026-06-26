-- Migration: 2026-06-25-colaboradores-rehire-import-indexes.sql
-- Objetivo:
--   - Adequar a tabela colaboradores ao cenario de recontratacao.
--   - Permitir repeticao de CPF em historicos distintos de admissao.
--   - Permitir repeticao de COD entre empresas diferentes.
--   - Otimizar as consultas do importador e das validacoes de RH.

ALTER TABLE colaboradores
    ADD INDEX IF NOT EXISTS idx_colaboradores_empresa_codigo (empresa_id, codigo),
    ADD INDEX IF NOT EXISTS idx_colaboradores_empresa_matricula (empresa_id, matricula),
    ADD INDEX IF NOT EXISTS idx_colaboradores_cpf_admissao (cpf, data_admissao),
    ADD INDEX IF NOT EXISTS idx_colaboradores_empresa_cpf_ativo (empresa_id, cpf, ativo, data_demissao);

-- Consultas de conferencia:
-- SHOW INDEX FROM colaboradores WHERE Key_name IN (
--   'idx_colaboradores_empresa_codigo',
--   'idx_colaboradores_empresa_matricula',
--   'idx_colaboradores_cpf_admissao',
--   'idx_colaboradores_empresa_cpf_ativo'
-- );
