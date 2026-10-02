-- Migration: 2026-09-30-avaliacoes-desenvolvimento-ciencia-eventos-tipo-desempenho.sql
-- Objetivo:
--   Amplia os CHECK de `documento_tipo` em `avaliacoes_desenvolvimento_ciencia` e
--   `avaliacoes_desenvolvimento_eventos` (criadas na Etapa 4) para aceitar 'avaliacao_desempenho' —
--   ALTER puramente aditivo: nenhuma tabela nova, nenhum dado tocado, nenhuma coluna alterada.
--   `AvaliacoesDesenvolvimentoAuditoriaService` já aceita qualquer `documento_tipo` que o banco
--   permitir — nenhuma mudança de código é necessária além desta migration.
--   Depende de 2026-09-29-avaliacoes-desenvolvimento.sql (Etapa 4) já aplicada.
--   Rollback: 2026-09-30-avaliacoes-desenvolvimento-ciencia-eventos-tipo-desempenho-rollback.sql.

ALTER TABLE avaliacoes_desenvolvimento_ciencia
  DROP CONSTRAINT chk_avdesenv_ciencia_tipo_doc,
  ADD CONSTRAINT chk_avdesenv_ciencia_tipo_doc CHECK (documento_tipo IN ('avaliacao_experiencia','feedback','avaliacao_desempenho'));

ALTER TABLE avaliacoes_desenvolvimento_eventos
  DROP CONSTRAINT chk_avdesenv_eventos_tipo_doc,
  ADD CONSTRAINT chk_avdesenv_eventos_tipo_doc CHECK (documento_tipo IN ('avaliacao_experiencia','feedback','avaliacao_desempenho'));
