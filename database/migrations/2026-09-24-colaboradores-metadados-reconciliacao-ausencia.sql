-- Migration: 2026-09-24-colaboradores-metadados-reconciliacao-ausencia.sql
-- Objetivo:
--   Fecha a lacuna de reconciliação encontrada no diagnóstico "66 registros stale" (empresa/
--   unidade 0005/0005, criados em 01/09/2026, nunca mais tocados pelo sync desde 08/09/2026):
--   MetadadosSyncService::applyRows() é upsert puro — nunca detecta quando uma chave
--   (`identificador`) que estava vigente no espelho deixou de vir num sync completo da origem
--   (RHMADEPLANT). O caso real investigado não era transferência nem desligamento: o mesmo
--   vínculo (mesmo CPF, mesma admissão, muitas vezes o mesmo número de contrato) foi recodificado
--   na origem para outra empresa/unidade — a chave antiga simplesmente some do SELECT, e o
--   registro local correspondente fica órfão, para sempre `ativo = 1`/`demissao = NULL`, inflando
--   headcount e distribuições por empresa/unidade/setor.
--
--   `ausente_na_origem` / `ausente_desde` dão ao espelho um jeito de SINALIZAR essa ausência sem
--   jamais apagar, sobrescrever ou inventar dado: nenhum DELETE, nenhuma `demissao` fabricada,
--   nenhuma mudança de `ativo` (que continua significando o que sempre significou na origem).
--   `ativo` e `ausente_na_origem` são independentes de propósito — um contrato pode ter sido
--   legitimamente ativo e simplesmente ter sido recodificado, o que não é o mesmo que ter sido
--   desligado.
--
--   `ausente_na_origem` (TINYINT(1) NOT NULL DEFAULT 0): 0 = a chave apareceu no último sync
--   completo aplicável; 1 = estava vigente no espelho mas deixou de vir num sync completo mais
--   recente. `ausente_desde` (DATETIME NULL): primeiro instante em que a ausência foi detectada —
--   nunca sobrescrito enquanto o registro continuar ausente (preserva a data real da primeira
--   detecção), e sempre limpo de volta para NULL se a chave reaparecer num sync futuro.
--
--   A reconciliação em si (comparar o lote recebido contra o espelho e marcar o que sumiu) só
--   roda a partir de MetadadosSyncService::applyRows() quando explicitamente pedida por um
--   chamador que sabe estar processando um sync completo e bem-sucedido (run() local e
--   MetadadosSyncIngestService::receberLote() em produção) — nunca em teste isolado, dry-run ou
--   lote parcial. Ver docstring de applyRows()/reconciliarAusentes() para o algoritmo completo.
--
--   Idempotente e portável (MariaDB 11.8 em produção e MySQL 8.4 em desenvolvimento): mesmo padrão
--   de INFORMATION_SCHEMA + PREPARE/EXECUTE já usado em 2026-09-24-usuarios-gestor-imediato.sql e
--   2026-09-24-colaboradores-metadados-sexo.sql. NÃO aplicar em produção nesta execução.

SET @col_existe := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colaboradores_metadados' AND COLUMN_NAME = 'ausente_na_origem'
);
SET @sql := IF(@col_existe = 0, 'ALTER TABLE colaboradores_metadados ADD COLUMN ausente_na_origem TINYINT(1) NOT NULL DEFAULT 0 AFTER sincronizado_em', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_existe := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colaboradores_metadados' AND COLUMN_NAME = 'ausente_desde'
);
SET @sql := IF(@col_existe = 0, 'ALTER TABLE colaboradores_metadados ADD COLUMN ausente_desde DATETIME NULL AFTER ausente_na_origem', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
