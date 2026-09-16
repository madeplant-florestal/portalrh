-- Seed: 2026-09-16-mensagens-seed.sql
-- Objetivo:
--   1) Cadastrar os 3 permissões novas do módulo (mensagens.visualizar/criar/editar) no catálogo
--      já existente `permissoes` (migration 2026-09-15-permissoes-individuais.sql) — nenhuma
--      tabela nova para isso, só linhas novas no catálogo, como já é o padrão da sprint anterior.
--   2) Cadastrar os 7 templates iniciais do processo seletivo, com o texto exatamente como
--      fornecido pelo RH (nenhuma reescrita).
--
--   Idempotente: `INSERT IGNORE` respeita `uk_permissoes_codigo` e `uk_mensagens_codigo` — pode
--   ser reexecutado sem duplicar nem falhar.
--
--   Depende de 2026-09-16-mensagens.sql (tabela `mensagens`) e de
--   2026-09-15-permissoes-individuais.sql (tabela `permissoes`) já aplicadas.

INSERT IGNORE INTO permissoes (codigo, modulo, nome, descricao, ordem, ativo) VALUES
  ('mensagens.visualizar', 'mensagens', 'Visualizar', 'Ver a listagem de mensagens do processo seletivo.', 90, 1),
  ('mensagens.criar',      'mensagens', 'Criar',       'Cadastrar uma nova mensagem.',                      100, 1),
  ('mensagens.editar',     'mensagens', 'Editar',      'Editar título, descrição, conteúdo ou status de uma mensagem existente.', 110, 1);

INSERT IGNORE INTO mensagens (codigo, titulo, descricao, conteudo, ativo) VALUES
('reprovacao_triagem', 'Reprovação — Triagem', NULL, 'Olá, [Nome].
Agradecemos seu interesse em fazer parte da Madeplant e sua participação em nosso processo seletivo.
Após análise do seu perfil em relação aos requisitos desta oportunidade, optamos por seguir com outros candidatos que apresentam aderência mais próxima às necessidades da posição neste momento.
Seu currículo poderá permanecer em nosso banco de talentos para futuras oportunidades.
Desejamos sucesso em sua trajetória profissional.
Atenciosamente,
Equipe de RH', 1),

('reprovacao_apos_entrevista', 'Reprovação — Após Entrevista', NULL, 'Olá, [Nome].
Agradecemos sua participação em nosso processo seletivo e o tempo dedicado durante as etapas realizadas.
Após avaliação do perfil e alinhamento com os requisitos da vaga, optamos por seguir com outro candidato para esta oportunidade.
Reconhecemos suas qualificações e agradecemos seu interesse em fazer parte da Madeplant.
Desejamos sucesso em seus próximos desafios profissionais.
Atenciosamente,
Equipe de RH', 1),

('banco_talentos', 'Banco de Talentos', NULL, 'Olá, [Nome].

Agradecemos sua participação em nosso processo seletivo.
Neste momento não seguiremos com sua candidatura para esta vaga específica, porém identificamos potencial em seu perfil e gostaríamos de mantê-lo em nosso banco de talentos para futuras oportunidades compatíveis com sua experiência e objetivos profissionais.
Agradecemos seu interesse em nossa empresa e desejamos sucesso em sua trajetória.
Atenciosamente,
Equipe de RH', 1),

('convocacao_entrevista_rh', 'Convocação para Entrevista RH', NULL, 'Olá, [Nome].
Parabéns! Seu perfil foi selecionado para a próxima etapa do nosso processo seletivo.
Gostaríamos de convidá-lo(a) para uma entrevista conforme informações abaixo:
📅 Data: [Data]
🕒 Horário: [Horário]
👤 Entrevistador(a): [Responsável]
📍 Local/Link: [Local ou Link]
Caso tenha qualquer dificuldade de comparecimento, pedimos que nos informe com antecedência.
Desejamos sucesso nesta etapa e agradecemos seu interesse em fazer parte da Madeplant.
Atenciosamente,
Equipe de RH', 1),

('convocacao_entrevista_gestor', 'Convocação para Entrevista com Gestor', NULL, 'Olá, [Nome].
Parabéns! Você foi aprovado(a) na etapa de entrevista com o RH e avançou para a próxima fase do nosso processo seletivo.
Gostaríamos de convidá-lo(a) para uma entrevista com o gestor da área, momento em que serão aprofundados aspectos relacionados à posição, às atividades da função e ao alinhamento com a equipe.
Confira os detalhes abaixo:
📅 Data: [Data]
🕒 Horário: [Horário]
👤 Gestor(a): [Nome do Gestor]
📍 Local/Link: [Local ou Link]
Caso tenha qualquer impedimento para participação, pedimos a gentileza de nos informar com antecedência.
Agradecemos sua participação até aqui e desejamos sucesso nesta próxima etapa.
Atenciosamente,
Equipe de RH', 1),

('aprovacao_carta_proposta', 'Aprovação Final e Carta Proposta', 'Associada conceitualmente à movimentação do Kanban para "Aprovado" e ao recurso de Gerar Carta Proposta (já existente — não alterado nesta sprint).', 'Olá, [Nome].
Temos o prazer de informar que você foi aprovado(a) em nosso processo seletivo.
Anexamos a Carta Proposta contendo as informações da oportunidade para sua avaliação.
Pedimos a gentileza de analisar as condições apresentadas e registrar seu aceite através do sistema.
Ficamos muito felizes com a possibilidade de contar com você em nosso time.
Atenciosamente,
Equipe de RH', 1),

('agendamento_exame_admissional', 'Agendamento de Exame Admissional', NULL, 'Olá, [Nome].
Recebemos o aceite da sua proposta e estamos muito felizes em tê-lo(a) conosco.
O próximo passo é a realização do exame admissional.
📅 Data: [Data]
🕒 Horário: [Horário]
🏥 Clínica: [Nome da Clínica]
📍 Endereço: [Endereço]
📞 Contato: [Telefone]
Solicitamos que compareça portando documento oficial com foto.
Em caso de dúvidas, nossa equipe está à disposição.
Atenciosamente,
Equipe de RH', 1);
