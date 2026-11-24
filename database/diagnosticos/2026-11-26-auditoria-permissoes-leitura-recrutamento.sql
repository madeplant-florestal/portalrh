-- Auditoria (SOMENTE LEITURA) — Etapa 3.2C.1 — executar no phpMyAdmin com o banco u172743873_portalrh ANTES de publicar o código.
-- Motivo: vagas.visualizar e candidaturas.visualizar passam a ser CONCEDÍVEIS ao perfil Gestor. Uma linha antiga em usuario_permissoes, hoje
-- INEFICAZ (a política bloqueava), tornaria-se EFETIVA automaticamente na publicação. Nenhuma consulta altera dados.

-- 1) BLOQUEIO DE IMPLANTAÇÃO: Gestores que JÁ têm uma das duas permissões gravadas (devem retornar 0 linhas; se houver, ver o plano no
--    docs/claude/vagas-setor-responsavel.md §10 — regularizar com o RH ANTES de publicar).
SELECT u.id AS usuario_id, u.nome, u.role, p.codigo AS permissao_gravada,
       (SELECT COUNT(*) FROM `u172743873_portalrh`.`usuario_setores_gerenciados` g WHERE g.usuario_id = u.id) AS setores_gerenciados
FROM `u172743873_portalrh`.`usuario_permissoes` up
INNER JOIN `u172743873_portalrh`.`permissoes` p ON p.id = up.permissao_id
INNER JOIN `u172743873_portalrh`.`usuarios` u ON u.id = up.usuario_id
WHERE u.role = 'gestor' AND p.codigo IN ('vagas.visualizar', 'candidaturas.visualizar')
ORDER BY u.id, p.codigo;

-- 2) Contexto: quantos usuários existem por perfil (Gestores existentes são os únicos afetados pela mudança de política).
SELECT role, COUNT(*) AS usuarios FROM `u172743873_portalrh`.`usuarios` GROUP BY role ORDER BY role;

-- 3) Informativo: Gestores com QUALQUER permissão de Recrutamento gravada que continua INEFICAZ após a publicação (Pipeline, edição de
--    candidaturas, escrita de vagas, indicações...). Não ganham acesso — listar para ciência e eventual limpeza futura.
SELECT u.id AS usuario_id, u.nome, p.codigo AS permissao_gravada_ineficaz
FROM `u172743873_portalrh`.`usuario_permissoes` up
INNER JOIN `u172743873_portalrh`.`permissoes` p ON p.id = up.permissao_id
INNER JOIN `u172743873_portalrh`.`usuarios` u ON u.id = up.usuario_id
WHERE u.role = 'gestor'
  AND (p.codigo LIKE 'pipeline.%' OR p.codigo LIKE 'candidaturas.%' OR p.codigo LIKE 'vagas.%' OR p.codigo LIKE 'indicacoes.%'
       OR p.codigo IN ('comunicacoes.visualizar', 'pesquisa_experiencia.visualizar'))
  AND p.codigo NOT IN ('vagas.visualizar', 'candidaturas.visualizar')
ORDER BY u.id, p.codigo;

-- 4) Informativo: Viewers com as duas permissões gravadas. NÃO ganham nada (a política do Viewer não muda; Candidaturas segue 403 e
--    Vagas segue como sempre). Listados só para ciência.
SELECT u.id AS usuario_id, u.nome, p.codigo AS permissao_gravada
FROM `u172743873_portalrh`.`usuario_permissoes` up
INNER JOIN `u172743873_portalrh`.`permissoes` p ON p.id = up.permissao_id
INNER JOIN `u172743873_portalrh`.`usuarios` u ON u.id = up.usuario_id
WHERE u.role = 'viewer' AND p.codigo IN ('vagas.visualizar', 'candidaturas.visualizar')
ORDER BY u.id, p.codigo;

-- 5) Pré-requisito da leitura setorial: toda vaga precisa de setor (o Gestor não vê vaga sem setor). Deve retornar 0.
SELECT COUNT(*) AS vagas_sem_setor FROM `u172743873_portalrh`.`vagas` WHERE setor_id IS NULL;

-- 6) Homologação: Gestores elegíveis a teste (perfil gestor, ativo, com ao menos 1 setor gerenciado) — sem expor e-mail/CPF.
SELECT u.id AS usuario_id, u.nome, COUNT(g.setor_id) AS setores_gerenciados
FROM `u172743873_portalrh`.`usuarios` u
LEFT JOIN `u172743873_portalrh`.`usuario_setores_gerenciados` g ON g.usuario_id = u.id
WHERE u.role = 'gestor'
GROUP BY u.id, u.nome ORDER BY u.id;
