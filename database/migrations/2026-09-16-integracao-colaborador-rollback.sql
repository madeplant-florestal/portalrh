-- Rollback: 2026-09-16-integracao-colaborador.sql
-- Remove só as 3 colunas de Integração + FK/índice associados. Preserva 100% dos demais dados de
-- `colaboradores`.

ALTER TABLE colaboradores
  DROP FOREIGN KEY fk_colaboradores_integracao_responsavel,
  DROP KEY idx_colaboradores_integracao_responsavel,
  DROP COLUMN integracao_responsavel_usuario_id,
  DROP COLUMN integracao_data,
  DROP COLUMN integracao_status;
