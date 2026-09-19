-- Seed: 2026-09-22-dashboard-entrevista-desligamento-permissao-seed.sql
-- Objetivo:
--   Dashboard da Entrevista de Desligamento. Cadastra no catálogo já existente `permissoes` (migration
--   2026-09-15-permissoes-individuais.sql) a permissão de visualização do painel gerencial.
--
--   NENHUMA tabela nova, NENHUMA alteração de schema — o dashboard só lê `colaboradores_metadados`,
--   `entrevistas_desligamento` e `entrevistas_desligamento_fatores` (já existentes). Só uma linha nova em
--   `permissoes` (`INSERT IGNORE`, idempotente, respeita `uk_permissoes_codigo`). Este seed apenas CADASTRA a
--   permissão — NÃO a concede a nenhum usuário. Permissão individual é a fonte efetiva; Admin mantém só o bypass
--   central. É um recurso próprio: NÃO reutiliza `entrevista_desligamento.resultados`.
--
--   Backend protegido de forma RÍGIDA: `Authorization::requirePermissao('dashboard_entrevista_desligamento.visualizar')`
--   em `AdminDashboardEntrevistaDesligamentoController::index()`; o item do menu só aparece com a permissão.
--   O painel é AGREGADO (sem nome, token, `metadados_id` nem comentários individuais).
--
--   Depende de `permissoes` (2026-09-15-permissoes-individuais.sql) já aplicada.

INSERT IGNORE INTO permissoes (codigo, modulo, nome, descricao, ordem, ativo) VALUES
  ('dashboard_entrevista_desligamento.visualizar', 'dashboard_entrevista_desligamento', 'Visualizar', 'Ver o Dashboard da Entrevista de Desligamento (indicadores agregados: cobertura, motivos declarados, eNPS, liderança, cultura, integração, evolução mensal, unidade e cargo). Não expõe respostas individuais.', 650, 1);
