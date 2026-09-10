-- Rollback: 2026-09-10-usuarios-contexto-organizacional.sql
--
--   Desfaz a estrutura de contexto organizacional de `usuarios`. Remove APENAS o que a migration
--   criou: a tabela `usuario_setores` e a coluna `usuarios.cargo_id` (com seu índice e FK).
--
--   Nenhum dado de `usuarios` é perdido além do próprio `cargo_id` (coluna nova). Nenhuma linha de
--   `usuarios` é excluída. `cargos` e `setores` não são tocados.
--
--   Compatível com MySQL 8.4.x e MariaDB 11.8.9-log. Executar na ordem abaixo (a tabela primeiro:
--   ela não referencia `cargo_id`, mas mantém a simetria com a migration).

DROP TABLE IF EXISTS usuario_setores;

ALTER TABLE usuarios
  DROP FOREIGN KEY fk_usuarios_cargo;

ALTER TABLE usuarios
  DROP KEY idx_usuarios_cargo,
  DROP COLUMN cargo_id;
