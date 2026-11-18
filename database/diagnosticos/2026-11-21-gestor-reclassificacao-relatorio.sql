-- =====================================================================================================
-- RELATORIO - Usuarios a reclassificar quando o perfil Gestor existir (Etapa 2B complementar) - SOMENTE LEITURA: apenas
-- SELECT, sem PREPARE, sem variaveis, sem DDL/DML. Banco: u172743873_portalrh (nome explicito). MariaDB 11.8 / phpMyAdmin.
-- SOMENTE IDs e contagens: nenhum nome, e-mail ou CPF. Para identificar a pessoa, abra /admin/usuarios/{id} no Portal.
-- Execute UM BLOCO POR VEZ. "Ativo" = e-mail verificado.
-- Grupos de modulo (ver docs/claude/roadmap-tecnico.md, "Preparacao do perfil Gestor"):
--   A  = pronto para escopo setorial (usuario_setores_gerenciados): colaboradores, avaliacao_experiencia
--   B  = precisa implementar escopo setorial (hoje filtra por gestor imediato/avaliador/solicitante/aprovador): avaliacao_desempenho, feedback, pdi,
--        solicitacao_vaga, kanban_vagas ; e SEM filtro por ator (listagem global): integracao_colaborador
--   X  = deixou de valer para viewer nesta etapa (entrevista_desligamento, pesquisa_reacao_integracao, mensagens)
-- =====================================================================================================

-- ===== BLOCO 1 - por viewer ativo: quantas permissoes em cada grupo + setores gerenciados (so IDs) ==========
SELECT u.id AS usuario_id,
       (SELECT COUNT(*) FROM u172743873_portalrh.usuario_setores_gerenciados g WHERE g.usuario_id = u.id) AS setores_gerenciados,
       SUM(p.codigo LIKE 'colaboradores.%' OR p.codigo LIKE 'avaliacao_experiencia.%') AS grupo_a,
       SUM(p.codigo LIKE 'avaliacao_desempenho.%' OR p.codigo LIKE 'feedback.%' OR p.codigo LIKE 'pdi.%' OR p.codigo LIKE 'solicitacao_vaga.%' OR p.codigo LIKE 'kanban_vagas.%') AS grupo_b_escopo_a_implementar,
       SUM(p.codigo LIKE 'integracao_colaborador.%') AS grupo_b_sem_filtro_por_ator,
       SUM(p.codigo LIKE 'entrevista_desligamento.%' OR p.codigo LIKE 'pesquisa_reacao_integracao.%' OR p.codigo LIKE 'mensagens.%') AS grupo_x_perdeu_acesso,
       SUM(p.codigo IN ('dashboard.visualizar','indicadores_rh.visualizar','dashboard_turnover.visualizar','dashboard_recrutamento.visualizar','dashboard_entrevista_desligamento.visualizar')) AS dashboards
FROM u172743873_portalrh.usuarios u
INNER JOIN u172743873_portalrh.usuario_permissoes up ON up.usuario_id = u.id
INNER JOIN u172743873_portalrh.permissoes p ON p.id = up.permissao_id AND p.ativo = 1
WHERE u.role = 'viewer' AND u.email_verified_at IS NOT NULL
GROUP BY u.id
HAVING grupo_a + grupo_b_escopo_a_implementar + grupo_b_sem_filtro_por_ator + grupo_x_perdeu_acesso > 0
ORDER BY u.id;

-- ===== BLOCO 2 - viewers que PERDERAM acesso nesta etapa (grupo X), por permissao (so contagens) ==========
SELECT p.codigo, COUNT(DISTINCT u.id) AS viewers_ativos
FROM u172743873_portalrh.usuarios u
INNER JOIN u172743873_portalrh.usuario_permissoes up ON up.usuario_id = u.id
INNER JOIN u172743873_portalrh.permissoes p ON p.id = up.permissao_id AND p.ativo = 1
WHERE u.role = 'viewer' AND u.email_verified_at IS NOT NULL
  AND (p.codigo LIKE 'entrevista_desligamento.%' OR p.codigo LIKE 'pesquisa_reacao_integracao.%' OR p.codigo LIKE 'mensagens.%')
GROUP BY p.codigo
ORDER BY p.codigo;

-- ===== BLOCO 3 - viewers com permissoes do grupo B SEM filtro por ator (integracao_colaborador) =====
-- Estes enxergam listagem global hoje; precisam de decisao (RH, Gestor com escopo ou remocao) antes do perfil Gestor.
SELECT p.codigo, COUNT(DISTINCT u.id) AS viewers_ativos
FROM u172743873_portalrh.usuarios u
INNER JOIN u172743873_portalrh.usuario_permissoes up ON up.usuario_id = u.id
INNER JOIN u172743873_portalrh.permissoes p ON p.id = up.permissao_id AND p.ativo = 1
WHERE u.role = 'viewer' AND u.email_verified_at IS NOT NULL
  AND p.codigo LIKE 'integracao_colaborador.%'
GROUP BY p.codigo
ORDER BY p.codigo;

-- ===== BLOCO 4 - viewers que ficam sem NENHUMA permissao efetiva depois desta etapa =====================
-- (so incompativeis gravadas, incluindo as de entrevistas/pesquisas/mensagens). Tem acesso apenas a Central do Portal.
SELECT u.id AS usuario_id
FROM u172743873_portalrh.usuarios u
WHERE u.role = 'viewer' AND u.email_verified_at IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM u172743873_portalrh.usuario_permissoes up
    INNER JOIN u172743873_portalrh.permissoes p ON p.id = up.permissao_id AND p.ativo = 1
    WHERE up.usuario_id = u.id
      AND p.codigo IN ('dashboard.visualizar','indicadores_rh.visualizar','dashboard_turnover.visualizar','dashboard_recrutamento.visualizar','dashboard_entrevista_desligamento.visualizar',
                       'colaboradores.visualizar','avaliacao_experiencia.visualizar','avaliacao_experiencia.avaliar','avaliacao_desempenho.visualizar','avaliacao_desempenho.avaliar','feedback.visualizar','feedback.avaliar','pdi.visualizar','pdi.gerenciar','pdi.acompanhar','integracao_colaborador.visualizar','integracao_colaborador.editar','solicitacao_vaga.visualizar','solicitacao_vaga.criar','solicitacao_vaga.editar','solicitacao_vaga.cancelar','kanban_vagas.visualizar','kanban_vagas.detalhes','kanban_vagas.editar','kanban_vagas.movimentar'))
ORDER BY u.id;
