-- Diagnostico (SOMENTE LEITURA) para o phpMyAdmin — banco u172743873_portalrh. Versao com nome de banco explicito de
-- 2026-11-25-pipeline-setor-divergencia.sql. Etapa 3.2C.1: a REFERENCIA OFICIAL de setor da vaga e vagas.setor_id (autorizacao do Gestor);
-- o filtro/exibicao do Kanban do RH ainda usa solicitacoes_vaga.setor_id. NAO usar COALESCE como regra de autorizacao.
-- Nao traz dados pessoais (so contagens).

-- 1) Divergencia por categoria
SELECT CASE
         WHEN v.setor_id IS NULL AND s.setor_id IS NULL THEN 'sem setor em nenhum'
         WHEN v.setor_id IS NULL AND s.setor_id IS NOT NULL THEN 'so a solicitacao tem setor'
         WHEN v.setor_id IS NOT NULL AND v.solicitacao_vaga_id IS NULL THEN 'vaga direta (sem solicitacao) com setor'
         WHEN v.setor_id = s.setor_id THEN 'iguais'
         ELSE 'DIVERGENTES'
       END AS situacao,
       COUNT(*) AS vagas
FROM `u172743873_portalrh`.`vagas` v
LEFT JOIN `u172743873_portalrh`.`solicitacoes_vaga` s ON s.id = v.solicitacao_vaga_id
GROUP BY situacao ORDER BY situacao;

-- 2) Detalhe das vagas em que os dois setores nao coincidem (e quantas candidaturas sao afetadas pelo filtro atual do Kanban)
SELECT v.id AS vaga_id, v.titulo, v.setor_id AS setor_vaga, s.setor_id AS setor_solicitacao,
       (SELECT COUNT(*) FROM `u172743873_portalrh`.`candidaturas` c WHERE c.vaga_id = v.id) AS candidaturas
FROM `u172743873_portalrh`.`vagas` v
LEFT JOIN `u172743873_portalrh`.`solicitacoes_vaga` s ON s.id = v.solicitacao_vaga_id
WHERE NOT (v.setor_id <=> s.setor_id)
ORDER BY candidaturas DESC, v.id;
