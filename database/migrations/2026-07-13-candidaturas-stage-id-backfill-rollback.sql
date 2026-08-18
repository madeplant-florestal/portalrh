-- Rollback: reverte o backfill de database/migrations/2026-07-13-candidaturas-stage-id-backfill.sql
--
-- Localiza as linhas pelo texto do marcador de auditoria em candidatura_historico (não
-- pelos ids da candidatura, que variam entre ambientes) e devolve o stage_id a NULL.

START TRANSACTION;

UPDATE candidaturas c
JOIN candidatura_historico h ON h.candidatura_id = c.id
SET c.stage_id = NULL
WHERE h.observacoes LIKE 'Backfill automático: stage_id estava NULL%';

DELETE FROM candidatura_historico
WHERE observacoes LIKE 'Backfill automático: stage_id estava NULL%';

COMMIT;
