-- Seed: 2026-09-29-avaliacoes-desenvolvimento-permissoes-seed.sql
-- Objetivo:
--   Avaliação de Experiência (45/90) e Feedback (Etapa 4). Mesmo padrão de 2026-09-23-pdi-permissoes-seed.sql:
--   cadastra no catálogo já existente `permissoes` (INSERT IGNORE, idempotente); NÃO concede a ninguém.
--   Permissão individual é a fonte efetiva (Admin só pelo bypass central); escopo por linha via
--   `gestor_usuario_id` para quem não é Admin/RH, igual ao PDI.
--
--   - avaliacao_experiencia.visualizar: lista de pendências e detalhe (no escopo da linha).
--   - avaliacao_experiencia.avaliar: preencher/salvar rascunho, concluir, reabrir (Admin/RH).
--   - feedback.visualizar: lista e detalhe (no escopo da linha).
--   - feedback.avaliar: preencher/salvar rascunho, concluir, reabrir (Admin/RH).
--
--   Depende de `permissoes` (2026-09-15-permissoes-individuais.sql) já aplicada.

INSERT IGNORE INTO permissoes (codigo, modulo, nome, descricao, ordem, ativo) VALUES
  ('avaliacao_experiencia.visualizar', 'avaliacoes_desenvolvimento', 'Visualizar Avaliações de Experiência', 'Ver a lista de pendências (próximas/pendentes/vencidas/aguardando ciência/realizadas) e o detalhe das Avaliações de Período de Experiência (45/90 dias) no seu escopo: Admin/RH veem todas, os demais só as avaliações em que são o gestor responsável.', 700, 1),
  ('avaliacao_experiencia.avaliar', 'avaliacoes_desenvolvimento', 'Avaliar Experiência', 'Preencher, salvar rascunho e concluir a Avaliação de Período de Experiência (45/90 dias). Admin/RH também podem reabrir uma avaliação concluída.', 710, 1),
  ('feedback.visualizar', 'avaliacoes_desenvolvimento', 'Visualizar Feedback', 'Ver a lista e o detalhe dos Feedbacks no seu escopo: Admin/RH veem todos, os demais só os feedbacks em que são o gestor responsável.', 720, 1),
  ('feedback.avaliar', 'avaliacoes_desenvolvimento', 'Registrar Feedback', 'Preencher, salvar rascunho e concluir o Formulário de Feedback e Desenvolvimento. Admin/RH também podem reabrir um feedback concluído.', 730, 1);
