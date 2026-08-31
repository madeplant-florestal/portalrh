-- Rollback: 2026-08-31-colaboradores-metadados-indices-analitico.sql
-- Remove os índices adicionados para a camada analítica de RH (Fase 4). Não afeta dados.

ALTER TABLE colaboradores_metadados
  DROP INDEX idx_colaboradores_metadados_admissao,
  DROP INDEX idx_colaboradores_metadados_demissao;
