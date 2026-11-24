-- Diagnostico (SOMENTE LEITURA): 2026-11-25-pipeline-setor-divergencia.sql
-- Etapa 3.2B.3 — o Kanban/Candidaturas do RH filtra setor pela SOLICITACAO (solicitacoes_vaga.setor_id); o escopo do Gestor usa vagas.setor_id.
-- Lista as vagas onde os dois divergem (ou onde so um existe). NAO altera dados; NAO traz dados pessoais (so contagem de candidaturas).

-- 1) Divergencia por categoria
SELECT CASE
         WHEN v.setor_id IS NULL AND s.setor_id IS NULL THEN 'sem setor em nenhum'
         WHEN v.setor_id IS NULL AND s.setor_id IS NOT NULL THEN 'so a solicitacao tem setor'
         WHEN v.setor_id IS NOT NULL AND v.solicitacao_vaga_id IS NULL THEN 'vaga direta (sem solicitacao) com setor'
         WHEN v.setor_id = s.setor_id THEN 'iguais'
         ELSE 'DIVERGENTES'
       END AS situacao,
       COUNT(*) AS vagas
FROM vagas v LEFT JOIN solicitacoes_vaga s ON s.id = v.solicitacao_vaga_id
GROUP BY situacao ORDER BY situacao;

-- 2) Detalhe (vagas com candidaturas afetadas pelo filtro de setor atual do Kanban)
SELECT v.id AS vaga_id, v.titulo, v.setor_id AS setor_vaga, s.setor_id AS setor_solicitacao,
       (SELECT COUNT(*) FROM candidaturas c WHERE c.vaga_id = v.id) AS candidaturas
FROM vagas v LEFT JOIN solicitacoes_vaga s ON s.id = v.solicitacao_vaga_id
WHERE NOT (v.setor_id <=> s.setor_id)
ORDER BY candidaturas DESC, v.id;
