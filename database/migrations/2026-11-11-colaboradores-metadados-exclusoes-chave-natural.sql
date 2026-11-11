-- Migration: 2026-11-11-colaboradores-metadados-exclusoes-chave-natural.sql
-- Objetivo:
--   Tornar PERMANENTE a identificação das exclusões analíticas de contratos
--   (colaboradores_metadados_exclusoes_analiticas, migration 2026-10-29) usando a CHAVE NATURAL do
--   vínculo — (codigo_empresa, codigo_unidade, numero_contrato) — em vez de `metadados_id`, uma chave
--   interna (AUTO_INCREMENT) que difere entre ambientes e muda numa reconstrução da base. Foi
--   exatamente isso que fez as 3 exclusões feitas em desenvolvimento não valerem em produção.
--
--   A chave natural é a mesma que o sincronizador usa no upsert
--   (ColaboradorMetadadosRepository::findByVinculo) e é UNIQUE em colaboradores_metadados
--   (uk_colaboradores_metadados_vinculo). Nunca CPF, nunca nome.
--
-- O que esta migration faz (ADITIVA; nenhum dado é apagado; colaboradores_metadados NÃO é tocada):
--   1. Adiciona codigo_empresa, codigo_unidade, numero_contrato (NULL) na tabela de exclusões, com
--      TIPO, CHARSET e COLLATION copiados das colunas de origem em colaboradores_metadados (evita
--      "Illegal mix of collations" entre MariaDB 11.8 em produção e MySQL 8.4 em desenvolvimento).
--   2. Preenche os 3 campos a partir do vínculo hoje referenciado por metadados_id. Preserva id da
--      exclusão, motivo, excluido_por_usuario_id, created_at e a própria metadados_id (referência
--      ao vínculo original, só para auditoria). Nenhuma linha é recriada.
--   3. ABORTA com erro explícito (nome da tabela inexistente na mensagem) se houver exclusão sem
--      vínculo correspondente, com chave vazia ou duplicada — nunca migra parcialmente em silêncio.
--   4. Torna os 3 campos NOT NULL e metadados_id NULLable (exclusões novas são identificadas só
--      pela chave natural; metadados_id deixa de ser obrigatória).
--   5. Cria o índice UNIQUE uk_cmea_vinculo (codigo_empresa, codigo_unidade, numero_contrato).
--
--   NÃO cria chave estrangeira. Em desenvolvimento a tabela tem fk_cmea_metadados/fk_cmea_usuario;
--   em produção a consulta ao information_schema não encontrou nenhuma FK — esta migration funciona
--   nos dois cenários e não altera nenhuma FK existente.
--
-- ATENÇÃO — DDL no MySQL/MariaDB faz COMMIT implícito: um ROLLBACK NÃO desfaz esta migration.
--   Por isso cada passo é idempotente (pode ser reexecutado) e há guardas antes de qualquer passo
--   que endureça o schema. Se a migration abortar, nada de dado foi perdido: corrija a causa
--   (consulta de diagnóstico abaixo) e rode o arquivo inteiro de novo, ou use o rollback.
--   NÃO aplicar em produção sem os "VALIDAÇÃO ANTES" abaixo e sem backup da tabela.
--
-- VALIDAÇÃO ANTES (somente leitura — rode e confira antes de aplicar):
--   -- (a) Estrutura atual:
--   --   SHOW CREATE TABLE colaboradores_metadados_exclusoes_analiticas;
--   -- (b) Cada exclusão aponta para um contrato existente? (esperado: 0 linhas)
--   --   SELECT e.id, e.metadados_id FROM colaboradores_metadados_exclusoes_analiticas e
--   --   LEFT JOIN colaboradores_metadados m ON m.id = e.metadados_id WHERE m.id IS NULL;
--   -- (c) As exclusões e seus contratos (esperado em produção: 3 linhas — 61, 556, 571):
--   --   SELECT e.id, e.metadados_id, m.identificador, m.codigo_empresa, m.codigo_unidade,
--   --          m.numero_contrato, m.nome, e.excluido_por_usuario_id, e.created_at
--   --   FROM colaboradores_metadados_exclusoes_analiticas e
--   --   JOIN colaboradores_metadados m ON m.id = e.metadados_id ORDER BY e.id;
--   -- (d) Chave natural é única em colaboradores_metadados (esperado: 0 linhas):
--   --   SELECT codigo_empresa, codigo_unidade, numero_contrato, COUNT(*) FROM colaboradores_metadados
--   --   GROUP BY 1,2,3 HAVING COUNT(*) > 1;
--
-- VALIDAÇÃO DEPOIS: ver os SELECTs no fim deste arquivo.
-- Rollback: 2026-11-11-colaboradores-metadados-exclusoes-chave-natural-rollback.sql
--
-- Idempotente e portável (MariaDB 11.8 em produção e MySQL 8.4 em desenvolvimento): usa
-- INFORMATION_SCHEMA + PREPARE/EXECUTE (mesmo padrão de 2026-09-24-colaboradores-metadados-sexo.sql),
-- nunca `ADD COLUMN IF NOT EXISTS`.

-- ---------------------------------------------------------------------------------------------
-- 0. Pré-condições: a tabela de exclusões e a chave natural única precisam existir.
-- ---------------------------------------------------------------------------------------------
SET @tabela_ok := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colaboradores_metadados_exclusoes_analiticas'
);
SET @sql := IF(@tabela_ok = 0,
  'SELECT * FROM ERRO__tabela_colaboradores_metadados_exclusoes_analiticas_nao_existe__aplique_antes_a_migration_2026_10_29',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @chave_natural_ok := (
  SELECT COUNT(*) FROM (
    SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colaboradores_metadados' AND NON_UNIQUE = 0
      AND INDEX_NAME = 'uk_colaboradores_metadados_vinculo'
      AND COLUMN_NAME IN ('codigo_empresa', 'codigo_unidade', 'numero_contrato')
    GROUP BY INDEX_NAME HAVING COUNT(*) = 3
  ) k
);
SET @sql := IF(@chave_natural_ok = 0,
  'SELECT * FROM ERRO__chave_unica_uk_colaboradores_metadados_vinculo_ausente_em_colaboradores_metadados',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------------------------
-- 1. Colunas da chave natural (tipo/charset/collation copiados da origem).
-- ---------------------------------------------------------------------------------------------
SET @def_emp := (
  SELECT CONCAT(COLUMN_TYPE, ' CHARACTER SET ', CHARACTER_SET_NAME, ' COLLATE ', COLLATION_NAME)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colaboradores_metadados' AND COLUMN_NAME = 'codigo_empresa'
);
SET @def_uni := (
  SELECT CONCAT(COLUMN_TYPE, ' CHARACTER SET ', CHARACTER_SET_NAME, ' COLLATE ', COLLATION_NAME)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colaboradores_metadados' AND COLUMN_NAME = 'codigo_unidade'
);
SET @def_ctr := (
  SELECT CONCAT(COLUMN_TYPE, ' CHARACTER SET ', CHARACTER_SET_NAME, ' COLLATE ', COLLATION_NAME)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colaboradores_metadados' AND COLUMN_NAME = 'numero_contrato'
);

SET @cols_existem := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colaboradores_metadados_exclusoes_analiticas'
    AND COLUMN_NAME IN ('codigo_empresa', 'codigo_unidade', 'numero_contrato')
);
SET @sql := IF(@cols_existem = 0,
  CONCAT(
    'ALTER TABLE colaboradores_metadados_exclusoes_analiticas ',
    'ADD COLUMN codigo_empresa ', @def_emp, ' NULL AFTER metadados_id, ',
    'ADD COLUMN codigo_unidade ', @def_uni, ' NULL AFTER codigo_empresa, ',
    'ADD COLUMN numero_contrato ', @def_ctr, ' NULL AFTER codigo_unidade'
  ),
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------------------------
-- 2. Preenchimento a partir do vínculo referenciado por metadados_id (idempotente: só linhas ainda
--    sem chave natural). `e.created_at = e.created_at` impede que um eventual ON UPDATE
--    CURRENT_TIMESTAMP altere a data original da exclusão. Não recria nenhuma linha.
-- ---------------------------------------------------------------------------------------------
UPDATE colaboradores_metadados_exclusoes_analiticas e
JOIN colaboradores_metadados m ON m.id = e.metadados_id
SET e.codigo_empresa = m.codigo_empresa,
    e.codigo_unidade = m.codigo_unidade,
    e.numero_contrato = m.numero_contrato,
    e.created_at = e.created_at
WHERE e.codigo_empresa IS NULL OR e.codigo_unidade IS NULL OR e.numero_contrato IS NULL;

-- ---------------------------------------------------------------------------------------------
-- 3. GUARDAS — abortam com erro explícito antes de endurecer o schema.
--    (a) exclusão sem vínculo correspondente (metadados_id órfão) ou com chave vazia/incompleta;
--    (b) duas exclusões com a mesma chave natural.
--    Diagnóstico de (a): SELECT * FROM colaboradores_metadados_exclusoes_analiticas
--                         WHERE codigo_empresa IS NULL OR codigo_unidade IS NULL OR numero_contrato IS NULL
--                            OR TRIM(codigo_empresa) = '' OR TRIM(codigo_unidade) = '' OR TRIM(numero_contrato) = '';
--    Diagnóstico de (b): SELECT codigo_empresa, codigo_unidade, numero_contrato, COUNT(*) FROM
--                         colaboradores_metadados_exclusoes_analiticas GROUP BY 1,2,3 HAVING COUNT(*) > 1;
-- ---------------------------------------------------------------------------------------------
SET @incompletas := (
  SELECT COUNT(*) FROM colaboradores_metadados_exclusoes_analiticas
  WHERE codigo_empresa IS NULL OR codigo_unidade IS NULL OR numero_contrato IS NULL
     OR TRIM(codigo_empresa) = '' OR TRIM(codigo_unidade) = '' OR TRIM(numero_contrato) = ''
);
SET @sql := IF(@incompletas > 0,
  'SELECT * FROM ERRO__existem_exclusoes_sem_vinculo_correspondente_ou_com_chave_incompleta__nada_foi_endurecido__veja_diagnostico_no_cabecalho',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @duplicadas := (
  SELECT COUNT(*) FROM (
    SELECT 1 FROM colaboradores_metadados_exclusoes_analiticas
    GROUP BY codigo_empresa, codigo_unidade, numero_contrato HAVING COUNT(*) > 1
  ) d
);
SET @sql := IF(@duplicadas > 0,
  'SELECT * FROM ERRO__existem_exclusoes_duplicadas_para_a_mesma_chave_natural__nada_foi_endurecido__veja_diagnostico_no_cabecalho',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------------------------
-- 4. Endurece: chave natural NOT NULL; metadados_id passa a NULLable (referência de auditoria).
--    Idempotente. Se restar qualquer NULL (guardas acima ignoradas), este passo falha — terceira
--    barreira.
-- ---------------------------------------------------------------------------------------------
SET @sql := CONCAT(
  'ALTER TABLE colaboradores_metadados_exclusoes_analiticas ',
  'MODIFY codigo_empresa ', @def_emp, ' NOT NULL, ',
  'MODIFY codigo_unidade ', @def_uni, ' NOT NULL, ',
  'MODIFY numero_contrato ', @def_ctr, ' NOT NULL, ',
  'MODIFY metadados_id INT NULL'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------------------------
-- 5. UNIQUE da chave natural (idempotente).
-- ---------------------------------------------------------------------------------------------
SET @idx_existe := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colaboradores_metadados_exclusoes_analiticas'
    AND INDEX_NAME = 'uk_cmea_vinculo'
);
SET @sql := IF(@idx_existe = 0,
  'ALTER TABLE colaboradores_metadados_exclusoes_analiticas ADD UNIQUE KEY uk_cmea_vinculo (codigo_empresa, codigo_unidade, numero_contrato)',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------------------------
-- VALIDAÇÃO DEPOIS (somente leitura)
-- ---------------------------------------------------------------------------------------------
-- (1) Mesmas exclusões de antes, agora com chave natural (id/motivo/usuário/data originais intactos):
SELECT e.id, e.metadados_id, e.codigo_empresa, e.codigo_unidade, e.numero_contrato,
       e.excluido_por_usuario_id, e.created_at, LEFT(e.motivo, 60) AS motivo
FROM colaboradores_metadados_exclusoes_analiticas e
ORDER BY e.id;

-- (2) Cada exclusão encontra seu contrato pela chave natural (esperado: nenhuma linha com m.id nulo):
SELECT e.id, m.id AS contrato_id_atual, m.identificador, m.nome
FROM colaboradores_metadados_exclusoes_analiticas e
LEFT JOIN colaboradores_metadados m
  ON m.codigo_empresa = e.codigo_empresa AND m.codigo_unidade = e.codigo_unidade AND m.numero_contrato = e.numero_contrato
ORDER BY e.id;

-- (3) Estrutura final:
SHOW CREATE TABLE colaboradores_metadados_exclusoes_analiticas;
