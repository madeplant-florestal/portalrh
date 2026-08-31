-- Migration: 2026-08-31-colaboradores-metadados-origem.sql
-- Objetivo:
--   Missão corretiva "Pureza da base analítica METADADOS" — o espelho colaboradores_metadados
--   não guardava a origem (RHTESTE vs RHMADEPLANT) de cada linha sincronizada. Isso permitiu que
--   6 contratos de RHTESTE (sincronizados nas Fases 1/2/3.1, quando local.php apontava para
--   RHTESTE) permanecessem silenciosamente misturados aos 727 contratos oficiais de RHMADEPLANT
--   carregados na Fase 3.3, sem nenhuma forma de distinguir uns dos outros. Os 6 já foram
--   removidos (identificação determinística via a própria semântica do upsert — ver
--   docs/claude/roadmap-tecnico.md) antes desta migration.
--
--   Esta migration adiciona a coluna que torna a origem de cada linha explícita e consultável, e
--   faz o backfill dos 727 contratos remanescentes como 'RHMADEPLANT' — confirmado por evidência
--   (log de sincronização storage/imports/metadados-sync-20260828-170616.json: 693 inseridos + 33
--   atualizados + 1 inalterado = 727, todos tocados pela sincronização real contra RHMADEPLANT).
--
--   A partir desta migration, MetadadosSyncService::applyRows() recusa por padrão sincronizar uma
--   origem diferente da já predominante no espelho (ver ColaboradorMetadadosRepository/
--   MetadadosSyncService::originConflict()) — só prossegue com a flag explícita
--   --permitir-origem-mista no CLI.

ALTER TABLE colaboradores_metadados
  ADD COLUMN origem_metadados VARCHAR(60) NOT NULL DEFAULT '' AFTER ativo,
  ADD INDEX idx_colaboradores_metadados_origem (origem_metadados);

UPDATE colaboradores_metadados SET origem_metadados = 'RHMADEPLANT' WHERE origem_metadados = '';
