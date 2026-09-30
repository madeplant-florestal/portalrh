-- Seed: 2026-09-30-avaliacoes-desempenho-permissoes-seed.sql
-- Objetivo:
--   Avaliação de Desempenho nativa (Etapa 6). Mesmo padrão de
--   2026-09-29-avaliacoes-desenvolvimento-permissoes-seed.sql: cadastra no catálogo já existente
--   `permissoes` (INSERT IGNORE, idempotente); NÃO concede a ninguém. Permissão individual é a
--   fonte efetiva (Admin só pelo bypass central); escopo por linha via `gestor_usuario_id` para
--   quem não é Admin/RH, igual Experiência/Feedback/PDI. Reabrir/cancelar seguem o MESMO padrão já
--   validado (permissão `.avaliar` + escopo total do ator — Admin/RH — para a ação de reabrir; sem
--   permissão nova, evita granularidade desnecessária).
--
--   - avaliacao_desempenho.visualizar: lista e detalhe (no escopo da linha).
--   - avaliacao_desempenho.avaliar: preencher/salvar rascunho, concluir, cancelar; reabrir e cancelar
--     de terceiros exigem também escopo total (Admin/RH), igual ao padrão já usado.
--
--   Depende de `permissoes` (2026-09-15-permissoes-individuais.sql) já aplicada.

INSERT IGNORE INTO permissoes (codigo, modulo, nome, descricao, ordem, ativo) VALUES
  ('avaliacao_desempenho.visualizar', 'avaliacoes_desenvolvimento', 'Visualizar Avaliação de Desempenho', 'Ver a lista e o detalhe das Avaliações de Desempenho no seu escopo: Admin/RH veem todas, os demais só as avaliações em que são o gestor responsável.', 740, 1),
  ('avaliacao_desempenho.avaliar', 'avaliacoes_desenvolvimento', 'Avaliar Desempenho', 'Preencher, salvar rascunho, concluir e cancelar a Avaliação de Desempenho. Admin/RH também podem reabrir uma avaliação concluída.', 750, 1);
