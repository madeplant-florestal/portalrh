-- =====================================================================================================
-- DIAGNOSTICO - Impacto da Etapa 2A (correcao de autorizacao) - SOMENTE LEITURA: apenas SELECT, sem PREPARE,
-- sem variaveis, sem DDL/DML. Banco: u172743873_portalrh (nome explicito em todas as consultas).
-- Compativel com MariaDB 11.8 e phpMyAdmin. Resultados AGREGADOS: nenhum nome, e-mail ou dado pessoal.
-- Execute UM BLOCO POR VEZ e cole os resultados. "Ativo" = e-mail verificado (email_verified_at IS NOT NULL),
-- a regra real de usuario ativo do Portal. EXECUTE ANTES DO DEPLOY (principalmente o BLOCO 7).
-- =====================================================================================================

-- ===== BLOCO 1 - usuarios por role (total e ativos) ==================================================
SELECT u.role,
       COUNT(*) AS usuarios,
       SUM(u.email_verified_at IS NOT NULL) AS ativos
FROM u172743873_portalrh.usuarios u
GROUP BY u.role
ORDER BY u.role;

-- ===== BLOCO 2 - viewers ativos e quantos tem QUALQUER permissao individual =========================
SELECT COUNT(*) AS viewers_ativos,
       SUM(EXISTS (SELECT 1 FROM u172743873_portalrh.usuario_permissoes up WHERE up.usuario_id = u.id)) AS com_alguma_permissao,
       SUM(NOT EXISTS (SELECT 1 FROM u172743873_portalrh.usuario_permissoes up WHERE up.usuario_id = u.id)) AS sem_nenhuma_permissao
FROM u172743873_portalrh.usuarios u
WHERE u.role = 'viewer' AND u.email_verified_at IS NOT NULL;

-- ===== BLOCO 3 - viewers ativos por permissao relevante a esta etapa ================================
-- dashboard.visualizar: continuam no painel, mas PERDEM a lista nominal e o CSV.
-- indicadores_rh.visualizar: passa a ser EXIGIDA (antes bastava a role) - quem nao a tem perde a tela.
SELECT p.codigo,
       COUNT(DISTINCT u.id) AS viewers_ativos_com_a_permissao
FROM u172743873_portalrh.usuarios u
INNER JOIN u172743873_portalrh.usuario_permissoes up ON up.usuario_id = u.id
INNER JOIN u172743873_portalrh.permissoes p ON p.id = up.permissao_id AND p.ativo = 1
WHERE u.role = 'viewer' AND u.email_verified_at IS NOT NULL
  AND p.codigo IN ('dashboard.visualizar', 'indicadores_rh.visualizar')
GROUP BY p.codigo
ORDER BY p.codigo;

-- ===== BLOCO 4 - viewers ativos com permissoes de CANDIDATURAS / PIPELINE / INDICACOES (acesso sera cortado) ====
SELECT p.codigo,
       COUNT(DISTINCT u.id) AS viewers_ativos_com_a_permissao
FROM u172743873_portalrh.usuarios u
INNER JOIN u172743873_portalrh.usuario_permissoes up ON up.usuario_id = u.id
INNER JOIN u172743873_portalrh.permissoes p ON p.id = up.permissao_id AND p.ativo = 1
WHERE u.role = 'viewer' AND u.email_verified_at IS NOT NULL
  AND (p.codigo LIKE 'candidaturas.%' OR p.codigo LIKE 'pipeline.%' OR p.codigo LIKE 'indicacoes.%')
GROUP BY p.codigo
ORDER BY p.codigo;

-- ===== BLOCO 5 - viewers ativos com permissoes de GERENCIAMENTO / FINANCEIRAS / ESCRITA (revisar na Etapa 2B) ====
-- Qualquer codigo terminado em gerenciar, gerenciar_pagamento, editar, criar, excluir, movimentar, assinar, publicar,
-- avaliar ou cancelar, concedido a quem tem role viewer.
SELECT p.codigo,
       COUNT(DISTINCT u.id) AS viewers_ativos_com_a_permissao
FROM u172743873_portalrh.usuarios u
INNER JOIN u172743873_portalrh.usuario_permissoes up ON up.usuario_id = u.id
INNER JOIN u172743873_portalrh.permissoes p ON p.id = up.permissao_id AND p.ativo = 1
WHERE u.role = 'viewer' AND u.email_verified_at IS NOT NULL
  AND SUBSTRING_INDEX(p.codigo, '.', -1) IN ('gerenciar', 'gerenciar_pagamento', 'editar', 'criar', 'excluir', 'movimentar', 'assinar', 'publicar', 'avaliar', 'cancelar')
GROUP BY p.codigo
ORDER BY p.codigo;

-- ===== BLOCO 6 - viewers ativos por QUANTIDADE de permissoes (perfil de concessao) ==================
SELECT t.qtd_permissoes, COUNT(*) AS viewers_ativos
FROM (
  SELECT u.id, COUNT(up.permissao_id) AS qtd_permissoes
  FROM u172743873_portalrh.usuarios u
  LEFT JOIN u172743873_portalrh.usuario_permissoes up ON up.usuario_id = u.id
  WHERE u.role = 'viewer' AND u.email_verified_at IS NOT NULL
  GROUP BY u.id
) t
GROUP BY t.qtd_permissoes
ORDER BY t.qtd_permissoes;

-- ===== BLOCO 7 - is_supervisor = 1 por role (A CONSULTA CRITICA ANTES DO DEPLOY) =====================
-- O flag passa a valer SOMENTE junto com role admin. Linhas com role rh/viewer abaixo PERDEM o bypass.
-- Esperado: apenas role = admin. Se aparecer rh/viewer, decida (corrigir a role ou aceitar a perda) ANTES de publicar.
SELECT u.role,
       COUNT(*) AS usuarios_is_supervisor,
       SUM(u.email_verified_at IS NOT NULL) AS ativos
FROM u172743873_portalrh.usuarios u
WHERE u.is_supervisor = 1
GROUP BY u.role
ORDER BY u.role;

-- ===== BLOCO 8 - usuarios potencialmente afetados (agregado, sem identificacao) ======================
SELECT 'viewers ativos que perdem a lista nominal/CSV do People Analytics (tem dashboard.visualizar)' AS impacto,
       COUNT(DISTINCT u.id) AS usuarios
FROM u172743873_portalrh.usuarios u
INNER JOIN u172743873_portalrh.usuario_permissoes up ON up.usuario_id = u.id
INNER JOIN u172743873_portalrh.permissoes p ON p.id = up.permissao_id AND p.ativo = 1 AND p.codigo = 'dashboard.visualizar'
WHERE u.role = 'viewer' AND u.email_verified_at IS NOT NULL
UNION ALL
SELECT 'viewers ativos que perdem Candidaturas/Pipeline/Indicacoes (tem alguma permissao desses modulos)',
       COUNT(DISTINCT u.id)
FROM u172743873_portalrh.usuarios u
INNER JOIN u172743873_portalrh.usuario_permissoes up ON up.usuario_id = u.id
INNER JOIN u172743873_portalrh.permissoes p ON p.id = up.permissao_id AND p.ativo = 1
  AND (p.codigo LIKE 'candidaturas.%' OR p.codigo LIKE 'pipeline.%' OR p.codigo LIKE 'indicacoes.%')
WHERE u.role = 'viewer' AND u.email_verified_at IS NOT NULL
UNION ALL
SELECT 'viewers ativos que perdem Indicadores de RH (NAO tem indicadores_rh.visualizar)',
       COUNT(*)
FROM u172743873_portalrh.usuarios u
WHERE u.role = 'viewer' AND u.email_verified_at IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM u172743873_portalrh.usuario_permissoes up
    INNER JOIN u172743873_portalrh.permissoes p ON p.id = up.permissao_id AND p.ativo = 1 AND p.codigo = 'indicadores_rh.visualizar'
    WHERE up.usuario_id = u.id)
UNION ALL
SELECT 'usuarios is_supervisor=1 com role diferente de admin (perdem o bypass)',
       COUNT(*)
FROM u172743873_portalrh.usuarios u
WHERE u.is_supervisor = 1 AND u.role <> 'admin';
