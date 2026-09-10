-- Rollback: 2026-09-08-reconciliar-setores-locais-metadados.sql
--
-- Desfaz APENAS esta reconciliação manual: solta os códigos oficiais '1'/'6'/'9' dos ids
-- 10/7/12 e limpa o `origem_metadados` que esta migration carimbou. Nunca toca outros registros,
-- nunca toca nome/slug/empresa_id/ativo/id/FKs.
--
-- Guarda `descricao_oficial IS NULL`: se uma sincronização REAL já enriqueceu a linha (descrição
-- oficial preenchida), o rollback é no-op nessa linha. Reverter uma reconciliação depois de já
-- ter sincronizado dados de verdade não é o caso de uso deste rollback, e 0 linhas afetadas é o
-- sinal correto de que não há o que desfazer com segurança aqui.

UPDATE setores SET codigo_setor = NULL, origem_metadados = NULL
    WHERE id = 10 AND codigo_setor = '1' AND descricao_oficial IS NULL;
UPDATE setores SET codigo_setor = NULL, origem_metadados = NULL
    WHERE id = 7 AND codigo_setor = '6' AND descricao_oficial IS NULL;
UPDATE setores SET codigo_setor = NULL, origem_metadados = NULL
    WHERE id = 12 AND codigo_setor = '9' AND descricao_oficial IS NULL;
