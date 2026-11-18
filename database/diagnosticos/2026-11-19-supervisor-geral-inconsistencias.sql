-- =====================================================================================================
-- DIAGNOSTICO - Supervisor Geral e contas privilegiadas (Etapa 2A-bis) - SOMENTE LEITURA: apenas SELECT, sem PREPARE,
-- sem variaveis, sem DDL/DML. Banco: u172743873_portalrh (nome explicito em todas as consultas). MariaDB 11.8 /
-- phpMyAdmin. Execute UM BLOCO POR VEZ, ANTES do deploy. "Ativo" = e-mail verificado (email_verified_at IS NOT NULL).
-- Regra nova: Supervisor Geral = role 'admin' E is_supervisor = 1. O flag em qualquer outra role e INCONSISTENTE
-- (nao concede privilegio; nenhuma conta e alterada automaticamente - a decisao e administrativa).
-- =====================================================================================================

-- ===== BLOCO 1 - Supervisores Gerais (admin + flag): total e ativos ==================================
-- Precisa haver PELO MENOS 1 ativo: o sistema recusa excluir/desativar/remover o ultimo.
SELECT COUNT(*) AS supervisores_gerais,
       SUM(u.email_verified_at IS NOT NULL) AS ativos
FROM u172743873_portalrh.usuarios u
WHERE u.role = 'admin' AND u.is_supervisor = 1;

-- ===== BLOCO 2 - contas INCONSISTENTES (flag em role diferente de admin) =============================
-- Estas contas PERDEM o bypass que o flag dava. Sem nome/e-mail: localize pelo id na tela de Usuarios.
SELECT u.id, u.role, (u.email_verified_at IS NOT NULL) AS ativo, u.created_at
FROM u172743873_portalrh.usuarios u
WHERE u.is_supervisor = 1 AND u.role <> 'admin'
ORDER BY u.id;

-- ===== BLOCO 3 - resumo por nivel hierarquico ========================================================
SELECT CASE
         WHEN u.role = 'admin' AND u.is_supervisor = 1 THEN '1-supervisor_geral'
         WHEN u.role = 'admin' THEN '2-administrador'
         WHEN u.role = 'rh' THEN '3-rh'
         WHEN u.role = 'viewer' THEN '5-viewer'
         ELSE '9-outro'
       END AS nivel,
       COUNT(*) AS usuarios,
       SUM(u.email_verified_at IS NOT NULL) AS ativos,
       SUM(u.is_supervisor = 1 AND u.role <> 'admin') AS com_flag_inconsistente
FROM u172743873_portalrh.usuarios u
GROUP BY nivel
ORDER BY nivel;

-- ===== BLOCO 4 - o e-mail do supervisor configurado e um Supervisor Geral? ===========================
-- Troque o e-mail abaixo pelo valor de security.supervisor_email do config. Esperado: role admin, flag 1, ativo 1.
SELECT u.id, u.role, u.is_supervisor, (u.email_verified_at IS NOT NULL) AS ativo
FROM u172743873_portalrh.usuarios u
WHERE u.email = 'TROQUE_PELO_EMAIL_DO_SUPERVISOR@dominio';

-- ===== BLOCO 5 - quem alterou perfis/privilegios/senhas/status/exclusoes (auditoria ja existente) =====
-- Mostra o volume por acao nos ultimos 90 dias (somente contagens; o historico anterior a esta etapa so tem
-- as acoes que ja eram gravadas: admin_password_change, permissoes_update, blocked_*).
SELECT a.action, COUNT(*) AS ocorrencias, MIN(a.created_at) AS primeira, MAX(a.created_at) AS ultima
FROM u172743873_portalrh.auditoria_usuarios a
WHERE a.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
GROUP BY a.action
ORDER BY a.action;

-- ===== BLOCO 6 - RH que alteraram perfis de contas admin (padrao a revisar, quando houver registro) =====
-- A acao 'role_change' passa a existir com esta etapa; antes dela a alteracao de perfil nao era auditada.
SELECT COUNT(*) AS alteracoes_de_perfil_auditadas
FROM u172743873_portalrh.auditoria_usuarios a
WHERE a.action = 'role_change';
