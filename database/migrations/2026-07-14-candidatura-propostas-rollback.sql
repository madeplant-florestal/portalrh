-- Rollback: 2026-07-14-candidatura-propostas.sql
-- Remove a tabela candidatura_propostas por completo (nenhuma outra tabela depende dela).

DROP TABLE IF EXISTS candidatura_propostas;
