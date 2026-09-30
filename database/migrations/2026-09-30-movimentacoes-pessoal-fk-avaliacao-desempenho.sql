-- Migration: 2026-09-30-movimentacoes-pessoal-fk-avaliacao-desempenho.sql
-- Objetivo:
--   `movimentacoes_pessoal.avaliacao_desempenho_id` passa a referenciar a Avaliação de Desempenho
--   NATIVA (`avaliacoes_desempenho`), não mais o legado `colaborador_avaliacoes`. Aprovado
--   explicitamente: a base de Movimentação de Pessoal parte do zero em produção (0 linhas reais
--   hoje — confirmado antes desta migration) e não precisamos carregar compatibilidade estrutural
--   com o legado. O NOME da coluna é preservado (`avaliacao_desempenho_id`) por continuar
--   semanticamente correto — só o ALVO do FK muda.
--
--   Sem coluna nova, sem SET NULL de dado real (não há dado real a proteger). A mesma troca é
--   replicada em `MovimentacaoPessoal::ensureSchema()` (schema fragmentado em três fontes — ver
--   CLAUDE.md), para que um ambiente novo (fresh install/teste) já nasça com o FK correto.
--
--   Depende de 2026-09-30-avaliacoes-desempenho.sql já aplicada.
--   Rollback: 2026-09-30-movimentacoes-pessoal-fk-avaliacao-desempenho-rollback.sql.

ALTER TABLE movimentacoes_pessoal
  DROP FOREIGN KEY fk_movimentacoes_avaliacao;

ALTER TABLE movimentacoes_pessoal
  ADD CONSTRAINT fk_movimentacoes_avaliacao FOREIGN KEY (avaliacao_desempenho_id) REFERENCES avaliacoes_desempenho(id) ON DELETE SET NULL;
