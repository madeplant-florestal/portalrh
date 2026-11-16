-- =====================================================================================================
-- DIAGNOSTICO - Headcount historico de OUTUBRO/2026 (240) - SOMENTE LEITURA (apenas SELECT; sem PREPARE,
-- sem variaveis, sem DDL/DML). Banco: u172743873_portalrh (nome explicito em todas as consultas). MariaDB 11.8.
-- Execute UM BLOCO POR VEZ e cole os resultados. Data de referencia = 2026-10-31 (troque em todas as linhas
-- para outro mes). As categorias dos blocos 2, 4 e 7 SE SOBREPOEM: nao somar como se fossem particao.
-- Em todos os blocos, contratos excluidos analiticamente (chave natural) ja ficam FORA, como no sistema.
-- =====================================================================================================

-- ===== BLOCO 1 - reproducao do calculo ATUAL do card (contratos BRUTOS vigentes em 31/10) ============
-- E o que o dashboard fazia: admissao <= data e (demissao nula ou >= data), SEM olhar ausente_na_origem
-- nem a transferencia continua. Deve devolver ~240, e as pessoas distintas por duas identidades.
SELECT COUNT(*) AS contratos_vigentes_bruto,
       COUNT(DISTINCT m.codigo_pessoa) AS pessoas_por_codigo_pessoa,
       COUNT(DISTINCT NULLIF(m.cpf, '')) AS pessoas_por_cpf
FROM u172743873_portalrh.colaboradores_metadados m
WHERE m.admissao IS NOT NULL AND m.admissao <= '2026-10-31'
  AND (m.demissao IS NULL OR m.demissao >= '2026-10-31')
  AND NOT EXISTS (SELECT 1 FROM u172743873_portalrh.colaboradores_metadados_exclusoes_analiticas e
                  WHERE e.codigo_empresa = m.codigo_empresa AND e.codigo_unidade = m.codigo_unidade AND e.numero_contrato = m.numero_contrato);

-- ===== BLOCO 2 - categorias SOBREPOSTAS do total de linhas (nao somar) =================================
SELECT COUNT(*) AS linhas_nao_excluidas,
       SUM(m.admissao IS NOT NULL AND m.admissao <= '2026-10-31' AND (m.demissao IS NULL OR m.demissao >= '2026-10-31')) AS vigentes_31_10,
       SUM(m.admissao IS NOT NULL AND m.admissao > '2026-10-31') AS admitidos_apos_31_10,
       SUM(m.demissao IS NOT NULL AND m.demissao < '2026-10-31') AS desligados_antes_31_10,
       SUM(m.demissao = '2026-10-31') AS desligados_em_31_10,
       SUM(m.admissao IS NULL) AS sem_admissao,
       SUM(m.ausente_na_origem = 1) AS ausentes_na_origem_hoje,
       SUM(m.codigo_setor IS NULL OR m.codigo_setor = '') AS sem_setor
FROM u172743873_portalrh.colaboradores_metadados m
WHERE NOT EXISTS (SELECT 1 FROM u172743873_portalrh.colaboradores_metadados_exclusoes_analiticas e
                  WHERE e.codigo_empresa = m.codigo_empresa AND e.codigo_unidade = m.codigo_unidade AND e.numero_contrato = m.numero_contrato);

-- ===== BLOCO 3 - vigentes em 31/10 separados por ausente_na_origem (hoje) =============================
-- Hipotese principal: o contrato de ORIGEM de uma transferencia continua fica ausente_na_origem=1, nunca e
-- demitido e continua "vigente" para sempre; somado ao contrato de destino, conta a mesma pessoa duas vezes.
SELECT m.ausente_na_origem, COUNT(*) AS contratos_vigentes_31_10
FROM u172743873_portalrh.colaboradores_metadados m
WHERE m.admissao IS NOT NULL AND m.admissao <= '2026-10-31'
  AND (m.demissao IS NULL OR m.demissao >= '2026-10-31')
  AND NOT EXISTS (SELECT 1 FROM u172743873_portalrh.colaboradores_metadados_exclusoes_analiticas e
                  WHERE e.codigo_empresa = m.codigo_empresa AND e.codigo_unidade = m.codigo_unidade AND e.numero_contrato = m.numero_contrato)
GROUP BY m.ausente_na_origem;

-- ===== BLOCO 4 - vigentes em 31/10 e ausentes hoje: existe contrato "sucessor" do mesmo CPF? ============
-- com_sucessor_mesmo_cpf = origem de transferencia (conta duas vezes no calculo antigo); sem_sucessor =
-- ausencia sem par (estado atual apenas; o sistema nao sabe quando sumiu).
SELECT SUM(suc.n > 0) AS com_sucessor_mesmo_cpf, SUM(suc.n = 0) AS sem_sucessor_ou_sem_cpf, COUNT(*) AS ausentes_vigentes_31_10
FROM (
  SELECT m.id,
         (SELECT COUNT(*) FROM u172743873_portalrh.colaboradores_metadados d
          WHERE m.cpf IS NOT NULL AND m.cpf <> '' AND d.cpf = m.cpf AND d.id <> m.id AND d.ausente_na_origem = 0
            AND d.admissao IS NOT NULL AND d.admissao <= '2026-10-31' AND (d.demissao IS NULL OR d.demissao >= '2026-10-31')) AS n
  FROM u172743873_portalrh.colaboradores_metadados m
  WHERE m.ausente_na_origem = 1 AND m.admissao IS NOT NULL AND m.admissao <= '2026-10-31'
    AND (m.demissao IS NULL OR m.demissao >= '2026-10-31')
    AND NOT EXISTS (SELECT 1 FROM u172743873_portalrh.colaboradores_metadados_exclusoes_analiticas e
                    WHERE e.codigo_empresa = m.codigo_empresa AND e.codigo_unidade = m.codigo_unidade AND e.numero_contrato = m.numero_contrato)
) suc;

-- ===== BLOCO 5 - lista dos ausentes vigentes em 31/10 (inspecao; origem da duplicidade) ================
SELECT m.codigo_empresa, m.codigo_unidade, m.numero_contrato, m.codigo_pessoa, m.nome, m.admissao, m.demissao,
       m.ativo, m.ausente_desde, m.data_ultima_transferencia
FROM u172743873_portalrh.colaboradores_metadados m
WHERE m.ausente_na_origem = 1 AND m.admissao IS NOT NULL AND m.admissao <= '2026-10-31'
  AND (m.demissao IS NULL OR m.demissao >= '2026-10-31')
  AND NOT EXISTS (SELECT 1 FROM u172743873_portalrh.colaboradores_metadados_exclusoes_analiticas e
                  WHERE e.codigo_empresa = m.codigo_empresa AND e.codigo_unidade = m.codigo_unidade AND e.numero_contrato = m.numero_contrato)
ORDER BY m.nome
LIMIT 100;

-- ===== BLOCO 6 - identidade da pessoa: codigo_pessoa e unico globalmente? ===============================
-- Se houver casos, o mesmo codigo_pessoa serve a CPFs diferentes (ou o contrario) e COUNT(DISTINCT
-- codigo_pessoa) NAO e seguro como "pessoas".
SELECT 'codigo_pessoa com mais de um CPF' AS verificacao, COUNT(*) AS casos FROM (
  SELECT codigo_pessoa FROM u172743873_portalrh.colaboradores_metadados
  WHERE cpf IS NOT NULL AND cpf <> '' GROUP BY codigo_pessoa HAVING COUNT(DISTINCT cpf) > 1) a
UNION ALL
SELECT 'CPF com mais de um codigo_pessoa', COUNT(*) FROM (
  SELECT cpf FROM u172743873_portalrh.colaboradores_metadados
  WHERE cpf IS NOT NULL AND cpf <> '' GROUP BY cpf HAVING COUNT(DISTINCT codigo_pessoa) > 1) b
UNION ALL
SELECT 'codigo_pessoa repetido entre empresas diferentes', COUNT(*) FROM (
  SELECT codigo_pessoa FROM u172743873_portalrh.colaboradores_metadados GROUP BY codigo_pessoa HAVING COUNT(DISTINCT codigo_empresa) > 1) c;

-- ===== BLOCO 7 - duplicidades e inconsistencias (categorias sobrepostas) ================================
SELECT
  (SELECT COUNT(*) FROM (SELECT codigo_empresa, codigo_unidade, numero_contrato FROM u172743873_portalrh.colaboradores_metadados
                         GROUP BY codigo_empresa, codigo_unidade, numero_contrato HAVING COUNT(*) > 1) x) AS chaves_naturais_duplicadas,
  (SELECT COUNT(*) FROM (SELECT identificador FROM u172743873_portalrh.colaboradores_metadados
                         GROUP BY identificador HAVING COUNT(*) > 1) y) AS identificadores_duplicados,
  (SELECT COUNT(*) FROM u172743873_portalrh.colaboradores_metadados WHERE demissao IS NOT NULL AND admissao IS NOT NULL AND demissao < admissao) AS demissao_antes_da_admissao,
  (SELECT COUNT(*) FROM u172743873_portalrh.colaboradores_metadados WHERE ativo = 1 AND demissao IS NOT NULL AND demissao < CURDATE()) AS ativo1_com_demissao_passada,
  (SELECT COUNT(*) FROM u172743873_portalrh.colaboradores_metadados WHERE (ativo = 0 OR ativo IS NULL) AND demissao IS NULL) AS ativo0_sem_demissao,
  (SELECT COUNT(*) FROM u172743873_portalrh.colaboradores_metadados WHERE admissao IS NULL) AS sem_admissao;

-- ===== BLOCO 8 - admissoes e desligamentos de outubro (brutos, sem classificar transferencia) ===========
SELECT SUM(m.admissao BETWEEN '2026-10-01' AND '2026-10-31') AS admissoes_outubro_brutas,
       SUM(m.demissao BETWEEN '2026-10-01' AND '2026-10-31') AS desligamentos_outubro_brutos
FROM u172743873_portalrh.colaboradores_metadados m
WHERE NOT EXISTS (SELECT 1 FROM u172743873_portalrh.colaboradores_metadados_exclusoes_analiticas e
                  WHERE e.codigo_empresa = m.codigo_empresa AND e.codigo_unidade = m.codigo_unidade AND e.numero_contrato = m.numero_contrato);

-- ===== BLOCO 9 - comparacao com HOJE (a fotografia que o card ja calculava certo: ausentes fora) =========
SELECT COUNT(*) AS vigentes_hoje_sem_ausentes
FROM u172743873_portalrh.colaboradores_metadados m
WHERE m.ausente_na_origem = 0 AND m.admissao IS NOT NULL AND m.admissao <= CURDATE()
  AND (m.demissao IS NULL OR m.demissao >= CURDATE())
  AND NOT EXISTS (SELECT 1 FROM u172743873_portalrh.colaboradores_metadados_exclusoes_analiticas e
                  WHERE e.codigo_empresa = m.codigo_empresa AND e.codigo_unidade = m.codigo_unidade AND e.numero_contrato = m.numero_contrato);

-- ===== BLOCO 10 - Headcount de 31/10 pela regra CORRIGIDA (aproximacao por CPF) =========================
-- = vigentes em 31/10 menos os ausentes que tem sucessor vigente de mesmo CPF. Deve se aproximar do valor que
-- o dashboard passa a mostrar (a classificacao oficial do sistema pode ser mais estrita que o CPF).
SELECT COUNT(*) AS headcount_31_10_corrigido_aprox
FROM u172743873_portalrh.colaboradores_metadados m
WHERE m.admissao IS NOT NULL AND m.admissao <= '2026-10-31'
  AND (m.demissao IS NULL OR m.demissao >= '2026-10-31')
  AND NOT EXISTS (SELECT 1 FROM u172743873_portalrh.colaboradores_metadados_exclusoes_analiticas e
                  WHERE e.codigo_empresa = m.codigo_empresa AND e.codigo_unidade = m.codigo_unidade AND e.numero_contrato = m.numero_contrato)
  AND NOT (m.ausente_na_origem = 1 AND m.cpf IS NOT NULL AND m.cpf <> ''
           AND EXISTS (SELECT 1 FROM u172743873_portalrh.colaboradores_metadados d
                       WHERE d.cpf = m.cpf AND d.id <> m.id AND d.ausente_na_origem = 0
                         AND d.admissao IS NOT NULL AND d.admissao <= '2026-10-31' AND (d.demissao IS NULL OR d.demissao >= '2026-10-31')));
