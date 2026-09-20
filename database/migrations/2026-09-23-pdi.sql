-- Migration: 2026-09-23-pdi.sql
-- Objetivo:
--   PDI — Plano de Desenvolvimento Individual (V1 ASSISTIDA por RH/Gestor). Processo persistente e evolutivo
--   (não é pesquisa de resposta única). Vínculo por CONTRATO oficial (`colaboradores_metadados.id` =
--   `metadados_id`); SEM UNIQUE (metadados_id): um contrato pode ter vários PDIs ao longo do tempo.
--
--   pdis                — cabeçalho do plano + SNAPSHOT do contexto na abertura (sem CPF/salário), gestor explícito
--                         (`gestor_usuario_id` + snapshot do nome), origem (lista fechada + referência futura SEM FK),
--                         datas, status de negócio + estados técnicos (rascunho/cancelado), textos do desenvolvimento,
--                         espaço do colaborador (registrado ASSISTIDAMENTE), evidências textuais e avaliação final.
--   pdi_competencias    — competências em TEXTO na V1 (`competencia_id` nullable p/ integração futura, sem FK).
--   pdi_acoes           — plano de ação: no máximo 3 por PDI (`ordem` 1–3 + UNIQUE (pdi_id, ordem); regra também no Service).
--   pdi_acompanhamentos — comentários APPEND-ONLY (a aplicação nunca atualiza nem apaga).
--   pdi_eventos         — trilha de auditoria APPEND-ONLY, gravada na mesma transação da alteração.
--
--   Regras no banco (CHECK — aplicadas em MySQL ≥ 8.0.16 / MariaDB ≥ 10.2.1; o Service valida de qualquer forma):
--   listas fechadas, prazo ≥ abertura e coerência conclusão × avaliação final × data real.
--   Sem `ensureSchema` em runtime. Sem anexos, sem assinatura, sem notificações.
--   Depende de `colaboradores_metadados` e `usuarios`. Rollback: 2026-09-23-pdi-rollback.sql.

CREATE TABLE IF NOT EXISTS pdis (
  id                                INT AUTO_INCREMENT PRIMARY KEY,
  metadados_id                      INT           NOT NULL,

  snap_nome                         VARCHAR(180)  NOT NULL,
  snap_codigo_empresa               VARCHAR(20)   NOT NULL,
  snap_empresa                      VARCHAR(180)  NULL,
  snap_codigo_unidade               VARCHAR(20)   NOT NULL,
  snap_unidade                      VARCHAR(180)  NULL,
  snap_codigo_cargo                 VARCHAR(20)   NULL,
  snap_cargo                        VARCHAR(180)  NULL,
  snap_admissao                     DATE          NULL,
  snap_data_inicio_cargo            DATE          NULL,

  gestor_usuario_id                 INT           NOT NULL,
  gestor_nome_snapshot              VARCHAR(180)  NOT NULL,

  origem_tipo                       VARCHAR(30)   NOT NULL,
  origem_ref_tipo                   VARCHAR(40)   NULL,
  origem_ref_id                     INT           NULL,

  status                            VARCHAR(20)   NOT NULL DEFAULT 'rascunho',
  data_abertura                     DATE          NOT NULL,
  data_prevista_conclusao           DATE          NOT NULL,
  data_real_conclusao               DATE          NULL,

  pontos_fortes                     TEXT          NULL,
  oportunidades_desenvolvimento     TEXT          NULL,
  objetivo_esperado                 TEXT          NULL,

  momento_profissional              TEXT          NULL,
  pontos_desenvolver_colaborador    TEXT          NULL,

  evidencias_evolucao               TEXT          NULL,

  avaliacao_final                   VARCHAR(30)   NULL,
  comentarios_finais                TEXT          NULL,

  criado_por_usuario_id             INT           NOT NULL,
  criado_em                         DATETIME      NOT NULL,
  atualizado_em                     DATETIME      NOT NULL,

  KEY idx_pdis_contrato (metadados_id),
  KEY idx_pdis_gestor (gestor_usuario_id),
  KEY idx_pdis_status (status),
  KEY idx_pdis_prevista (data_prevista_conclusao),
  KEY idx_pdis_criado_por (criado_por_usuario_id),
  CONSTRAINT fk_pdis_contrato FOREIGN KEY (metadados_id) REFERENCES colaboradores_metadados(id) ON DELETE RESTRICT,
  CONSTRAINT fk_pdis_gestor FOREIGN KEY (gestor_usuario_id) REFERENCES usuarios(id),
  CONSTRAINT fk_pdis_criado_por FOREIGN KEY (criado_por_usuario_id) REFERENCES usuarios(id),
  CONSTRAINT chk_pdis_origem CHECK (origem_tipo IN ('avaliacao_experiencia','feedback','avaliacao_desempenho','desenvolvimento_carreira')),
  CONSTRAINT chk_pdis_status CHECK (status IN ('rascunho','nao_iniciado','em_andamento','concluido','cancelado')),
  CONSTRAINT chk_pdis_avaliacao CHECK (avaliacao_final IS NULL OR avaliacao_final IN ('nao_evoluiu','evoluiu_parcialmente','objetivo_atingido','superou_expectativa')),
  CONSTRAINT chk_pdis_prazo CHECK (data_prevista_conclusao >= data_abertura),
  CONSTRAINT chk_pdis_conclusao CHECK (
    (status = 'concluido' AND data_real_conclusao IS NOT NULL AND avaliacao_final IS NOT NULL)
    OR (status <> 'concluido' AND data_real_conclusao IS NULL AND avaliacao_final IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pdi_competencias (
  id                                INT AUTO_INCREMENT PRIMARY KEY,
  pdi_id                            INT           NOT NULL,
  competencia_texto                 VARCHAR(180)  NOT NULL,
  competencia_id                    INT           NULL,
  criado_em                         DATETIME      NOT NULL,
  KEY idx_pdi_competencias_pdi (pdi_id),
  CONSTRAINT fk_pdi_competencias_pdi FOREIGN KEY (pdi_id) REFERENCES pdis(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pdi_acoes (
  id                                INT AUTO_INCREMENT PRIMARY KEY,
  pdi_id                            INT           NOT NULL,
  ordem                             TINYINT       NOT NULL,
  descricao                         VARCHAR(500)  NOT NULL,
  responsavel_tipo                  VARCHAR(20)   NOT NULL,
  responsavel_usuario_id            INT           NULL,
  responsavel_nome_snapshot         VARCHAR(180)  NULL,
  prazo                             DATE          NOT NULL,
  status                            VARCHAR(20)   NOT NULL DEFAULT 'nao_iniciada',
  criado_em                         DATETIME      NOT NULL,
  atualizado_em                     DATETIME      NOT NULL,
  UNIQUE KEY uk_pdi_acoes_ordem (pdi_id, ordem),
  KEY idx_pdi_acoes_responsavel (responsavel_usuario_id),
  CONSTRAINT fk_pdi_acoes_pdi FOREIGN KEY (pdi_id) REFERENCES pdis(id) ON DELETE CASCADE,
  CONSTRAINT fk_pdi_acoes_responsavel FOREIGN KEY (responsavel_usuario_id) REFERENCES usuarios(id),
  CONSTRAINT chk_pdi_acoes_ordem CHECK (ordem BETWEEN 1 AND 3),
  CONSTRAINT chk_pdi_acoes_responsavel CHECK (responsavel_tipo IN ('colaborador','gestor','rh','outro')),
  CONSTRAINT chk_pdi_acoes_status CHECK (status IN ('nao_iniciada','em_andamento','concluida'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pdi_acompanhamentos (
  id                                INT AUTO_INCREMENT PRIMARY KEY,
  pdi_id                            INT           NOT NULL,
  autor_usuario_id                  INT           NOT NULL,
  autor_papel                       VARCHAR(30)   NOT NULL,
  registrado_em_nome_do_colaborador TINYINT(1)    NOT NULL DEFAULT 0,
  comentario                        TEXT          NOT NULL,
  criado_em                         DATETIME      NOT NULL,
  KEY idx_pdi_acompanhamentos_pdi (pdi_id, criado_em),
  KEY idx_pdi_acompanhamentos_autor (autor_usuario_id),
  CONSTRAINT fk_pdi_acompanhamentos_pdi FOREIGN KEY (pdi_id) REFERENCES pdis(id) ON DELETE RESTRICT,
  CONSTRAINT fk_pdi_acompanhamentos_autor FOREIGN KEY (autor_usuario_id) REFERENCES usuarios(id),
  CONSTRAINT chk_pdi_acompanhamentos_papel CHECK (autor_papel IN ('gestor','rh','admin','colaborador_assistido'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pdi_eventos (
  id                                INT AUTO_INCREMENT PRIMARY KEY,
  pdi_id                            INT           NOT NULL,
  tipo_evento                       VARCHAR(50)   NOT NULL,
  campo                             VARCHAR(60)   NULL,
  valor_anterior                    TEXT          NULL,
  valor_novo                        TEXT          NULL,
  ator_usuario_id                   INT           NOT NULL,
  ator_papel                        VARCHAR(20)   NOT NULL,
  registrado_em_nome_do_colaborador TINYINT(1)    NOT NULL DEFAULT 0,
  ip                                VARCHAR(45)   NULL,
  criado_em                         DATETIME      NOT NULL,
  KEY idx_pdi_eventos_pdi (pdi_id, criado_em),
  KEY idx_pdi_eventos_ator (ator_usuario_id),
  CONSTRAINT fk_pdi_eventos_pdi FOREIGN KEY (pdi_id) REFERENCES pdis(id) ON DELETE RESTRICT,
  CONSTRAINT fk_pdi_eventos_ator FOREIGN KEY (ator_usuario_id) REFERENCES usuarios(id),
  CONSTRAINT chk_pdi_eventos_papel CHECK (ator_papel IN ('gestor','rh','admin'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
