-- Rollback: 2026-09-16-comunicacoes-candidato.sql
-- Remove a tabela `comunicacoes`. Não afeta `candidaturas`, `mensagens` nem `usuarios`.

DROP TABLE IF EXISTS comunicacoes;
