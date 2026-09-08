-- Rollback: 2026-09-08-reconciliar-empresas-locais-metadados.sql
--
-- Desfaz APENAS esta reconciliação manual: solta os códigos oficiais 0005/0007/0008 dos ids
-- 2/3/4 e limpa o `origem_metadados` que esta migration carimbou. Nunca toca outros registros,
-- nunca toca nome/slug/ativo/id/FKs.
--
-- Guarda `razao_social IS NULL`: se uma sincronização REAL já enriqueceu a linha (razão social
-- oficial preenchida), o rollback é no-op nessa linha. Reverter uma reconciliação depois de já
-- ter sincronizado dados de verdade não é o caso de uso deste rollback, e 0 linhas afetadas é o
-- sinal correto de que não há o que desfazer com segurança aqui.

UPDATE empresas SET codigo_empresa = NULL, origem_metadados = NULL
    WHERE id = 2 AND codigo_empresa = '0005' AND razao_social IS NULL;
UPDATE empresas SET codigo_empresa = NULL, origem_metadados = NULL
    WHERE id = 3 AND codigo_empresa = '0007' AND razao_social IS NULL;
UPDATE empresas SET codigo_empresa = NULL, origem_metadados = NULL
    WHERE id = 4 AND codigo_empresa = '0008' AND razao_social IS NULL;
