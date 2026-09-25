-- Migration: 2026-09-25-colaboradores-metadados-data-transferencia.sql
-- Objetivo:
--   Persiste a data OFICIAL de transferência interempresa (RHCONTRATOS.DATAULTTRANSFERENCIA),
--   confirmada por investigação direta no SQL Server RHMADEPLANT com cobertura 66/66 (100%) nos
--   casos reais de transferência contínua ocorridos em 01/09/2026 — fonte usada por
--   MetadadosMovimentacaoService::classificarTransferenciaContinua() para derivar a VIGÊNCIA
--   ANALÍTICA de cada lado da transferência (fim = data-1 na origem, início = data no destino),
--   nunca para alterar `admissao`/`demissao` oficiais.
--
--   `data_ultima_transferencia` (DATE NULL, nunca DATETIME — precisamos da data civil efetiva,
--   sem hora/timezone): NULL para todo contrato que nunca foi transferido. Campo puramente
--   informativo/derivado da origem — nenhuma regra de negócio grava nele fora da sincronização.
--
--   Idempotente e portável (MariaDB 11.8 em produção e MySQL 8.4 em desenvolvimento): mesmo padrão
--   de INFORMATION_SCHEMA + PREPARE/EXECUTE já usado em 2026-09-24-colaboradores-metadados-
--   reconciliacao-ausencia.sql. NÃO aplicar em produção nesta execução — só no banco local de
--   desenvolvimento, para validar a correção de transferências interempresa do People Analytics.

SET @col_existe := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colaboradores_metadados' AND COLUMN_NAME = 'data_ultima_transferencia'
);
SET @sql := IF(@col_existe = 0, 'ALTER TABLE colaboradores_metadados ADD COLUMN data_ultima_transferencia DATE NULL AFTER ausente_desde', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
