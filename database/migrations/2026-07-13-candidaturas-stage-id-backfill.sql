-- Migration: Backfill de candidaturas.stage_id NULL para a etapa inicial do pipeline
--
-- Contexto: Candidatura::create() nunca atribuía stage_id no INSERT, deixando a coluna
-- NULL até a primeira movimentação manual no Kanban. Isso quebrava exatamente essa
-- primeira movimentação: AdminPipelineController exibia esses candidatos na coluna
-- "Nova Inscrição" via fallback só-de-tela (`?? 1`), mas RecruitmentPipelineService
-- comparava contra o stage_id real (NULL -> 0) e rejeitava com falso "conflito de
-- concorrência" (ver bug reportado em 2026-07-13). Esta migration corrige os registros
-- já afetados; o código (Candidatura::create) foi corrigido para não gerar novos casos.
--
-- Cada linha corrigida ganha uma entrada em candidatura_historico (tabela de auditoria
-- já existente, reaproveitada) para manter rastreabilidade de que o stage_id não veio
-- de uma movimentação real de usuário.

START TRANSACTION;

SET @default_stage_id = (SELECT id FROM pipeline_stages WHERE slug = 'nova-inscricao' LIMIT 1);

INSERT INTO candidatura_historico (candidatura_id, status_anterior, status_novo, observacoes, usuario_id, created_at)
SELECT id, NULL, 'Nova Inscrição',
       'Backfill automático: stage_id estava NULL, impedindo movimentação no Kanban (bug do fallback de exibição). Atribuído à etapa inicial. Ver database/migrations/2026-07-13-candidaturas-stage-id-backfill.sql',
       NULL, NOW()
FROM candidaturas
WHERE stage_id IS NULL AND @default_stage_id IS NOT NULL;

UPDATE candidaturas
SET stage_id = @default_stage_id
WHERE stage_id IS NULL AND @default_stage_id IS NOT NULL;

COMMIT;
