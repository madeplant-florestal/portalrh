-- Seed: 2026-09-21-entrevista-desligamento-permissoes-seed.sql
-- Objetivo:
--   Módulo "Entrevista de Desligamento". Cadastra no catálogo já existente `permissoes` (migration
--   2026-09-15-permissoes-individuais.sql) as três permissões do módulo.
--
--   NENHUMA tabela nova, NENHUMA alteração de schema — só linhas em `permissoes` (`INSERT IGNORE`,
--   idempotente, respeita `uk_permissoes_codigo`). Este seed apenas CADASTRA as permissões — NÃO as concede
--   a nenhum usuário. Permissão individual é a fonte efetiva; Admin mantém só o bypass central.
--
--   - entrevista_desligamento.visualizar: módulo e situação operacional (elegíveis, pendentes, respondidas,
--     expiradas, canceladas). NÃO lê respostas individuais.
--   - entrevista_desligamento.gerenciar: gerar, regenerar e cancelar links.
--   - entrevista_desligamento.resultados: resultado individual identificado e indicadores derivados das respostas.
--
--   Depende de `permissoes` (2026-09-15-permissoes-individuais.sql) já aplicada.

INSERT IGNORE INTO permissoes (codigo, modulo, nome, descricao, ordem, ativo) VALUES
  ('entrevista_desligamento.visualizar', 'entrevista_desligamento', 'Visualizar', 'Acessar a Entrevista de Desligamento e ver a situação operacional (elegíveis, pendentes, respondidas, expiradas e canceladas). Não permite ler respostas.', 620, 1),
  ('entrevista_desligamento.gerenciar', 'entrevista_desligamento', 'Gerenciar', 'Gerar, regenerar e cancelar o link da Entrevista de Desligamento.', 630, 1),
  ('entrevista_desligamento.resultados', 'entrevista_desligamento', 'Resultados', 'Ver o resultado individual identificado (respostas e comentários) e os indicadores da Entrevista de Desligamento.', 640, 1);
