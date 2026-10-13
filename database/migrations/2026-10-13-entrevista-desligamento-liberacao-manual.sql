-- Migration: 2026-10-13-entrevista-desligamento-liberacao-manual.sql
-- Objetivo:
--   Liberação manual da Entrevista de Desligamento — permite RH/Admin liberar a Entrevista antes
--   de o contrato aparecer rescindido no METADADOS, SEM alterar nenhum dado oficial do
--   colaborador/contrato (METADADOS continua READ-ONLY; `colaboradores_metadados` não é tocada).
--
--   Por que uma tabela própria (em vez de 2 colunas soltas, ver pedido original do RH):
--     - não pode viver em `colaboradores_metadados` (espelho oficial, somente leitura);
--     - não pode viver em `colaboradores` (hub local legado): nem todo contrato do METADADOS tem
--       uma linha correspondente em `colaboradores` (gap já conhecido do Portal), e a granularidade
--       certa é por CONTRATO (`metadados_id`), não por colaborador-hub;
--     - `entrevistas_desligamento` já usa exatamente essa chave (`metadados_id` ->
--       `colaboradores_metadados.id`) — esta tabela espelha o MESMO padrão arquitetural.
--
--   UNIQUE(metadados_id): no máximo UMA liberação "ativa" por contrato — liberar/cancelar/liberar
--   de novo é um UPSERT na mesma linha (ver EntrevistaDesligamentoService::liberarManualmente()),
--   nunca acumula histórico de ciclos (auditoria mínima suficiente: quem liberou, quando, e se/quando
--   cancelou — não é um log de eventos).
--
--   `liberacao_ativa` (cancelada_em IS NULL) é SOMENTE um dos dois caminhos de elegibilidade —
--   nunca substitui a regra oficial (contrato rescindido no METADADOS continua sendo o caminho
--   principal) e NUNCA é usada por nenhum indicador de Headcount/Turnover/Admissões/Desligamentos/
--   People Analytics, que continuam 100% baseados em `colaboradores_metadados.demissao`.
--
--   Também relaxa `entrevistas_desligamento.snap_demissao` para NULL: uma entrevista gerada via
--   liberação manual (contrato ainda ativo, sem demissão real) precisa conseguir gravar snapshot
--   sem data de desligamento — antes dessa correção, NOT NULL quebraria esse fluxo (ou gravaria uma
--   data inventada, que é exatamente o que este Bloco proíbe).
--
--   Idempotente (CREATE TABLE IF NOT EXISTS / MODIFY COLUMN). Rollback:
--   2026-10-13-entrevista-desligamento-liberacao-manual-rollback.sql. NÃO executar em produção
--   nesta rodada.

CREATE TABLE IF NOT EXISTS entrevista_desligamento_liberacoes (
  id                        INT AUTO_INCREMENT PRIMARY KEY,
  metadados_id              INT          NOT NULL,
  liberada_em               DATETIME     NOT NULL,
  liberada_por_usuario_id   INT          NOT NULL,
  cancelada_em              DATETIME     NULL,
  cancelada_por_usuario_id  INT          NULL,
  created_at                TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_entrevista_desligamento_liberacoes_contrato (metadados_id),
  KEY idx_edl_liberada_por (liberada_por_usuario_id),
  KEY idx_edl_cancelada_por (cancelada_por_usuario_id),
  CONSTRAINT fk_edl_contrato FOREIGN KEY (metadados_id) REFERENCES colaboradores_metadados(id) ON DELETE RESTRICT,
  CONSTRAINT fk_edl_liberada_por FOREIGN KEY (liberada_por_usuario_id) REFERENCES usuarios(id),
  CONSTRAINT fk_edl_cancelada_por FOREIGN KEY (cancelada_por_usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE entrevistas_desligamento MODIFY COLUMN snap_demissao DATE NULL;
