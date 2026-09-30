-- Rollback: 2026-09-30-movimentacoes-pessoal-fk-avaliacao-desempenho-rollback.sql
-- Reverte 2026-09-30-movimentacoes-pessoal-fk-avaliacao-desempenho.sql — volta o FK para o legado
-- `colaborador_avaliacoes`. Só é seguro rodar se nenhuma linha de `movimentacoes_pessoal` estiver
-- apontando para um id que só existe em `avaliacoes_desempenho` (o FK ON DELETE SET NULL não limpa
-- retroativamente ao trocar o alvo).

ALTER TABLE movimentacoes_pessoal
  DROP FOREIGN KEY fk_movimentacoes_avaliacao;

ALTER TABLE movimentacoes_pessoal
  ADD CONSTRAINT fk_movimentacoes_avaliacao FOREIGN KEY (avaliacao_desempenho_id) REFERENCES colaborador_avaliacoes(id) ON DELETE SET NULL;
