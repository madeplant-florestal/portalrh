-- Diagnostico (SOMENTE LEITURA): 2026-11-24-vagas-setor-regularizacao.sql
-- Etapa 3.2A — classifica as vagas quanto ao setor responsavel. Requer a migration 2026-11-24-vagas-setor-responsavel.sql ja executada.
-- NAO altera dados. NAO inclui CPF, curriculo ou qualquer dado pessoal (so a CONTAGEM de candidaturas).
-- Classes: A = setor estruturado | B = sem setor, mas a SOLICITACAO vinculada tem setor estruturado | C = sem setor/solicitacao e o texto de
-- `area` coincide com o nome de um setor oficial (SUGESTAO NAO COMPROVADA) | D = sem informacao suficiente.
-- A aplicacao da classe B (unica automatica) e feita por: php scripts/regularizar_setor_vagas.php --aplicar (simulacao por padrao).

-- 1) Resumo por classe
SELECT CASE
         WHEN v.setor_id IS NOT NULL THEN 'A'
         WHEN s.setor_id IS NOT NULL THEN 'B'
         WHEN EXISTS (SELECT 1 FROM setores o WHERE o.codigo_setor IS NOT NULL AND o.ativo = 1
                      AND (UPPER(TRIM(o.nome)) = UPPER(TRIM(v.area)) OR UPPER(TRIM(o.descricao_oficial)) = UPPER(TRIM(v.area)))) THEN 'C'
         ELSE 'D'
       END AS classe,
       COUNT(*) AS vagas
FROM vagas v
LEFT JOIN solicitacoes_vaga s ON s.id = v.solicitacao_vaga_id
GROUP BY classe ORDER BY classe;

-- 2) Relatorio por vaga (sem dados pessoais)
SELECT v.id AS vaga_id, v.titulo, v.area AS area_textual, v.solicitacao_vaga_id,
       v.setor_id AS setor_atual_id, sa.nome AS setor_atual,
       s.setor_id AS setor_solicitacao_id, ss.nome AS setor_solicitacao,
       (SELECT COUNT(*) FROM candidaturas c WHERE c.vaga_id = v.id) AS candidaturas,
       CASE WHEN v.setor_id IS NOT NULL AND s.setor_id IS NOT NULL AND v.setor_id <> s.setor_id THEN 'DIVERGE da solicitacao' ELSE '' END AS observacao
FROM vagas v
LEFT JOIN solicitacoes_vaga s ON s.id = v.solicitacao_vaga_id
LEFT JOIN setores sa ON sa.id = v.setor_id
LEFT JOIN setores ss ON ss.id = s.setor_id
ORDER BY (v.setor_id IS NULL) DESC, v.id;

-- 3) Vagas com setor INATIVO ou fora do cadastro oficial (merecem revisao; o vinculo historico continua valido)
SELECT v.id AS vaga_id, v.titulo, st.nome AS setor, st.ativo, st.codigo_setor
FROM vagas v INNER JOIN setores st ON st.id = v.setor_id
WHERE st.ativo = 0 OR st.codigo_setor IS NULL OR st.codigo_setor = '';

-- 4) Condicao para o endurecimento futuro (NOT NULL): precisa retornar 0
SELECT COUNT(*) AS vagas_sem_setor FROM vagas WHERE setor_id IS NULL;
