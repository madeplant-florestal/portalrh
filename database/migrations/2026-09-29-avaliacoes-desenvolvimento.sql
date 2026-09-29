-- Migration: 2026-09-29-avaliacoes-desenvolvimento.sql
-- Objetivo:
--   Etapa 4 — domínio "Avaliações e Desenvolvimento": Avaliação do Período de Experiência (45/90 dias) e
--   Feedback. Mesmo padrão arquitetural do PDI (2026-09-23-pdi.sql): vínculo por CONTRATO oficial
--   (`colaboradores_metadados.id` = `metadados_id`, nunca CPF/codigo_pessoa/`colaboradores` legado), gestor
--   explícito (`gestor_usuario_id` + snapshot do nome, nunca aprovador), SNAPSHOT do contexto na abertura
--   (sem CPF/salário) para preservar o histórico mesmo se cargo/setor/gestor mudarem depois, trilha de
--   auditoria e ciência COMPARTILHADAS entre os dois domínios (mesmo componente visual e de dados, §45 da
--   Etapa 4), preparação de campos conceituais para assinatura eletrônica futura (§46 — nenhum provedor
--   integrado, nenhum valor fictício).
--
--   PENDÊNCIAS 45/90 NÃO são pré-criadas: a lista de Próximas/Pendentes/Vencidas é DERIVADA em tempo de
--   consulta (contrato ativo + admissão) contra o que já existe em `avaliacoes_experiencia`
--   (AvaliacaoExperienciaService::derivarPendencias()) — só existe uma LINHA quando a avaliação é
--   efetivamente iniciada (rascunho) ou concluída (§25 da Etapa 4).
--
--   avaliacoes_experiencia          — cabeçalho da Avaliação de Período de Experiência (tipo 45 ou 90).
--   avaliacoes_experiencia_criterios — notas 1–5 dos critérios dos 6 valores culturais (Respeito, Honestidade,
--                                      Lealdade, Ética, Ousadia, Coragem — 4 critérios cada); os TEXTOS dos
--                                      critérios são listas fechadas no Service (AvaliacaoExperienciaService::
--                                      VALORES_CULTURAIS), não duplicados no banco.
--   feedbacks                       — cabeçalho do Formulário de Feedback e Desenvolvimento.
--   feedback_valores                — avaliação (Atende / Desenvolvimento Necessário) + comentário por valor
--                                      cultural; comentário obrigatório quando Desenvolvimento Necessário
--                                      (CHECK no banco + validado de qualquer forma no Service, §34/§63).
--   avaliacoes_desenvolvimento_eventos — trilha de auditoria APPEND-ONLY, compartilhada pelos dois domínios
--                                      (`documento_tipo` + `documento_id`, SEM FK — mesma convenção de
--                                      `pdis.origem_ref_id`, para não amarrar a duas FKs opcionais).
--   avaliacoes_desenvolvimento_ciencia — ciência (Gestor/RH/Colaborador) + preparação para assinatura futura,
--                                      compartilhada pelos dois domínios, mesma convenção sem FK do documento.
--
--   Regras no banco (CHECK — MySQL ≥ 8.0.16 / MariaDB ≥ 10.2.1; o Service valida de qualquer forma, nunca
--   confia só no banco nem só no frontend). Sem anexos, sem assinatura real, sem notificações.
--   Depende de `colaboradores_metadados` e `usuarios`. Rollback: 2026-09-29-avaliacoes-desenvolvimento-rollback.sql.

CREATE TABLE IF NOT EXISTS avaliacoes_experiencia (
  id                                INT AUTO_INCREMENT PRIMARY KEY,
  metadados_id                      INT           NOT NULL,
  tipo                              VARCHAR(2)    NOT NULL,

  snap_nome                         VARCHAR(180)  NOT NULL,
  snap_codigo_empresa               VARCHAR(20)   NOT NULL,
  snap_empresa                      VARCHAR(180)  NULL,
  snap_codigo_unidade               VARCHAR(20)   NOT NULL,
  snap_unidade                      VARCHAR(180)  NULL,
  snap_codigo_setor                 VARCHAR(20)   NULL,
  snap_setor                        VARCHAR(180)  NULL,
  snap_codigo_cargo                 VARCHAR(20)   NULL,
  snap_cargo                        VARCHAR(180)  NULL,
  snap_admissao                     DATE          NOT NULL,

  gestor_usuario_id                 INT           NOT NULL,
  gestor_nome_snapshot              VARCHAR(180)  NOT NULL,

  status                            VARCHAR(20)   NOT NULL DEFAULT 'rascunho',
  data_prevista                     DATE          NOT NULL,
  data_realizacao                   DATE          NULL,

  valores_comentario_respeito       TEXT          NULL,
  valores_comentario_honestidade    TEXT          NULL,
  valores_comentario_lealdade       TEXT          NULL,
  valores_comentario_etica          TEXT          NULL,
  valores_comentario_ousadia        TEXT          NULL,
  valores_comentario_coragem        TEXT          NULL,

  tecnica_capacidade                VARCHAR(20)   NULL,
  tecnica_comentarios                TEXT          NULL,

  adaptacao_nivel                   VARCHAR(20)   NULL,
  adaptacao_comentarios             TEXT          NULL,

  feedback_geral                    TEXT          NULL,

  parecer                           VARCHAR(40)   NULL,
  parecer_justificativa             TEXT          NULL,

  criado_por_usuario_id             INT           NOT NULL,
  criado_em                         DATETIME      NOT NULL,
  atualizado_em                     DATETIME      NOT NULL,

  UNIQUE KEY uk_avexp_contrato_tipo (metadados_id, tipo),
  KEY idx_avexp_gestor (gestor_usuario_id),
  KEY idx_avexp_status (status),
  KEY idx_avexp_prevista (data_prevista),
  CONSTRAINT fk_avexp_contrato FOREIGN KEY (metadados_id) REFERENCES colaboradores_metadados(id) ON DELETE RESTRICT,
  CONSTRAINT fk_avexp_gestor FOREIGN KEY (gestor_usuario_id) REFERENCES usuarios(id),
  CONSTRAINT fk_avexp_criado_por FOREIGN KEY (criado_por_usuario_id) REFERENCES usuarios(id),
  CONSTRAINT chk_avexp_tipo CHECK (tipo IN ('45','90')),
  CONSTRAINT chk_avexp_status CHECK (status IN ('rascunho','concluido','cancelado')),
  CONSTRAINT chk_avexp_tecnica CHECK (tecnica_capacidade IS NULL OR tecnica_capacidade IN ('sim','parcialmente','nao')),
  CONSTRAINT chk_avexp_adaptacao CHECK (adaptacao_nivel IS NULL OR adaptacao_nivel IN ('excelente','boa','regular','insatisfatoria')),
  CONSTRAINT chk_avexp_parecer CHECK (parecer IS NULL OR parecer IN ('apto_efetivacao','efetivacao_acompanhamento','prorrogacao_experiencia','nao_recomendado')),
  CONSTRAINT chk_avexp_justificativa CHECK (
    parecer IS NULL OR parecer = 'apto_efetivacao' OR (parecer_justificativa IS NOT NULL AND parecer_justificativa <> '')
  ),
  CONSTRAINT chk_avexp_conclusao CHECK (
    (status = 'concluido' AND data_realizacao IS NOT NULL AND parecer IS NOT NULL)
    OR (status <> 'concluido' AND data_realizacao IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS avaliacoes_experiencia_criterios (
  id                                INT AUTO_INCREMENT PRIMARY KEY,
  avaliacao_id                      INT           NOT NULL,
  valor                             VARCHAR(20)   NOT NULL,
  criterio_indice                   TINYINT       NOT NULL,
  nota                              TINYINT       NULL,
  UNIQUE KEY uk_avexp_criterios (avaliacao_id, valor, criterio_indice),
  CONSTRAINT fk_avexp_criterios_avaliacao FOREIGN KEY (avaliacao_id) REFERENCES avaliacoes_experiencia(id) ON DELETE CASCADE,
  CONSTRAINT chk_avexp_criterios_valor CHECK (valor IN ('respeito','honestidade','lealdade','etica','ousadia','coragem')),
  CONSTRAINT chk_avexp_criterios_indice CHECK (criterio_indice BETWEEN 1 AND 4),
  CONSTRAINT chk_avexp_criterios_nota CHECK (nota IS NULL OR nota BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS feedbacks (
  id                                INT AUTO_INCREMENT PRIMARY KEY,
  metadados_id                      INT           NOT NULL,

  snap_nome                         VARCHAR(180)  NOT NULL,
  snap_codigo_empresa               VARCHAR(20)   NOT NULL,
  snap_empresa                      VARCHAR(180)  NULL,
  snap_codigo_unidade               VARCHAR(20)   NOT NULL,
  snap_unidade                      VARCHAR(180)  NULL,
  snap_codigo_setor                 VARCHAR(20)   NULL,
  snap_setor                        VARCHAR(180)  NULL,
  snap_codigo_cargo                 VARCHAR(20)   NULL,
  snap_cargo                        VARCHAR(180)  NULL,

  gestor_usuario_id                 INT           NOT NULL,
  gestor_nome_snapshot              VARCHAR(180)  NOT NULL,

  tipo                              VARCHAR(20)   NOT NULL,
  data_feedback                     DATE          NOT NULL,
  status                            VARCHAR(20)   NOT NULL DEFAULT 'rascunho',

  pontos_fortes                     TEXT          NULL,
  pontos_desenvolvimento            TEXT          NULL,
  proximos_passos                   TEXT          NULL,

  espaco_colaborador                TEXT          NULL,
  espaco_colaborador_preenchido_em  DATETIME      NULL,

  resultado_geral                   VARCHAR(40)   NULL,
  observacoes_finais                TEXT          NULL,

  criado_por_usuario_id             INT           NOT NULL,
  criado_em                         DATETIME      NOT NULL,
  atualizado_em                     DATETIME      NOT NULL,
  concluido_em                      DATETIME      NULL,

  KEY idx_feedbacks_contrato (metadados_id),
  KEY idx_feedbacks_gestor (gestor_usuario_id),
  KEY idx_feedbacks_status (status),
  CONSTRAINT fk_feedbacks_contrato FOREIGN KEY (metadados_id) REFERENCES colaboradores_metadados(id) ON DELETE RESTRICT,
  CONSTRAINT fk_feedbacks_gestor FOREIGN KEY (gestor_usuario_id) REFERENCES usuarios(id),
  CONSTRAINT fk_feedbacks_criado_por FOREIGN KEY (criado_por_usuario_id) REFERENCES usuarios(id),
  CONSTRAINT chk_feedbacks_tipo CHECK (tipo IN ('reconhecimento','desenvolvimento','alinhamento','acompanhamento')),
  CONSTRAINT chk_feedbacks_status CHECK (status IN ('rascunho','concluido','cancelado')),
  CONSTRAINT chk_feedbacks_resultado CHECK (resultado_geral IS NULL OR resultado_geral IN ('reconhecido_alinhado','em_desenvolvimento','necessita_acompanhamento')),
  CONSTRAINT chk_feedbacks_conclusao CHECK (
    (status = 'concluido' AND concluido_em IS NOT NULL AND resultado_geral IS NOT NULL)
    OR (status <> 'concluido' AND concluido_em IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS feedback_valores (
  id                                INT AUTO_INCREMENT PRIMARY KEY,
  feedback_id                       INT           NOT NULL,
  valor                             VARCHAR(20)   NOT NULL,
  avaliacao                         VARCHAR(30)   NULL,
  comentario                        TEXT          NULL,
  UNIQUE KEY uk_feedback_valores (feedback_id, valor),
  CONSTRAINT fk_feedback_valores_feedback FOREIGN KEY (feedback_id) REFERENCES feedbacks(id) ON DELETE CASCADE,
  CONSTRAINT chk_feedback_valores_valor CHECK (valor IN ('respeito','honestidade','lealdade','etica','ousadia','coragem')),
  CONSTRAINT chk_feedback_valores_avaliacao CHECK (avaliacao IS NULL OR avaliacao IN ('atende','desenvolvimento_necessario')),
  CONSTRAINT chk_feedback_valores_comentario CHECK (
    avaliacao IS NULL OR avaliacao <> 'desenvolvimento_necessario' OR (comentario IS NOT NULL AND comentario <> '')
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS avaliacoes_desenvolvimento_eventos (
  id                                INT AUTO_INCREMENT PRIMARY KEY,
  documento_tipo                    VARCHAR(30)   NOT NULL,
  documento_id                      INT           NOT NULL,
  tipo_evento                       VARCHAR(50)   NOT NULL,
  campo                             VARCHAR(60)   NULL,
  valor_anterior                    TEXT          NULL,
  valor_novo                        TEXT          NULL,
  ator_usuario_id                   INT           NOT NULL,
  ator_papel                        VARCHAR(20)   NOT NULL,
  ip                                VARCHAR(45)   NULL,
  criado_em                         DATETIME      NOT NULL,
  KEY idx_avdesenv_eventos_doc (documento_tipo, documento_id, criado_em),
  KEY idx_avdesenv_eventos_ator (ator_usuario_id),
  CONSTRAINT fk_avdesenv_eventos_ator FOREIGN KEY (ator_usuario_id) REFERENCES usuarios(id),
  CONSTRAINT chk_avdesenv_eventos_tipo_doc CHECK (documento_tipo IN ('avaliacao_experiencia','feedback')),
  CONSTRAINT chk_avdesenv_eventos_papel CHECK (ator_papel IN ('gestor','rh','admin'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS avaliacoes_desenvolvimento_ciencia (
  id                                INT AUTO_INCREMENT PRIMARY KEY,
  documento_tipo                    VARCHAR(30)   NOT NULL,
  documento_id                      INT           NOT NULL,
  papel                             VARCHAR(20)   NOT NULL,
  usuario_id                        INT           NULL,
  nome_snapshot                     VARCHAR(180)  NOT NULL,
  registrado_em                     DATETIME      NOT NULL,

  status_assinatura                 VARCHAR(20)   NOT NULL DEFAULT 'nao_solicitada',
  provedor_assinatura               VARCHAR(40)   NULL,
  documento_externo_id              VARCHAR(100)  NULL,
  assinatura_solicitada_em          DATETIME      NULL,
  assinatura_concluida_em           DATETIME      NULL,
  documento_assinado_url            VARCHAR(500)  NULL,
  documento_hash                    VARCHAR(128)  NULL,

  UNIQUE KEY uk_avdesenv_ciencia (documento_tipo, documento_id, papel),
  KEY idx_avdesenv_ciencia_usuario (usuario_id),
  CONSTRAINT fk_avdesenv_ciencia_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  CONSTRAINT chk_avdesenv_ciencia_tipo_doc CHECK (documento_tipo IN ('avaliacao_experiencia','feedback')),
  CONSTRAINT chk_avdesenv_ciencia_papel CHECK (papel IN ('gestor','rh','colaborador')),
  CONSTRAINT chk_avdesenv_ciencia_status_assinatura CHECK (status_assinatura IN ('nao_solicitada','aguardando','parcialmente_assinada','concluida','cancelada','erro'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
