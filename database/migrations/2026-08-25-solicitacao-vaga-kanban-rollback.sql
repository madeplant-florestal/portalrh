-- Rollback: reverte database/migrations/2026-08-25-solicitacao-vaga-kanban.sql

START TRANSACTION;

DELETE FROM solicitacao_vaga_kanban_historico
WHERE observacao = 'Cancelamento migrado automaticamente a partir do fluxo de aprovação anterior.';

COMMIT;

ALTER TABLE solicitacoes_vaga
  DROP FOREIGN KEY fk_solicitacoes_situacao_kanban,
  DROP FOREIGN KEY fk_solicitacoes_cancelada_por,
  DROP FOREIGN KEY fk_solicitacoes_fechada_por,
  DROP KEY idx_solicitacoes_situacao_kanban;

ALTER TABLE solicitacoes_vaga
  DROP COLUMN situacao_kanban_id,
  DROP COLUMN motivo_cancelamento_encrypted,
  DROP COLUMN cancelada_em,
  DROP COLUMN cancelada_por_usuario_id,
  DROP COLUMN fechada_em,
  DROP COLUMN fechada_por_usuario_id;

DROP TABLE IF EXISTS solicitacao_vaga_kanban_historico;
DROP TABLE IF EXISTS solicitacao_vaga_stages;
