-- Rollback: 2026-11-06-cartas-propostas-comprovante-rollback.sql
-- Reverte 2026-11-06-cartas-propostas-comprovante.sql.
-- ATENÇÃO: remove também qualquer via de comprovante já gerada (os arquivos PDF em
-- storage/cartas_propostas/ não são apagados por este rollback — remoção de arquivo é manual).

ALTER TABLE cartas_propostas
  DROP KEY uk_cartas_propostas_comprovante_identificador,
  DROP COLUMN comprovante_gerado_em,
  DROP COLUMN comprovante_identificador,
  DROP COLUMN comprovante_pdf_nome_armazenado;
