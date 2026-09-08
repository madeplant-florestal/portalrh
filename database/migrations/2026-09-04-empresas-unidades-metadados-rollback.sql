-- Rollback: 2026-09-04-empresas-unidades-metadados.sql
--
-- Desfaz a fundação de Empresas/Unidades da Fase 5.1A. Seguro enquanto nenhuma FK nova apontar
-- para `unidades` e nenhuma tela consumir as colunas novas de `empresas` (estado desta fase).
--
-- ATENÇÃO:
--   * DROP TABLE unidades descarta as unidades sincronizadas do METADADOS.
--   * DROP COLUMN em `empresas` descarta o vínculo `codigo_empresa` já adotado/sincronizado —
--     a próxima sincronização teria que readotar as empresas por nome novamente.
--   * `nome`, `slug`, `ativo`, `id` de `empresas` NÃO são tocados por este rollback.

DROP TABLE IF EXISTS unidades;

ALTER TABLE empresas
  DROP KEY uk_empresas_codigo,
  DROP COLUMN sincronizado_em,
  DROP COLUMN origem_metadados,
  DROP COLUMN razao_social,
  DROP COLUMN codigo_empresa;
