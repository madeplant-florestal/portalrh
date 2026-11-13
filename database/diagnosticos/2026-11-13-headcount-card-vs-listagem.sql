-- SOMENTE LEITURA. Diagnóstico: Headcount do card (175) vs listagem "Ativos" (174).
-- O card (PeopleAnalyticsService) conta contratos pela DATA: admissao <= hoje e (demissao IS NULL OU demissao >= hoje),
-- ignorando a flag `ativo`. A listagem (ColaboradorMetadadosConsultaRepository) usa `ativo = 1`.
-- Ambos excluem ausente_na_origem = 1 e a exclusão analítica. Esta consulta lista os contratos que estão
-- em UM dos critérios e não no outro, pela chave natural (codigo_empresa+codigo_unidade+numero_contrato).

SELECT
  m.codigo_empresa, m.codigo_unidade, m.numero_contrato, m.codigo_pessoa, m.nome,
  m.admissao, m.demissao, m.ativo, m.ausente_na_origem,
  CASE WHEN m.admissao IS NOT NULL AND m.admissao <= CURDATE() AND (m.demissao IS NULL OR m.demissao >= CURDATE()) THEN 1 ELSE 0 END AS conta_no_card,
  CASE WHEN m.ativo = 1 THEN 1 ELSE 0 END AS conta_na_listagem
FROM colaboradores_metadados m
WHERE m.ausente_na_origem = 0
  AND NOT EXISTS (
    SELECT 1 FROM colaboradores_metadados_exclusoes_analiticas e
    WHERE e.codigo_empresa = m.codigo_empresa AND e.codigo_unidade = m.codigo_unidade AND e.numero_contrato = m.numero_contrato
  )
  AND (
    (m.admissao IS NOT NULL AND m.admissao <= CURDATE() AND (m.demissao IS NULL OR m.demissao >= CURDATE()))
    <> (m.ativo = 1)
  );

-- Contratos x pessoas distintas na população do card (um colaborador com 2 contratos vigentes conta 2).
SELECT COUNT(*) AS contratos, COUNT(DISTINCT codigo_pessoa) AS pessoas
FROM colaboradores_metadados m
WHERE m.ausente_na_origem = 0 AND m.admissao IS NOT NULL AND m.admissao <= CURDATE()
  AND (m.demissao IS NULL OR m.demissao >= CURDATE())
  AND NOT EXISTS (
    SELECT 1 FROM colaboradores_metadados_exclusoes_analiticas e
    WHERE e.codigo_empresa = m.codigo_empresa AND e.codigo_unidade = m.codigo_unidade AND e.numero_contrato = m.numero_contrato
  );
