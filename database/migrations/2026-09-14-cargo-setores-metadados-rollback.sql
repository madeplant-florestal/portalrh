-- Rollback: 2026-09-14-cargo-setores-metadados.sql
--
--   Remove a tabela `cargo_setores_metadados`. Não afeta `cargos`/`setores` (FKs são ON DELETE
--   CASCADE só no sentido cargo_setores_metadados -> cargos/setores, nunca o contrário) nem a
--   tabela legada `cargo_setores`, que nunca foi tocada por esta migration.

DROP TABLE IF EXISTS cargo_setores_metadados;
