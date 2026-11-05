-- Migration: 2026-11-05-entrevista-desligamento-comentarios-secao.sql
-- Objetivo:
--   Correção solicitada pelo RH (validação de 06/11/2026): cada uma das 5 dimensões da Entrevista
--   de Desligamento (Experiência na empresa, Liderança, Cultura e Valores, Integração e
--   Desenvolvimento, Experiência Geral) passa a ter seu PRÓPRIO comentário em texto livre,
--   opcional — as respostas de escala 1-5/0-10 nem sempre registram adequadamente a percepção do
--   ex-colaborador.
--
--   Extensão direta do padrão de colunas fixas já usado pelo módulo (ver
--   EntrevistaDesligamentoRepository::COLUNAS_RESPOSTA) — não há estrutura extensível de
--   perguntas/respostas preexistente para reaproveitar. Todas as colunas são NULLABLE: entrevistas
--   antigas (sem esses campos) continuam válidas sem qualquer alteração de dados.
--
--   Idempotente (ADD COLUMN IF NOT EXISTS). Rollback: 2026-11-05-entrevista-desligamento-comentarios-secao-rollback.sql.

ALTER TABLE entrevistas_desligamento
  ADD COLUMN IF NOT EXISTS exp_comentario TEXT NULL AFTER exp_clima,
  ADD COLUMN IF NOT EXISTS lid_comentario TEXT NULL AFTER lid_justica,
  ADD COLUMN IF NOT EXISTS cul_comentario TEXT NULL AFTER cul_ousadia,
  ADD COLUMN IF NOT EXISTS int_comentario TEXT NULL AFTER int_expectativa,
  ADD COLUMN IF NOT EXISTS experiencia_geral_comentario TEXT NULL AFTER experiencia_geral;
