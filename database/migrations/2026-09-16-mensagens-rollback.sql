-- Rollback: 2026-09-16-mensagens.sql
-- Remove a tabela `mensagens`. Não afeta `permissoes`/`usuario_permissoes` (as 3 permissões
-- novas do módulo ficam órfãs no catálogo, mas não quebram nada — para removê-las também,
-- rode manualmente: DELETE FROM permissoes WHERE modulo = 'mensagens';
-- Nenhuma outra tabela do Portal é afetada.

DROP TABLE IF EXISTS mensagens;
