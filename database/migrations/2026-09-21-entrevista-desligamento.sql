-- Migration: 2026-09-21-entrevista-desligamento.sql
-- Objetivo:
--   Módulo "Entrevista de Desligamento". Uma entrevista IDENTIFICADA por CONTRATO oficial do METADADOS
--   (`colaboradores_metadados.id` = `metadados_id`), respondida pelo ex-colaborador através de um link
--   individual (token aleatório; só o hash SHA-256 é persistido).
--
--   - UNIQUE (metadados_id): uma entrevista por contrato (recontratação = outro contrato = outra entrevista).
--   - UNIQUE (token_hash): o hash identifica a entrevista na página pública. Regenerar o link troca o hash
--     (o token anterior deixa de existir para o sistema).
--   - snap_*: SNAPSHOT dos dados oficiais no momento da geração — uma sincronização posterior do METADADOS
--     não altera o contexto histórico da entrevista. Sem CPF, nascimento ou salário.
--   - Estado da entrevista é DERIVADO de timestamps (respondida_em / cancelada_em / expira_em) — sem coluna
--     de status redundante.
--   - Motivo declarado (`motivo_principal`) é INDEPENDENTE do motivo oficial (`snap_motivo_*`).
--   - Fatores contribuintes em tabela filha (N:N por chave composta), nunca lista delimitada.
--   - Sem coluna de classificação eNPS: Promotor/Neutro/Detrator é calculado pelo sistema a partir de `enps`.
--
--   Idempotente (CREATE TABLE IF NOT EXISTS). Depende de `colaboradores_metadados` e `usuarios`.
--   Rollback: 2026-09-21-entrevista-desligamento-rollback.sql.

CREATE TABLE IF NOT EXISTS entrevistas_desligamento (
  id                              INT AUTO_INCREMENT PRIMARY KEY,
  metadados_id                    INT          NOT NULL,
  token_hash                      CHAR(64)     NOT NULL,
  gerada_em                       DATETIME     NOT NULL,
  gerada_por_usuario_id           INT          NOT NULL,
  regeneracoes                    INT          NOT NULL DEFAULT 0,
  expira_em                       DATETIME     NOT NULL,
  cancelada_em                    DATETIME     NULL,
  cancelada_por_usuario_id        INT          NULL,
  respondida_em                   DATETIME     NULL,

  snap_nome                       VARCHAR(180) NOT NULL,
  snap_codigo_empresa             VARCHAR(20)  NOT NULL,
  snap_empresa                    VARCHAR(180) NULL,
  snap_codigo_unidade             VARCHAR(20)  NOT NULL,
  snap_unidade                    VARCHAR(180) NULL,
  snap_codigo_cargo               VARCHAR(20)  NULL,
  snap_cargo                      VARCHAR(180) NULL,
  snap_admissao                   DATE         NULL,
  snap_demissao                   DATE         NOT NULL,
  snap_motivo_codigo              VARCHAR(20)  NULL,
  snap_motivo_descricao           VARCHAR(180) NULL,

  motivo_principal                VARCHAR(40)  NULL,
  motivo_descricao                TEXT         NULL,

  exp_remuneracao                 TINYINT      NULL,
  exp_beneficios                  TINYINT      NULL,
  exp_condicoes_trabalho          TINYINT      NULL,
  exp_comunicacao                 TINYINT      NULL,
  exp_desenvolvimento             TINYINT      NULL,
  exp_reconhecimento              TINYINT      NULL,
  exp_clima                       TINYINT      NULL,

  lid_respeito                    TINYINT      NULL,
  lid_comunicacao                 TINYINT      NULL,
  lid_abertura                    TINYINT      NULL,
  lid_desenvolvimento             TINYINT      NULL,
  lid_justica                     TINYINT      NULL,

  cul_respeito                    TINYINT      NULL,
  cul_honestidade                 TINYINT      NULL,
  cul_lealdade                    TINYINT      NULL,
  cul_etica                       TINYINT      NULL,
  cul_coragem                     TINYINT      NULL,
  cul_ousadia                     TINYINT      NULL,

  int_compreender                 TINYINT      NULL,
  int_treinamento                 TINYINT      NULL,
  int_expectativa                 TINYINT      NULL,

  experiencia_geral               TINYINT      NULL,
  enps                            TINYINT      NULL,

  aberta_continuar                TEXT         NULL,
  aberta_melhorar                 TEXT         NULL,
  aberta_mensagem                 TEXT         NULL,

  created_at                      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uk_entrevistas_desligamento_contrato (metadados_id),
  UNIQUE KEY uk_entrevistas_desligamento_token (token_hash),
  KEY idx_entrevistas_desligamento_expira (expira_em),
  KEY idx_entrevistas_desligamento_respondida (respondida_em),
  KEY idx_entrevistas_desligamento_gerada_por (gerada_por_usuario_id),
  KEY idx_entrevistas_desligamento_cancelada_por (cancelada_por_usuario_id),
  CONSTRAINT fk_entrevistas_desligamento_contrato FOREIGN KEY (metadados_id) REFERENCES colaboradores_metadados(id) ON DELETE RESTRICT,
  CONSTRAINT fk_entrevistas_desligamento_gerada_por FOREIGN KEY (gerada_por_usuario_id) REFERENCES usuarios(id),
  CONSTRAINT fk_entrevistas_desligamento_cancelada_por FOREIGN KEY (cancelada_por_usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS entrevistas_desligamento_fatores (
  entrevista_id                   INT          NOT NULL,
  fator                           VARCHAR(40)  NOT NULL,
  PRIMARY KEY (entrevista_id, fator),
  CONSTRAINT fk_entrevistas_desligamento_fatores_entrevista FOREIGN KEY (entrevista_id) REFERENCES entrevistas_desligamento(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
