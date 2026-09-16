-- Seed: 2026-09-16-comunicacao-pesquisa-integracao-seed.sql
-- Objetivo:
--   1) Cadastrar as 4 permissões novas desta sprint no catálogo já existente `permissoes`
--      (migration 2026-09-15-permissoes-individuais.sql) — nenhuma tabela nova para isso.
--   2) Cadastrar o novo template `pesquisa_experiencia_candidato` em `mensagens`
--      (migration 2026-09-16-mensagens.sql, já aplicada) — texto exatamente como fornecido.
--
--   Idempotente: `INSERT IGNORE` respeita `uk_permissoes_codigo` e `uk_mensagens_codigo` — pode
--   ser reexecutado sem duplicar nem falhar.
--
--   NÃO altera a migration/seed anterior de Mensagens (2026-09-16-mensagens-seed.sql) — este é um
--   seed incremental separado, específico desta sprint.
--
--   Depende de `permissoes` (2026-09-15-permissoes-individuais.sql) e `mensagens`
--   (2026-09-16-mensagens.sql) já aplicadas.

INSERT IGNORE INTO permissoes (codigo, modulo, nome, descricao, ordem, ativo) VALUES
  ('comunicacoes.visualizar', 'comunicacoes', 'Visualizar', 'Ver o histórico de comunicação de um candidato.', 120, 1),
  ('pesquisa_experiencia.visualizar', 'pesquisa_experiencia', 'Visualizar', 'Ver o resultado da pesquisa de experiência de um candidato.', 130, 1),
  ('integracao_colaborador.visualizar', 'integracao_colaborador', 'Visualizar', 'Ver os dados de integração (onboarding) de um colaborador.', 140, 1),
  ('integracao_colaborador.editar', 'integracao_colaborador', 'Editar', 'Registrar/editar a integração (onboarding) de um colaborador.', 150, 1);

INSERT IGNORE INTO mensagens (codigo, titulo, descricao, conteudo, ativo) VALUES
('pesquisa_experiencia_candidato', 'Pesquisa de Experiência do Candidato', NULL, 'Olá, [Nome].

Agradecemos sua participação em nosso processo seletivo.

Gostaríamos de conhecer sua opinião sobre sua experiência conosco. Sua avaliação é muito importante para continuarmos melhorando nosso processo de recrutamento e seleção.

Para responder à pesquisa, acesse:

[Link da Pesquisa]

Agradecemos sua participação.

Atenciosamente,
Equipe de RH', 1);
