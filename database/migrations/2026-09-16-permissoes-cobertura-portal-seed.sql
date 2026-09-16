-- Seed: 2026-09-16-permissoes-cobertura-portal-seed.sql
-- Objetivo:
--   Ajuste "Tela de Usuários + Cobertura Completa de Permissões". Cadastra no catálogo já
--   existente `permissoes` (migration 2026-09-15-permissoes-individuais.sql) as capacidades REAIS
--   dos módulos administrativos do Portal que ainda não tinham nenhuma permissão individual
--   mapeada — levantadas por inspeção direta dos controllers/rotas correspondentes (nunca
--   inventadas): AdminController, AdminRhIndicadoresController, AdminCandidaturasController,
--   AdminPipelineController, AdminRecruitmentWebhooksController, AdminEmpresasController,
--   AdminSetoresController, AdminCargosController, AdminColaboradoresController,
--   AdminBeneficiosController, AdminAvaliacoesController, AdminVagasController,
--   AdminMovimentacoesPessoalController, AdminIndicacoesController, AdminManualController.
--
--   NENHUMA tabela nova, NENHUMA alteração de schema — só linhas novas em `permissoes`
--   (`INSERT IGNORE`, idempotente, respeita `uk_permissoes_codigo`). Não toca nas permissões já
--   publicadas (solicitacao_vaga.*, kanban_vagas.*, mensagens.*, comunicacoes.visualizar,
--   pesquisa_experiencia.visualizar, integracao_colaborador.*) nem em nenhuma migration antiga.
--
--   Uso desta rodada (decisão confirmada explicitamente pelo Fabio): ADITIVO — o backend desses
--   controllers continua exatamente como está (`Auth::requireRole([...])` inalterado; RH/Admin
--   mantêm 100% do acesso que já tinham). As novas permissões alimentam o MENU (mostra o item se
--   o usuário já tinha acesso pela role OU passou a ter a permissão nova) e a Tela de Usuários —
--   preparando o catálogo para o dia em que cada módulo precisar de controle mais fino, sem quebrar
--   nenhum fluxo hoje em produção. Ações tipo `.excluir`/`.publicar`/`.assinar`/`.movimentar` só
--   foram criadas onde o controller realmente possui esse método separado (Empresas/Setores/
--   Cargos/Benefícios/Avaliações têm delete() real; Vagas tem delete()+publicar() reais;
--   Movimentação de Pessoal tem signRh() real; Pipeline/Kanban tem move() real; Webhooks tem
--   settings/regenerate-secret/test/process-pending/retry reais) — nada foi criado só por padrão.
--
--   Depende de `permissoes` (2026-09-15-permissoes-individuais.sql) já aplicada.

INSERT IGNORE INTO permissoes (codigo, modulo, nome, descricao, ordem, ativo) VALUES
  ('dashboard.visualizar', 'dashboard', 'Visualizar', 'Ver o painel inicial (Dashboard) do Portal.', 160, 1),

  ('indicadores_rh.visualizar', 'indicadores_rh', 'Visualizar', 'Ver o dashboard de Indicadores de RH.', 170, 1),

  ('candidaturas.visualizar', 'candidaturas', 'Visualizar', 'Ver a listagem e o detalhe de candidaturas, incluindo baixar o currículo.', 180, 1),
  ('candidaturas.editar', 'candidaturas', 'Editar', 'Atualizar etapa/status, observações e dados do Programa de Indicações de uma candidatura.', 190, 1),

  ('pipeline.visualizar', 'pipeline', 'Visualizar', 'Ver o Pipeline Kanban de candidatos.', 200, 1),
  ('pipeline.movimentar', 'pipeline', 'Movimentar', 'Mover um candidato entre etapas do Pipeline Kanban.', 210, 1),

  ('recruitment_webhooks.visualizar', 'recruitment_webhooks', 'Visualizar', 'Ver a tela de Webhooks do recrutamento.', 220, 1),
  ('recruitment_webhooks.gerenciar', 'recruitment_webhooks', 'Gerenciar', 'Salvar configurações, regenerar segredo, testar, reprocessar pendências e reenviar eventos de webhook.', 230, 1),

  ('empresas.visualizar', 'empresas', 'Visualizar', 'Ver o cadastro de Empresas.', 240, 1),
  ('empresas.criar', 'empresas', 'Criar', 'Cadastrar uma nova Empresa.', 250, 1),
  ('empresas.editar', 'empresas', 'Editar', 'Editar uma Empresa existente.', 260, 1),
  ('empresas.excluir', 'empresas', 'Excluir', 'Excluir uma Empresa.', 270, 1),

  ('setores.visualizar', 'setores', 'Visualizar', 'Ver o cadastro de Setores.', 280, 1),
  ('setores.criar', 'setores', 'Criar', 'Cadastrar um novo Setor.', 290, 1),
  ('setores.editar', 'setores', 'Editar', 'Editar um Setor existente.', 300, 1),
  ('setores.excluir', 'setores', 'Excluir', 'Excluir um Setor.', 310, 1),

  ('cargos.visualizar', 'cargos', 'Visualizar', 'Ver o cadastro de Cargos.', 320, 1),
  ('cargos.criar', 'cargos', 'Criar', 'Cadastrar um novo Cargo.', 330, 1),
  ('cargos.editar', 'cargos', 'Editar', 'Editar um Cargo existente.', 340, 1),
  ('cargos.excluir', 'cargos', 'Excluir', 'Excluir um Cargo.', 350, 1),

  ('colaboradores.visualizar', 'colaboradores', 'Visualizar', 'Ver a listagem de Colaboradores.', 360, 1),
  ('colaboradores.editar', 'colaboradores', 'Editar', 'Editar dados de RH e a tela de Acesso/Liderança de um Colaborador.', 370, 1),

  ('beneficios.visualizar', 'beneficios', 'Visualizar', 'Ver o cadastro de Benefícios.', 380, 1),
  ('beneficios.criar', 'beneficios', 'Criar', 'Cadastrar um novo Benefício.', 390, 1),
  ('beneficios.editar', 'beneficios', 'Editar', 'Editar um Benefício existente.', 400, 1),
  ('beneficios.excluir', 'beneficios', 'Excluir', 'Excluir um Benefício.', 410, 1),

  ('avaliacoes.visualizar', 'avaliacoes', 'Visualizar', 'Ver o cadastro de Avaliações.', 420, 1),
  ('avaliacoes.criar', 'avaliacoes', 'Criar', 'Cadastrar uma nova Avaliação.', 430, 1),
  ('avaliacoes.editar', 'avaliacoes', 'Editar', 'Editar uma Avaliação existente.', 440, 1),
  ('avaliacoes.excluir', 'avaliacoes', 'Excluir', 'Excluir uma Avaliação.', 450, 1),

  ('vagas.visualizar', 'vagas', 'Visualizar', 'Ver a listagem de Vagas.', 460, 1),
  ('vagas.criar', 'vagas', 'Criar', 'Cadastrar uma nova Vaga.', 470, 1),
  ('vagas.editar', 'vagas', 'Editar', 'Editar uma Vaga existente.', 480, 1),
  ('vagas.excluir', 'vagas', 'Excluir', 'Excluir uma Vaga.', 490, 1),
  ('vagas.publicar', 'vagas', 'Publicar', 'Publicar/despublicar uma Vaga.', 500, 1),

  ('movimentacao_pessoal.visualizar', 'movimentacao_pessoal', 'Visualizar', 'Ver a listagem e o detalhe de Movimentações de Pessoal.', 510, 1),
  ('movimentacao_pessoal.criar', 'movimentacao_pessoal', 'Criar', 'Registrar uma nova Movimentação de Pessoal.', 520, 1),
  ('movimentacao_pessoal.editar', 'movimentacao_pessoal', 'Editar', 'Editar uma Movimentação de Pessoal existente.', 530, 1),
  ('movimentacao_pessoal.assinar', 'movimentacao_pessoal', 'Assinar (RH)', 'Registrar a assinatura/aprovação de RH numa Movimentação de Pessoal.', 540, 1),

  ('indicacoes.visualizar', 'indicacoes', 'Visualizar', 'Ver a listagem e os relatórios do Programa de Indicações.', 550, 1),
  ('indicacoes.gerenciar_pagamento', 'indicacoes', 'Gerenciar pagamento', 'Marcar como pago e ajustar a data de pagamento de uma indicação.', 560, 1),

  ('manual.visualizar', 'manual', 'Visualizar', 'Ver o Manual de Uso do Portal.', 570, 1);

-- "Link vagas públicas" (item do menu) foi analisado (§13 do ajuste) e NÃO recebeu permissão: é só
-- um atalho para a página pública `/vagas`, que já não exige autenticação nenhuma — esconder o
-- atalho no menu administrativo não protegeria nada (qualquer visitante anônimo já acessa a mesma
-- URL livremente). Não é uma capacidade administrativa real, então nenhum código foi criado.
