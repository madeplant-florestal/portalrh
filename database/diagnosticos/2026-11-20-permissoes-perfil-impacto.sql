-- =====================================================================================================
-- DIAGNOSTICO - Impacto da politica central de permissoes por perfil (Etapa 2B) - SOMENTE LEITURA: apenas SELECT,
-- sem PREPARE, sem variaveis, sem DDL/DML. Banco: u172743873_portalrh (nome explicito em todas as consultas).
-- MariaDB 11.8 / phpMyAdmin. Resultados AGREGADOS (sem nomes/e-mails). Execute UM BLOCO POR VEZ, ANTES do deploy.
-- "Ativo" = e-mail verificado. Os niveis abaixo espelham PermissaoPerfilPolicy:
--   permitida (5 dashboards) | transitoria (modulos com escopo por Setor Gerenciado) | pendente (sem escopo, uso nao comprovado)
--   | incompativel (nominal/financeira/administrativa: NAO concede mais acesso ao viewer, continua gravada).
-- =====================================================================================================

-- ===== BLOCO 1 - viewers ativos por NIVEL da permissao gravada (quantos usuarios e quantas atribuicoes) ====
SELECT t.nivel,
       COUNT(DISTINCT t.usuario_id) AS viewers_ativos,
       COUNT(*) AS atribuicoes
FROM (
  SELECT up.usuario_id,
         CASE
           WHEN p.codigo IN ('dashboard.visualizar','indicadores_rh.visualizar','dashboard_turnover.visualizar','dashboard_recrutamento.visualizar','dashboard_entrevista_desligamento.visualizar') THEN '1-permitida'
           WHEN p.codigo IN ('colaboradores.visualizar','avaliacao_experiencia.visualizar','avaliacao_experiencia.avaliar','avaliacao_desempenho.visualizar','avaliacao_desempenho.avaliar','feedback.visualizar','feedback.avaliar','pdi.visualizar','pdi.gerenciar','pdi.acompanhar','integracao_colaborador.visualizar','integracao_colaborador.editar','solicitacao_vaga.visualizar','solicitacao_vaga.criar','solicitacao_vaga.editar','solicitacao_vaga.cancelar','kanban_vagas.visualizar','kanban_vagas.detalhes','kanban_vagas.editar','kanban_vagas.movimentar') THEN '2-transitoria_gestor'
           WHEN p.codigo IN ('entrevista_desligamento.visualizar','entrevista_desligamento.gerenciar','entrevista_desligamento.resultados','mensagens.visualizar','mensagens.criar','mensagens.editar','pesquisa_reacao_integracao.visualizar','pesquisa_reacao_integracao.gerenciar') THEN '3-pendente_decisao'
           ELSE '4-incompativel'
         END AS nivel
  FROM u172743873_portalrh.usuarios u
  INNER JOIN u172743873_portalrh.usuario_permissoes up ON up.usuario_id = u.id
  INNER JOIN u172743873_portalrh.permissoes p ON p.id = up.permissao_id AND p.ativo = 1
  WHERE u.role = 'viewer' AND u.email_verified_at IS NOT NULL
) t
GROUP BY t.nivel
ORDER BY t.nivel;

-- ===== BLOCO 2 - permissoes INCOMPATIVEIS gravadas em viewers ativos (perdem efeito; continuam armazenadas) ====
SELECT p.codigo, COUNT(DISTINCT u.id) AS viewers_ativos
FROM u172743873_portalrh.usuarios u
INNER JOIN u172743873_portalrh.usuario_permissoes up ON up.usuario_id = u.id
INNER JOIN u172743873_portalrh.permissoes p ON p.id = up.permissao_id AND p.ativo = 1
WHERE u.role = 'viewer' AND u.email_verified_at IS NOT NULL
  AND p.codigo NOT IN ('dashboard.visualizar','indicadores_rh.visualizar','dashboard_turnover.visualizar','dashboard_recrutamento.visualizar','dashboard_entrevista_desligamento.visualizar',
                       'colaboradores.visualizar','avaliacao_experiencia.visualizar','avaliacao_experiencia.avaliar','avaliacao_desempenho.visualizar','avaliacao_desempenho.avaliar','feedback.visualizar','feedback.avaliar','pdi.visualizar','pdi.gerenciar','pdi.acompanhar','integracao_colaborador.visualizar','integracao_colaborador.editar','solicitacao_vaga.visualizar','solicitacao_vaga.criar','solicitacao_vaga.editar','solicitacao_vaga.cancelar','kanban_vagas.visualizar','kanban_vagas.detalhes','kanban_vagas.editar','kanban_vagas.movimentar',
                       'entrevista_desligamento.visualizar','entrevista_desligamento.gerenciar','entrevista_desligamento.resultados','mensagens.visualizar','mensagens.criar','mensagens.editar','pesquisa_reacao_integracao.visualizar','pesquisa_reacao_integracao.gerenciar')
GROUP BY p.codigo
ORDER BY viewers_ativos DESC, p.codigo;

-- ===== BLOCO 3 - PENDENTES DE DECISAO em viewers ativos (a evidencia de dependencia operacional que falta) ====
-- Entrevista de Desligamento, Mensagens e Pesquisas de Reacao nao filtram por setor no backend. Se houver viewers aqui,
-- decidir: sao operacao legitima (migrar para o futuro Gestor/RH) ou devem perder o acesso?
SELECT p.codigo, COUNT(DISTINCT u.id) AS viewers_ativos
FROM u172743873_portalrh.usuarios u
INNER JOIN u172743873_portalrh.usuario_permissoes up ON up.usuario_id = u.id
INNER JOIN u172743873_portalrh.permissoes p ON p.id = up.permissao_id AND p.ativo = 1
WHERE u.role = 'viewer' AND u.email_verified_at IS NOT NULL
  AND p.codigo IN ('entrevista_desligamento.visualizar','entrevista_desligamento.gerenciar','entrevista_desligamento.resultados','mensagens.visualizar','mensagens.criar','mensagens.editar','pesquisa_reacao_integracao.visualizar','pesquisa_reacao_integracao.gerenciar')
GROUP BY p.codigo
ORDER BY p.codigo;

-- ===== BLOCO 4 - viewers ativos que tem permissoes de GESTOR-DE-FATO (transitorias) e quantos tem Setor Gerenciado ====
-- Indica quem hoje usa o papel de gestor com role viewer (candidatos a migrar para o perfil Gestor na etapa propria).
SELECT COUNT(DISTINCT u.id) AS viewers_com_permissao_transitoria,
       COUNT(DISTINCT CASE WHEN EXISTS (SELECT 1 FROM u172743873_portalrh.usuario_setores_gerenciados g WHERE g.usuario_id = u.id) THEN u.id END) AS destes_com_setor_gerenciado
FROM u172743873_portalrh.usuarios u
INNER JOIN u172743873_portalrh.usuario_permissoes up ON up.usuario_id = u.id
INNER JOIN u172743873_portalrh.permissoes p ON p.id = up.permissao_id AND p.ativo = 1
WHERE u.role = 'viewer' AND u.email_verified_at IS NOT NULL
  AND p.codigo IN ('colaboradores.visualizar','avaliacao_experiencia.visualizar','avaliacao_experiencia.avaliar','avaliacao_desempenho.visualizar','avaliacao_desempenho.avaliar','feedback.visualizar','feedback.avaliar','pdi.visualizar','pdi.gerenciar','pdi.acompanhar','integracao_colaborador.visualizar','integracao_colaborador.editar','solicitacao_vaga.visualizar','solicitacao_vaga.criar','solicitacao_vaga.editar','solicitacao_vaga.cancelar','kanban_vagas.visualizar','kanban_vagas.detalhes','kanban_vagas.editar','kanban_vagas.movimentar');

-- ===== BLOCO 5 - permissoes sem classificacao (codigo novo no catalogo de producao) ===================
-- Deve voltar VAZIO. Qualquer codigo listado aqui nao esta em PermissaoPerfilPolicy e e tratado como incompativel.
SELECT p.codigo
FROM u172743873_portalrh.permissoes p
WHERE p.ativo = 1
  AND p.codigo NOT IN ('dashboard.visualizar','indicadores_rh.visualizar','dashboard_turnover.visualizar','dashboard_recrutamento.visualizar','dashboard_entrevista_desligamento.visualizar',
                       'colaboradores.visualizar','colaboradores.editar','avaliacao_experiencia.visualizar','avaliacao_experiencia.avaliar','avaliacao_desempenho.visualizar','avaliacao_desempenho.avaliar','feedback.visualizar','feedback.avaliar','pdi.visualizar','pdi.gerenciar','pdi.acompanhar','integracao_colaborador.visualizar','integracao_colaborador.editar','solicitacao_vaga.visualizar','solicitacao_vaga.criar','solicitacao_vaga.editar','solicitacao_vaga.cancelar','kanban_vagas.visualizar','kanban_vagas.detalhes','kanban_vagas.editar','kanban_vagas.movimentar',
                       'entrevista_desligamento.visualizar','entrevista_desligamento.gerenciar','entrevista_desligamento.resultados','mensagens.visualizar','mensagens.criar','mensagens.editar','pesquisa_reacao_integracao.visualizar','pesquisa_reacao_integracao.gerenciar',
                       'candidaturas.visualizar','candidaturas.editar','pipeline.visualizar','pipeline.movimentar','indicacoes.visualizar','indicacoes.gerenciar_pagamento','recruitment_webhooks.visualizar','recruitment_webhooks.gerenciar',
                       'empresas.visualizar','empresas.criar','empresas.editar','empresas.excluir','setores.visualizar','setores.criar','setores.editar','setores.excluir','cargos.visualizar','cargos.criar','cargos.editar','cargos.excluir',
                       'beneficios.visualizar','beneficios.criar','beneficios.editar','beneficios.excluir','avaliacoes.visualizar','avaliacoes.criar','avaliacoes.editar','avaliacoes.excluir',
                       'vagas.visualizar','vagas.criar','vagas.editar','vagas.excluir','vagas.publicar','movimentacao_pessoal.visualizar','movimentacao_pessoal.criar','movimentacao_pessoal.editar','movimentacao_pessoal.assinar',
                       'manual.visualizar','comunicacoes.visualizar','pesquisa_experiencia.visualizar')
ORDER BY p.codigo;

-- ===== BLOCO 6 - total de viewers ativos sem nenhuma permissao efetiva (so incompativeis gravadas) ====
-- Esses viewers passam a nao ter acesso a nada alem da Central do Portal.
SELECT COUNT(*) AS viewers_ativos_sem_permissao_efetiva
FROM u172743873_portalrh.usuarios u
WHERE u.role = 'viewer' AND u.email_verified_at IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM u172743873_portalrh.usuario_permissoes up
    INNER JOIN u172743873_portalrh.permissoes p ON p.id = up.permissao_id AND p.ativo = 1
    WHERE up.usuario_id = u.id
      AND p.codigo IN ('dashboard.visualizar','indicadores_rh.visualizar','dashboard_turnover.visualizar','dashboard_recrutamento.visualizar','dashboard_entrevista_desligamento.visualizar',
                       'colaboradores.visualizar','avaliacao_experiencia.visualizar','avaliacao_experiencia.avaliar','avaliacao_desempenho.visualizar','avaliacao_desempenho.avaliar','feedback.visualizar','feedback.avaliar','pdi.visualizar','pdi.gerenciar','pdi.acompanhar','integracao_colaborador.visualizar','integracao_colaborador.editar','solicitacao_vaga.visualizar','solicitacao_vaga.criar','solicitacao_vaga.editar','solicitacao_vaga.cancelar','kanban_vagas.visualizar','kanban_vagas.detalhes','kanban_vagas.editar','kanban_vagas.movimentar',
                       'entrevista_desligamento.visualizar','entrevista_desligamento.gerenciar','entrevista_desligamento.resultados','mensagens.visualizar','mensagens.criar','mensagens.editar','pesquisa_reacao_integracao.visualizar','pesquisa_reacao_integracao.gerenciar'));
