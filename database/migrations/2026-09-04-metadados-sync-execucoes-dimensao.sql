-- Migration: 2026-09-04-metadados-sync-execucoes-dimensao.sql
-- Objetivo:
--   Fase 5.1A — o histórico operacional `metadados_sync_execucoes` passa a registrar QUAL dimensão
--   cada execução sincronizou (empresas / unidades / colaboradores), já que a partir desta fase há
--   três dimensões sendo sincronizadas pelo mesmo pipeline (sender -> receiver interno).
--
--   `dimensao` é NULLABLE. Backfill das linhas já existentes como 'colaboradores': provado — até
--   esta migration a única sincronização registrada era a de colaboradores
--   (POST /internal/metadados/colaboradores/sync, ver primeira carga real 01/09/2026).
--
--   Um mesmo `correlacao_id` poderá futuramente identificar uma execução completa contendo várias
--   dimensões; nesta fase cada dimensão gera sua própria linha.
--
--   Puramente aditiva. `ALTER TABLE` puro (coluna nova, MySQL 8.4.3 não aceita IF NOT EXISTS).
--   NÃO aplicar em produção nesta execução.

ALTER TABLE metadados_sync_execucoes
  ADD COLUMN dimensao VARCHAR(20) NULL AFTER gatilho,
  ADD INDEX idx_mse_dimensao (dimensao);

UPDATE metadados_sync_execucoes SET dimensao = 'colaboradores' WHERE dimensao IS NULL;
