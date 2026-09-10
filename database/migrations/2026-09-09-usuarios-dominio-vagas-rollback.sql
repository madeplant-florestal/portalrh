-- Rollback: 2026-09-09-usuarios-dominio-vagas.sql
-- Remove as 3 colunas de domínio de Vagas adicionadas a `usuarios` e todas as constraints/índices
-- associados. NÃO restaura valores em usuario_colaboradores (que nunca foram alterados).
--
-- Perda de dados esperada: quaisquer vínculos METADADOS (colaborador_metadados_id) e aprovadores
-- (aprovador_usuario_id) configurados manualmente após a migration são descartados. A autorização
-- (pode_solicitar_vaga) volta a depender exclusivamente de usuario_colaboradores no código antigo.

ALTER TABLE usuarios
  DROP FOREIGN KEY fk_usuarios_aprovador,
  DROP FOREIGN KEY fk_usuarios_colaborador_metadados;

ALTER TABLE usuarios
  DROP KEY idx_usuarios_aprovador,
  DROP KEY uk_usuarios_colaborador_metadados;

ALTER TABLE usuarios
  DROP COLUMN aprovador_usuario_id,
  DROP COLUMN colaborador_metadados_id,
  DROP COLUMN pode_solicitar_vaga;
