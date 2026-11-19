-- Rollback: 2026-11-23-permissoes-aprovacao-lider-e-integracao-agregada.sql
-- Remove as duas permissões do catálogo e as concessões feitas a usuários DEPOIS da migration (se alguma).
-- ATENÇÃO: quem tinha essas permissões perde o acesso correspondente (Gestor deixa de aprovar como líder / ver o dashboard agregado).
-- Antes de executar, conferir o impacto (somente leitura):
--   SELECT u.id, u.role, p.codigo FROM usuario_permissoes up JOIN permissoes p ON p.id = up.permissao_id JOIN usuarios u ON u.id = up.usuario_id
--   WHERE p.codigo IN ('solicitacao_vaga.aprovar_lider', 'dashboard_integracao_agregado.visualizar');

DELETE up FROM usuario_permissoes up
JOIN permissoes p ON p.id = up.permissao_id
WHERE p.codigo IN ('solicitacao_vaga.aprovar_lider', 'dashboard_integracao_agregado.visualizar');

DELETE FROM permissoes WHERE codigo IN ('solicitacao_vaga.aprovar_lider', 'dashboard_integracao_agregado.visualizar');
