-- Migration: 2026-09-30-avaliacoes-desempenho.sql
-- Objetivo:
--   Avaliação de Desempenho NATIVA do domínio "Avaliações e Desenvolvimento" (Etapa 6, 2026-09).
--   Implementação limpa — NÃO evolui nem depende de `colaborador_avaliacoes` (legado, sobre
--   `colaboradores`/CPF em claro, sem gestor/snapshot/status/ciência). O legado continua existindo
--   só como referência histórica em `/admin/avaliacoes` (Cadastros), sem nenhuma relação de código
--   com esta tabela.
--
--   Mesmo padrão arquitetural de `avaliacoes_experiencia` (Etapa 4): vínculo por CONTRATO oficial
--   (`colaboradores_metadados.id` = `metadados_id`, nunca CPF/codigo_pessoa/`colaboradores` legado),
--   gestor explícito (`gestor_usuario_id` + snapshot do nome), SNAPSHOT do contexto na abertura
--   (nunca lookup dinâmico — corrige o problema real encontrado na auditoria do legado, onde o
--   cargo exibido numa avaliação antiga mudava se o colaborador fosse promovido depois).
--
--   avaliacoes_desempenho          — cabeçalho: ciclo/período (texto livre — sem catálogo formal de
--                                    ciclos nesta fase, mesma decisão pragmática já tomada no PDI
--                                    V1 para competências), status, resultado final EXPLÍCITO do
--                                    avaliador (nunca calculado a partir da média das notas — as
--                                    notas são só evidência de apoio ao julgamento), GAPs/plano de
--                                    ação como resumo textual (o GAP objetivo por critério é sempre
--                                    DERIVADO em tempo de leitura — nota_esperada − nota_atual —,
--                                    nunca persistido).
--   avaliacoes_desempenho_criterios — critérios/competências em TEXTO livre na V1 (`competencia_id`
--                                    nullable p/ catálogo futuro, sem FK — mesma convenção de
--                                    `pdi_competencias`), nota_atual/nota_esperada na escala 1–5
--                                    (limites e labels centralizados em
--                                    AvaliacaoDesempenhoService::ESCALA_MIN/MAX/LABELS — nunca magic
--                                    number solto em controller/view/repository).
--
--   Integração futura com PDI: `pdis.chk_pdis_origem` já aceita 'avaliacao_desempenho' desde a
--   Etapa 4 — nenhuma alteração necessária ali. Quando um PDI futuro nascer de uma Avaliação de
--   Desempenho, usa `origem_tipo='avaliacao_desempenho'`, `origem_ref_tipo='avaliacao_desempenho'`,
--   `origem_ref_id=avaliacoes_desempenho.id` (sem FK — mesma convenção já usada por Experiência/
--   Feedback). Nenhuma automação de criação de PDI nesta rodada.
--
--   Ciência e auditoria: ZERO tabelas novas — reaproveita `avaliacoes_desenvolvimento_ciencia`/
--   `avaliacoes_desenvolvimento_eventos` já existentes (ver migration companion
--   2026-09-30-avaliacoes-desenvolvimento-ciencia-eventos-tipo-desempenho.sql, que amplia o CHECK de
--   `documento_tipo` para aceitar 'avaliacao_desempenho').
--
--   Regras no banco (CHECK — MySQL ≥ 8.0.16 / MariaDB ≥ 10.2.1; o Service valida de qualquer forma).
--   Depende de `colaboradores_metadados` e `usuarios`. Rollback: 2026-09-30-avaliacoes-desempenho-rollback.sql.

CREATE TABLE IF NOT EXISTS avaliacoes_desempenho (
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
  snap_admissao                     DATE          NOT NULL,

  gestor_usuario_id                 INT           NOT NULL,
  gestor_nome_snapshot              VARCHAR(180)  NOT NULL,

  ciclo                             VARCHAR(80)   NOT NULL,
  periodo_inicio                    DATE          NOT NULL,
  periodo_fim                       DATE          NOT NULL,

  status                            VARCHAR(20)   NOT NULL DEFAULT 'rascunho',
  data_realizacao                   DATE          NULL,

  pontos_fortes                     TEXT          NULL,
  gaps_identificados                TEXT          NULL,
  plano_acao_sugerido               TEXT          NULL,

  resultado_final                   VARCHAR(30)   NULL,
  parecer_comentario                TEXT          NULL,

  criado_por_usuario_id             INT           NOT NULL,
  criado_em                         DATETIME      NOT NULL,
  atualizado_em                     DATETIME      NOT NULL,

  KEY idx_avdes_contrato (metadados_id),
  KEY idx_avdes_gestor (gestor_usuario_id),
  KEY idx_avdes_status (status),
  CONSTRAINT fk_avdes_contrato FOREIGN KEY (metadados_id) REFERENCES colaboradores_metadados(id) ON DELETE RESTRICT,
  CONSTRAINT fk_avdes_gestor FOREIGN KEY (gestor_usuario_id) REFERENCES usuarios(id),
  CONSTRAINT fk_avdes_criado_por FOREIGN KEY (criado_por_usuario_id) REFERENCES usuarios(id),
  CONSTRAINT chk_avdes_status CHECK (status IN ('rascunho','concluido','cancelado')),
  CONSTRAINT chk_avdes_resultado CHECK (resultado_final IS NULL OR resultado_final IN ('supera_expectativas','atende_expectativas','atende_parcialmente','nao_atende')),
  CONSTRAINT chk_avdes_prazo CHECK (periodo_fim >= periodo_inicio),
  CONSTRAINT chk_avdes_conclusao CHECK (
    (status = 'concluido' AND data_realizacao IS NOT NULL AND resultado_final IS NOT NULL)
    OR (status <> 'concluido' AND data_realizacao IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS avaliacoes_desempenho_criterios (
  id                                INT AUTO_INCREMENT PRIMARY KEY,
  avaliacao_id                      INT           NOT NULL,
  competencia_texto                 VARCHAR(180)  NOT NULL,
  competencia_id                    INT           NULL,
  nota_atual                        TINYINT       NULL,
  nota_esperada                     TINYINT       NULL,
  comentario                        TEXT          NULL,
  KEY idx_avdes_criterios_avaliacao (avaliacao_id),
  CONSTRAINT fk_avdes_criterios_avaliacao FOREIGN KEY (avaliacao_id) REFERENCES avaliacoes_desempenho(id) ON DELETE CASCADE,
  CONSTRAINT chk_avdes_criterios_nota_atual CHECK (nota_atual IS NULL OR nota_atual BETWEEN 1 AND 5),
  CONSTRAINT chk_avdes_criterios_nota_esperada CHECK (nota_esperada IS NULL OR nota_esperada BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
