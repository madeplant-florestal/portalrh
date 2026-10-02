-- Rollback: 2026-10-02-usuario-setores-gerenciados.sql
-- Remove a tabela "Setores gerenciados" por completo. Nenhuma outra tabela é afetada (ela nunca
-- escreveu em usuario_setores, usuarios.gestor_usuario_id nem usuario_colaboradores).

DROP TABLE IF EXISTS usuario_setores_gerenciados;
