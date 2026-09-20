-- Seed: 2026-09-23-pdi-permissoes-seed.sql
-- Objetivo:
--   PDI (V1 assistida). Cadastra no catálogo já existente `permissoes` (migration 2026-09-15-permissoes-individuais.sql)
--   as três permissões do módulo. NENHUMA tabela nova, NENHUM schema — só linhas em `permissoes` (`INSERT IGNORE`,
--   idempotente). Este seed apenas CADASTRA — NÃO concede a nenhum usuário. Permissão individual é a fonte efetiva
--   (Admin só pelo bypass central); além dela vale o ESCOPO POR LINHA: quem não é Admin/RH só acessa PDIs em que é o
--   gestor responsável (`pdis.gestor_usuario_id`).
--
--   - pdi.visualizar: lista e detalhes (no escopo da linha).
--   - pdi.gerenciar: criar; editar estrutura (gestor, origem, datas, textos, competências, ações).
--   - pdi.acompanhar: acompanhamentos, status, evidências, espaço do colaborador (assistido), avaliação, concluir, reabrir.
--
--   Depende de `permissoes` (2026-09-15-permissoes-individuais.sql) já aplicada.

INSERT IGNORE INTO permissoes (codigo, modulo, nome, descricao, ordem, ativo) VALUES
  ('pdi.visualizar', 'pdi', 'Visualizar', 'Ver a lista e o detalhe dos PDIs (Plano de Desenvolvimento Individual) no seu escopo: Admin/RH veem todos, os demais só os PDIs em que são o gestor responsável.', 660, 1),
  ('pdi.gerenciar', 'pdi', 'Gerenciar', 'Criar PDIs e editar a estrutura do plano: gestor, origem, datas, pontos fortes, oportunidades, objetivo, competências e ações.', 670, 1),
  ('pdi.acompanhar', 'pdi', 'Acompanhar', 'Acompanhar o PDI: registrar acompanhamentos, alterar status, registrar evidências e o espaço do colaborador (assistido), avaliar, concluir, cancelar e (Admin/RH) reabrir.', 680, 1);
