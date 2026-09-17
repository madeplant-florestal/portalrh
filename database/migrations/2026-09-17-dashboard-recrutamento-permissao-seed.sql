-- Seed: 2026-09-17-dashboard-recrutamento-permissao-seed.sql
-- Objetivo:
--   Sprint "Dashboard de Recrutamento e Seleção". Cadastra no catálogo já existente `permissoes`
--   (migration 2026-09-15-permissoes-individuais.sql) a permissão de visualização do novo painel.
--
--   NENHUMA tabela nova, NENHUMA alteração de schema — só uma linha nova em `permissoes`
--   (`INSERT IGNORE`, idempotente, respeita `uk_permissoes_codigo`).
--
--   Diferente da rodada "cobertura de permissões" (aditiva, para preservar acesso legado): aqui o
--   backend é protegido de forma RÍGIDA desde o início —
--   `Authorization::requirePermissao('dashboard_recrutamento.visualizar')` em
--   `AdminDashboardRecrutamentoController::index()`. Admin mantém o bypass central já existente
--   (`Authorization::usuarioEhAdmin()`); RH/Supervisor NÃO recebem acesso automático pela role —
--   só quem tiver a permissão individual concedida na Tela de Usuários.
--
--   Depende de `permissoes` (2026-09-15-permissoes-individuais.sql) já aplicada.

INSERT IGNORE INTO permissoes (codigo, modulo, nome, descricao, ordem, ativo) VALUES
  ('dashboard_recrutamento.visualizar', 'dashboard_recrutamento', 'Visualizar', 'Ver o Dashboard de Recrutamento e Seleção.', 580, 1);
