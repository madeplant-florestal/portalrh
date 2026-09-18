-- Rollback: 2026-09-19-pesquisa-integracao-qr-rollback.sql
-- Reverte 2026-09-19-pesquisa-integracao-qr.sql.
--
-- DESTRUTIVO: para voltar `colaborador_id`/`token_hash` a NOT NULL é preciso apagar antes TODAS as
-- respostas do fluxo QR (linhas com colaborador_id NULL) — perda de dados coletados pelo QR. As
-- linhas do fluxo individual (colaborador_id preenchido) permanecem intactas. Só executar com
-- aprovação explícita e separada (regra 6 do CLAUDE.md), nunca como rotina.

DELETE FROM pesquisas_integracao WHERE colaborador_id IS NULL;

ALTER TABLE pesquisas_integracao
  DROP INDEX uk_pesquisas_integracao_contrato_evento,
  DROP COLUMN metadados_id,
  MODIFY colaborador_id INT NOT NULL,
  MODIFY token_hash CHAR(64) NOT NULL;

DROP TABLE IF EXISTS sessoes_integracao;
