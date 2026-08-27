-- Migration: 2026-08-25-solicitacao-vaga-kanban.sql
-- Objetivo:
--   Kanban de Solicitações de Vaga (acompanhamento operacional da vaga solicitada pelo
--   gestor), independente do fluxo de aprovação líder/RH já existente em
--   solicitacoes_vaga.status_fluxo (pendente_lider/pendente_rh/aprovada/reprovada_*/concluida).
--
--   As duas máquinas de estado ficam desacopladas de propósito:
--   - status_fluxo         -> segue exclusivamente o fluxo de aprovação (líder/RH), já em produção.
--   - situacao_kanban_id   -> segue exclusivamente o ciclo operacional da vaga no novo Kanban
--                             (Em aprovação/Aprovada/Em recrutamento/Em processo seletivo/
--                             Fechada/Cancelada), movimentado manualmente via drag-and-drop.
--
--   Catálogo de etapas em tabela própria (solicitacao_vaga_stages), não em pipeline_stages
--   (tabela do Kanban de candidatos, que nem possui migration própria — só existe via dump
--   de produção). Histórico em tabela própria (solicitacao_vaga_kanban_historico), espelhando
--   o padrão já usado em candidatura_historico: mesma tabela serve tanto para transição de
--   etapa quanto para anotação avulsa (quando situacao_anterior = situacao_nova).

CREATE TABLE IF NOT EXISTS solicitacao_vaga_stages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(60) NOT NULL,
  slug VARCHAR(50) NOT NULL,
  ordem INT NOT NULL DEFAULT 0,
  cor VARCHAR(7) NOT NULL DEFAULT '#cccccc',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_solicitacao_vaga_stages_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO solicitacao_vaga_stages (nome, slug, ordem, cor) VALUES
  ('Em aprovação', 'em-aprovacao', 1, '#f59e0b'),
  ('Aprovada', 'aprovada', 2, '#1d4ed8'),
  ('Em recrutamento', 'em-recrutamento', 3, '#0ea5e9'),
  ('Em processo seletivo', 'em-processo-seletivo', 4, '#8b5cf6'),
  ('Fechada', 'fechada', 5, '#059669'),
  ('Cancelada', 'cancelada', 6, '#ef4444');

CREATE TABLE IF NOT EXISTS solicitacao_vaga_kanban_historico (
  id INT AUTO_INCREMENT PRIMARY KEY,
  solicitacao_id INT NOT NULL,
  situacao_anterior VARCHAR(60) DEFAULT NULL,
  situacao_nova VARCHAR(60) NOT NULL,
  observacao TEXT DEFAULT NULL,
  usuario_id INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sv_kanban_historico_solicitacao (solicitacao_id),
  CONSTRAINT fk_sv_kanban_hist_solicitacao FOREIGN KEY (solicitacao_id) REFERENCES solicitacoes_vaga(id) ON DELETE CASCADE,
  CONSTRAINT fk_sv_kanban_hist_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE solicitacoes_vaga
  ADD COLUMN situacao_kanban_id INT NULL AFTER status_fluxo,
  ADD COLUMN motivo_cancelamento_encrypted TEXT NULL AFTER situacao_kanban_id,
  ADD COLUMN cancelada_em DATETIME NULL AFTER motivo_cancelamento_encrypted,
  ADD COLUMN cancelada_por_usuario_id INT NULL AFTER cancelada_em,
  ADD COLUMN fechada_em DATETIME NULL AFTER cancelada_por_usuario_id,
  ADD COLUMN fechada_por_usuario_id INT NULL AFTER fechada_em;

ALTER TABLE solicitacoes_vaga
  ADD KEY idx_solicitacoes_situacao_kanban (situacao_kanban_id),
  ADD CONSTRAINT fk_solicitacoes_situacao_kanban FOREIGN KEY (situacao_kanban_id) REFERENCES solicitacao_vaga_stages(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_solicitacoes_cancelada_por FOREIGN KEY (cancelada_por_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_solicitacoes_fechada_por FOREIGN KEY (fechada_por_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL;

-- Backfill dos registros existentes: mapeamento determinístico status_fluxo -> situação
-- operacional inicial do Kanban. Ver decisão registrada em CLAUDE.md/sprint: reprovada_lider e
-- reprovada_rh viram 'cancelada' só como estado operacional; NÃO inventamos um motivo de
-- cancelamento de negócio que nunca existiu (motivo_cancelamento_encrypted fica NULL nesses
-- casos) — o histórico abaixo documenta explicitamente a origem da migração automática.

START TRANSACTION;

UPDATE solicitacoes_vaga sv
JOIN solicitacao_vaga_stages st ON st.slug = (
  CASE sv.status_fluxo
    WHEN 'pendente_lider'   THEN 'em-aprovacao'
    WHEN 'pendente_rh'      THEN 'em-aprovacao'
    WHEN 'aprovada'         THEN 'aprovada'
    WHEN 'concluida'        THEN 'fechada'
    WHEN 'reprovada_lider'  THEN 'cancelada'
    WHEN 'reprovada_rh'     THEN 'cancelada'
  END
)
SET sv.situacao_kanban_id = st.id
WHERE sv.situacao_kanban_id IS NULL;

-- Fechamento: melhor esforço a partir de dados já registrados (nunca inventado). Data de
-- fechamento = data de admissão já preenchida no controle interno de RH, com fallback para
-- updated_at; responsável = último ator que gravou o controle interno de RH na auditoria
-- já existente (solicitacao_vaga_auditoria).
UPDATE solicitacoes_vaga sv
JOIN solicitacao_vaga_stages st ON st.id = sv.situacao_kanban_id AND st.slug = 'fechada'
SET sv.fechada_em = COALESCE(sv.data_admissao, sv.updated_at),
    sv.fechada_por_usuario_id = (
      SELECT sa.actor_usuario_id
      FROM solicitacao_vaga_auditoria sa
      WHERE sa.solicitacao_id = sv.id AND sa.event_type = 'rh_control_update' AND sa.actor_usuario_id IS NOT NULL
      ORDER BY sa.created_at DESC
      LIMIT 1
    )
WHERE sv.fechada_em IS NULL;

-- Cancelamento: apenas o carimbo de quando a migração rodou; motivo permanece NULL de propósito.
UPDATE solicitacoes_vaga sv
JOIN solicitacao_vaga_stages st ON st.id = sv.situacao_kanban_id AND st.slug = 'cancelada'
SET sv.cancelada_em = sv.updated_at
WHERE sv.cancelada_em IS NULL;

INSERT INTO solicitacao_vaga_kanban_historico (solicitacao_id, situacao_anterior, situacao_nova, observacao, usuario_id, created_at)
SELECT sv.id, NULL, 'Cancelada',
       'Cancelamento migrado automaticamente a partir do fluxo de aprovação anterior.',
       NULL, sv.updated_at
FROM solicitacoes_vaga sv
JOIN solicitacao_vaga_stages st ON st.id = sv.situacao_kanban_id AND st.slug = 'cancelada'
WHERE sv.status_fluxo IN ('reprovada_lider', 'reprovada_rh');

COMMIT;
