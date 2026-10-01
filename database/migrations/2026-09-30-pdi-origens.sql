-- Migration: 2026-09-30-pdi-origens.sql
-- Objetivo:
--   Etapa 7 — Avaliações e Feedbacks alimentando o PDI. `pdis.origem_tipo`/`origem_ref_tipo`/
--   `origem_ref_id` (2026-09-23-pdi.sql) continuam existindo e representam a origem PRINCIPAL/
--   fundadora do PDI (campo obrigatório, 1 valor, sem FK — mesma convenção já usada). Esta tabela
--   ADICIONA suporte a MÚLTIPLAS origens por PDI (PDI 1:N Origens, §6 da Etapa 7): um PDI pode ser
--   alimentado por mais de uma Avaliação de Experiência/Feedback/Avaliação de Desempenho ao longo do
--   tempo, sem duplicar o documento inteiro (só um pequeno snapshot textual da necessidade
--   selecionada, §20).
--
--   Sem FK em (origem_tipo, origem_ref_id): o alvo é uma de três tabelas diferentes conforme
--   origem_tipo (avaliacoes_experiencia / feedbacks / avaliacoes_desempenho) — mesma convenção
--   "sem FK polimórfica" já usada em pdis.origem_ref_id e em avaliacoes_desenvolvimento_eventos/
--   ciencia (documento_tipo + documento_id).
--
--   UNIQUE (pdi_id, origem_tipo, origem_ref_id) previne duplicidade silenciosa (§16): vincular o
--   mesmo documento duas vezes ao mesmo PDI é rejeitado pelo banco (defesa em profundidade; o Service
--   também verifica antes de tentar inserir). O MESMO documento pode alimentar PDIs DIFERENTES — não
--   há UNIQUE em (origem_tipo, origem_ref_id) sozinho.
--
--   'manual'/'desenvolvimento_carreira' NUNCA aparecem aqui — só origens DOCUMENTAIS entram em
--   pdi_origens; um PDI manual (sem documento de origem) não tem nenhuma linha nesta tabela e
--   continua funcionando exatamente como antes (PdiService::ORIGENS inalterado).
--
--   Depende de `pdis` (2026-09-23-pdi.sql) e `usuarios` já aplicadas. Rollback: 2026-09-30-pdi-origens-rollback.sql.

CREATE TABLE IF NOT EXISTS pdi_origens (
  id                     INT AUTO_INCREMENT PRIMARY KEY,
  pdi_id                 INT           NOT NULL,
  origem_tipo            VARCHAR(30)   NOT NULL,
  origem_ref_id          INT           NOT NULL,
  contexto_snapshot       TEXT          NULL,
  criado_por_usuario_id  INT           NOT NULL,
  criado_em              DATETIME      NOT NULL,

  UNIQUE KEY uk_pdi_origens_doc (pdi_id, origem_tipo, origem_ref_id),
  KEY idx_pdi_origens_pdi (pdi_id),
  KEY idx_pdi_origens_origem (origem_tipo, origem_ref_id),
  CONSTRAINT fk_pdi_origens_pdi FOREIGN KEY (pdi_id) REFERENCES pdis(id) ON DELETE CASCADE,
  CONSTRAINT fk_pdi_origens_criado_por FOREIGN KEY (criado_por_usuario_id) REFERENCES usuarios(id),
  CONSTRAINT chk_pdi_origens_tipo CHECK (origem_tipo IN ('avaliacao_experiencia','feedback','avaliacao_desempenho'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
