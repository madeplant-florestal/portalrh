-- Seed: 2026-09-15-permissoes-individuais-seed.sql
-- Objetivo:
--   1) Catalogar as 8 permissões iniciais (Solicitação de Vaga + Kanban de Vagas).
--   2) Compatibilidade: conceder `solicitacao_vaga.criar` a todo usuário que hoje já tem
--      `usuarios.pode_solicitar_vaga = 1`, para que ninguém perca a capacidade durante a
--      transição para o novo mecanismo. `pode_solicitar_vaga` NÃO é removida nesta sprint.
--
--   Idempotente: `INSERT IGNORE` respeita `uk_permissoes_codigo` e a PK composta de
--   `usuario_permissoes` — pode ser reexecutado sem duplicar nem falhar.
--
--   Depende de 2026-09-15-permissoes-individuais.sql já aplicada (tabelas `permissoes` e
--   `usuario_permissoes` existentes).

INSERT IGNORE INTO permissoes (codigo, modulo, nome, descricao, ordem, ativo) VALUES
  ('solicitacao_vaga.visualizar', 'solicitacao_vaga', 'Visualizar',   'Ver a lista e os detalhes de Solicitações de Vaga.', 10, 1),
  ('solicitacao_vaga.criar',      'solicitacao_vaga', 'Criar',        'Abrir uma nova Solicitação de Vaga.',                20, 1),
  ('solicitacao_vaga.editar',     'solicitacao_vaga', 'Editar',       'Editar dados de uma Solicitação de Vaga existente.', 30, 1),
  ('solicitacao_vaga.cancelar',   'solicitacao_vaga', 'Cancelar',     'Cancelar uma Solicitação de Vaga.',                  40, 1),
  ('kanban_vagas.visualizar',     'kanban_vagas',     'Visualizar',   'Ver o Kanban de Solicitações de Vaga.',              50, 1),
  ('kanban_vagas.detalhes',       'kanban_vagas',     'Abrir detalhes','Abrir os detalhes de um card do Kanban.',           60, 1),
  ('kanban_vagas.editar',         'kanban_vagas',     'Editar',       'Editar dados de um card do Kanban.',                 70, 1),
  ('kanban_vagas.movimentar',     'kanban_vagas',     'Movimentar',   'Mover um card entre etapas do Kanban.',              80, 1);

INSERT IGNORE INTO usuario_permissoes (usuario_id, permissao_id)
SELECT u.id, p.id
FROM usuarios u
CROSS JOIN permissoes p
WHERE u.pode_solicitar_vaga = 1
  AND p.codigo = 'solicitacao_vaga.criar';
