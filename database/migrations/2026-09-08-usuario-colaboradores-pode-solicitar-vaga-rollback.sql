-- Rollback: 2026-09-08-usuario-colaboradores-pode-solicitar-vaga.sql
-- Remove a coluna. Seguro: puramente aditiva, nenhuma FK, nenhuma tela obrigatória depende dela
-- (o gate volta a exigir `is_gestor = 1`, comportamento anterior). Descarta as autorizações
-- explícitas registradas.

ALTER TABLE usuario_colaboradores
  DROP COLUMN pode_solicitar_vaga;
