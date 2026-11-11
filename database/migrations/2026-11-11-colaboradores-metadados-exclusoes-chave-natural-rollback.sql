-- Rollback da migration 2026-11-11-colaboradores-metadados-exclusoes-chave-natural.sql.
--
-- Volta a tabela de exclusões ao desenho de 2026-10-29 (identificação só por metadados_id NOT NULL).
-- NÃO apaga nenhuma exclusão: antes de remover as colunas da chave natural, RESTAURA metadados_id
-- de TODAS as linhas a partir do contrato que hoje tem aquela chave natural (assim exclusões criadas
-- depois da migration, que têm metadados_id NULL, e exclusões antigas cujo metadados_id ficou
-- defasado após uma reconstrução da base, voltam a apontar para o contrato certo).
--
-- ABORTA com erro explícito, sem remover nada, se alguma exclusão não tiver contrato correspondente
-- (não há como representá-la só por metadados_id sem perdê-la). Nesse caso: ou o contrato precisa
-- existir em colaboradores_metadados, ou a linha deve ser removida conscientemente antes
-- (SELECT * FROM colaboradores_metadados_exclusoes_analiticas e LEFT JOIN colaboradores_metadados m
--  ON m.codigo_empresa = e.codigo_empresa AND m.codigo_unidade = e.codigo_unidade
--  AND m.numero_contrato = e.numero_contrato WHERE m.id IS NULL;).
--
-- DDL faz COMMIT implícito (ROLLBACK não desfaz). Idempotente: pode ser reexecutado. Se as colunas
-- já não existem, os passos viram no-op.
-- Use APENAS se a migration estiver aplicada e o código novo já tiver sido revertido (o código novo
-- depende das colunas da chave natural).

SET @cols_existem := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colaboradores_metadados_exclusoes_analiticas'
    AND COLUMN_NAME IN ('codigo_empresa', 'codigo_unidade', 'numero_contrato')
);

-- 1. Restaura metadados_id a partir da chave natural (só se as colunas ainda existem).
SET @sql := IF(@cols_existem = 3,
  'UPDATE colaboradores_metadados_exclusoes_analiticas e
   JOIN colaboradores_metadados m
     ON m.codigo_empresa = e.codigo_empresa AND m.codigo_unidade = e.codigo_unidade AND m.numero_contrato = e.numero_contrato
   SET e.metadados_id = m.id, e.created_at = e.created_at',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Guarda: nenhuma exclusão pode ficar sem metadados_id.
SET @sem_id := (SELECT COUNT(*) FROM colaboradores_metadados_exclusoes_analiticas WHERE metadados_id IS NULL);
SET @sql := IF(@sem_id > 0,
  'SELECT * FROM ERRO__existem_exclusoes_sem_contrato_correspondente__rollback_abortado_sem_remover_nada__veja_orientacao_no_cabecalho',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. metadados_id volta a NOT NULL (falha se restar NULL — segunda barreira).
ALTER TABLE colaboradores_metadados_exclusoes_analiticas MODIFY metadados_id INT NOT NULL;

-- 4. Remove o índice e as colunas da chave natural (idempotente).
SET @idx_existe := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colaboradores_metadados_exclusoes_analiticas'
    AND INDEX_NAME = 'uk_cmea_vinculo'
);
SET @sql := IF(@idx_existe > 0,
  'ALTER TABLE colaboradores_metadados_exclusoes_analiticas DROP INDEX uk_cmea_vinculo',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(@cols_existem > 0,
  'ALTER TABLE colaboradores_metadados_exclusoes_analiticas DROP COLUMN codigo_empresa, DROP COLUMN codigo_unidade, DROP COLUMN numero_contrato',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
