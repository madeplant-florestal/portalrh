-- Migration: 2026-11-23-permissoes-aprovacao-lider-e-integracao-agregada.sql  (Etapa 2C.2C)
-- Objetivo: catalogar DUAS permissões novas, SEM conceder a ninguém:
--   * solicitacao_vaga.aprovar_lider            — o Gestor aprovar/reprovar a etapa "líder imediato" de uma Solicitação de Vaga
--                                                 (além da permissão, exige designação formal, setor gerenciado, etapa correta e CSRF).
--   * dashboard_integracao_agregado.visualizar  — o Gestor ver SOMENTE indicadores agregados da Integração dos próprios setores
--                                                 (mínimo de 5 respondentes distintos, sem comentários nem respostas individuais).
-- Nenhuma linha é inserida em usuario_permissoes: ninguém ganha acesso automaticamente. Idempotente (INSERT IGNORE + uk_permissoes_codigo).
-- EXECUÇÃO MANUAL em produção (phpMyAdmin, com o banco u172743873_portalrh selecionado). Depende de 2026-09-15-permissoes-individuais.sql.

INSERT IGNORE INTO permissoes (codigo, modulo, nome, descricao, ordem, ativo) VALUES
  ('solicitacao_vaga.aprovar_lider', 'solicitacao_vaga', 'Aprovar como líder', 'Aprovar ou reprovar a etapa de líder imediato de uma Solicitação de Vaga em que o usuário foi designado aprovador, dentro dos setores que gerencia.', 35, 1),
  ('dashboard_integracao_agregado.visualizar', 'integracao_colaborador', 'Dashboard agregado de Integração', 'Ver indicadores agregados da Integração dos setores que gerencia (mínimo de 5 respondentes, sem comentários nem respostas individuais).', 145, 1);

-- Verificação (somente leitura): as duas linhas devem aparecer, e NENHUM usuário deve ter essas permissões.
SELECT p.codigo, p.ativo, (SELECT COUNT(*) FROM usuario_permissoes up WHERE up.permissao_id = p.id) AS usuarios_com_a_permissao
FROM permissoes p
WHERE p.codigo IN ('solicitacao_vaga.aprovar_lider', 'dashboard_integracao_agregado.visualizar');
