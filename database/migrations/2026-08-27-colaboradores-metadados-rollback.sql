-- Rollback: reverte database/migrations/2026-08-27-colaboradores-metadados.sql
-- Seguro: nenhuma FK de outra tabela referencia colaboradores_metadados nesta fase.

DROP TABLE IF EXISTS colaboradores_metadados;
