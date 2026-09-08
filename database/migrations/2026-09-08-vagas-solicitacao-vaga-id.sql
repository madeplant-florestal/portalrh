-- Migration: 2026-09-08-vagas-solicitacao-vaga-id.sql
-- Objetivo:
--   Sprint operacional de Solicitação/Publicação de Vagas. Torna rastreável e idempotente o
--   vínculo Solicitação de Vaga -> Vaga pública, e adiciona o carimbo de publicação.
--
--     - `solicitacao_vaga_id`  FK -> solicitacoes_vaga(id), NULLABLE (vagas legadas e o cadastro
--                              manual de exceção continuam com NULL), UNIQUE (uma solicitação
--                              origina no máximo UMA vaga — a geração é idempotente).
--                              ON DELETE SET NULL: apagar uma solicitação nunca apaga a vaga
--                              pública que já nasceu dela.
--     - `publicada_em`         DATETIME NULL. Preenchido quando o RH publica o rascunho.
--                              Visibilidade pública continua sendo `vagas.ativo = 1` (inalterado);
--                              `publicada_em` responde "quando foi publicada?" na auditoria.
--
--   Estado de RASCUNHO de uma vaga gerada por solicitação = `ativo = 0 AND publicada_em IS NULL`
--   (derivado, sem coluna de status nova). A geração cria a vaga com `ativo = 0`.
--
--   Puramente aditiva. NÃO altera nenhuma coluna existente de `vagas`, nenhuma FK existente,
--   nenhum registro. `RecruitmentWebhookSchemaService::ensureSchema()` também passa a garantir
--   estas colunas em instalação nova (padrão do projeto — ver CLAUDE.md §3.7).
--   `ALTER TABLE` puro (MySQL 8.4.3 não aceita ADD COLUMN IF NOT EXISTS).
--   NÃO aplicar em produção nesta execução.

ALTER TABLE vagas
  ADD COLUMN solicitacao_vaga_id INT NULL AFTER id,
  ADD COLUMN publicada_em DATETIME NULL AFTER ativo,
  ADD UNIQUE KEY uk_vagas_solicitacao_vaga (solicitacao_vaga_id),
  ADD CONSTRAINT fk_vagas_solicitacao_vaga FOREIGN KEY (solicitacao_vaga_id)
    REFERENCES solicitacoes_vaga(id) ON DELETE SET NULL;
