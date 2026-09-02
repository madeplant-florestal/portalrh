-- Rollback: 2026-09-01-metadados-sync-execucoes.sql
-- Remove o histórico operacional das sincronizações do METADADOS.
-- Seguro: tabela puramente aditiva, nenhuma outra estrutura depende dela.
-- ATENÇÃO: descarta todo o histórico de sincronizações registrado até aqui.

DROP TABLE IF EXISTS metadados_sync_execucoes;
