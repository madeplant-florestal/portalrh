-- Rollback: 2026-09-10-reconciliar-cargos-locais-metadados.sql
--
-- Desfaz APENAS esta reconciliação manual: solta o código oficial '047' do id 24 e limpa o
-- `origem_metadados` que esta migration carimbou. Nunca toca outros registros, nunca toca
-- nome/slug/ativo/id/FKs.
--
-- Guarda `descricao_oficial IS NULL`: se uma sincronização REAL já enriqueceu a linha (descrição
-- oficial preenchida), o rollback é no-op (0 linhas afetadas) — reverter depois de sincronizar
-- dados de verdade não é o caso de uso deste rollback.

UPDATE cargos SET codigo_cargo = NULL, origem_metadados = NULL
    WHERE id = 24 AND codigo_cargo = '047' AND descricao_oficial IS NULL;
