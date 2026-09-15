-- Rollback: 2026-09-15-permissoes-individuais.sql
-- Remove as duas tabelas novas, nesta ordem (usuario_permissoes referencia permissoes via FK).
-- Não afeta `usuarios` nem qualquer outra tabela existente. `pode_solicitar_vaga` e demais
-- colunas legadas permanecem intocadas — o rollback apenas desfaz a fundação de permissões.

DROP TABLE IF EXISTS usuario_permissoes;
DROP TABLE IF EXISTS permissoes;
