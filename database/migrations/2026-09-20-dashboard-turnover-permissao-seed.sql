-- Seed: 2026-09-20-dashboard-turnover-permissao-seed.sql
-- Objetivo:
--   Sprint "Dashboard de Turnover". Cadastra no catálogo já existente `permissoes` (migration
--   2026-09-15-permissoes-individuais.sql) a permissão de visualização do novo painel.
--
--   NENHUMA tabela nova, NENHUMA alteração de schema — só uma linha nova em `permissoes`
--   (`INSERT IGNORE`, idempotente, respeita `uk_permissoes_codigo`). Este seed apenas CADASTRA a
--   permissão — não a concede a nenhum usuário.
--
--   Backend protegido de forma RÍGIDA desde o início (mesmo padrão de
--   `dashboard_recrutamento.visualizar`): `Authorization::requirePermissao('dashboard_turnover.visualizar')`
--   em `AdminDashboardTurnoverController::index()`, e o item do menu só aparece com a permissão.
--   Admin mantém o bypass central já existente (`Authorization::usuarioEhAdmin()`); RH/Supervisor
--   NÃO recebem acesso automático pela role — só quem tiver a permissão individual concedida na
--   Tela de Usuários.
--
--   Depende de `permissoes` (2026-09-15-permissoes-individuais.sql) já aplicada.

INSERT IGNORE INTO permissoes (codigo, modulo, nome, descricao, ordem, ativo) VALUES
  ('dashboard_turnover.visualizar', 'dashboard_turnover', 'Visualizar', 'Ver o Dashboard de Turnover (evolução mensal, admissões x desligamentos, motivos, cargo, empresa e tempo de empresa).', 610, 1);
