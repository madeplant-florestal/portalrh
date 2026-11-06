-- Migration: 2026-11-06-cartas-propostas-comprovante.sql
-- Objetivo:
--   Finalização da homologação da Carta Proposta — "via com comprovante de aceite" (PDF
--   SEPARADO do PDF original da proposta, gerado só depois do aceite válido do candidato).
--
--   `comprovante_pdf_nome_armazenado` -> nome do arquivo em storage/cartas_propostas/ (mesmo
--                                        diretório do PDF original, mesma convenção de nome
--                                        aleatório — nunca sobrescreve/substitui o PDF original).
--   `comprovante_identificador`       -> identificador público curto da decisão, impresso no
--                                        próprio documento (nunca o id interno auto-increment).
--   `comprovante_gerado_em`           -> quando a via foi gerada (sempre após decisao_em).
--
--   Puramente aditiva (ALTER TABLE ADD COLUMN). NÃO aplicar em produção nesta execução.
--   Rollback: 2026-11-06-cartas-propostas-comprovante-rollback.sql.

ALTER TABLE cartas_propostas
  ADD COLUMN comprovante_pdf_nome_armazenado VARCHAR(64) NULL AFTER pdf_nome_armazenado,
  ADD COLUMN comprovante_identificador VARCHAR(32) NULL AFTER comprovante_pdf_nome_armazenado,
  ADD COLUMN comprovante_gerado_em DATETIME NULL AFTER comprovante_identificador,
  ADD UNIQUE KEY uk_cartas_propostas_comprovante_identificador (comprovante_identificador);
