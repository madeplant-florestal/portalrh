-- Rollback: 2026-09-08-vagas-solicitacao-vaga-id.sql
-- Remove a FK, o índice UNIQUE e as colunas. Seguro: puramente aditivas, nenhuma coluna existente
-- de `vagas` é tocada. Perde-se a rastreabilidade Solicitação -> Vaga das vagas já geradas (as
-- vagas em si permanecem).

ALTER TABLE vagas
  DROP FOREIGN KEY fk_vagas_solicitacao_vaga;

ALTER TABLE vagas
  DROP KEY uk_vagas_solicitacao_vaga,
  DROP COLUMN publicada_em,
  DROP COLUMN solicitacao_vaga_id;
