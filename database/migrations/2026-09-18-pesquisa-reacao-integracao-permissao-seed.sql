-- Seed: 2026-09-18-pesquisa-reacao-integracao-permissao-seed.sql
-- Objetivo:
--   Cadastra no catálogo já existente `permissoes` (migration 2026-09-15-permissoes-individuais.sql)
--   as capacidades do novo módulo administrativo "Pesquisa de Reação — Treinamento de Integração".
--
--   NENHUMA tabela nova, NENHUMA alteração de schema — só duas linhas novas em `permissoes`
--   (`INSERT IGNORE`, idempotente, respeita `uk_permissoes_codigo`). Este seed apenas CADASTRA as
--   permissões no catálogo — não concede nenhuma delas a nenhum usuário automaticamente.
--
--   Backend protegido de forma RÍGIDA desde o início (mesmo padrão de
--   `dashboard_recrutamento.visualizar`, migration 2026-09-17-dashboard-recrutamento-permissao-seed.sql):
--   `Authorization::requirePermissao('pesquisa_reacao_integracao.visualizar')` no index/resultados
--   de `AdminPesquisaReacaoIntegracaoController`, e
--   `Authorization::requirePermissao('pesquisa_reacao_integracao.gerenciar')` em gerar
--   link/desativar. Admin mantém o bypass central já existente
--   (`Authorization::usuarioEhAdmin()`); RH/Supervisor NÃO recebem acesso automático pela role —
--   só quem tiver a permissão individual concedida na Tela de Usuários.
--
--   Depende de `permissoes` (2026-09-15-permissoes-individuais.sql) já aplicada.

INSERT IGNORE INTO permissoes (codigo, modulo, nome, descricao, ordem, ativo) VALUES
  ('pesquisa_reacao_integracao.visualizar', 'pesquisa_reacao_integracao', 'Visualizar', 'Ver campanhas e resultados da Pesquisa de Reação do Treinamento de Integração.', 590, 1),
  ('pesquisa_reacao_integracao.gerenciar', 'pesquisa_reacao_integracao', 'Gerenciar', 'Gerar links de campanha e desativar campanhas da Pesquisa de Reação do Treinamento de Integração.', 600, 1);
