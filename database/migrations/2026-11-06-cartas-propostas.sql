-- Migration: 2026-11-06-cartas-propostas.sql
-- Objetivo:
--   Fase 1 da formalização da contratação (Carta Proposta, Aceite eletrônico e, nas fases
--   seguintes, Exame Admissional). Cada candidatura pode ter várias PROPOSTAS, uma por VERSÃO —
--   nunca UPDATE sobre uma proposta já emitida/enviada: uma alteração nas condições sempre gera
--   uma nova linha (nova versão), preservando integralmente o snapshot e o PDF da anterior para
--   auditoria. O aceite de uma versão nunca é reaproveitado por outra (token_hash é UNIQUE e
--   pertence a exatamente uma versão).
--
--   status:
--     rascunho    -> sendo editada pelo RH, ainda pode ser atualizada na mesma linha (sem gerar
--                    versão nova a cada edição).
--     gerada      -> PDF emitido, dados congelados (snapshot). Ainda NÃO enviada ao candidato.
--     enviada     -> RH confirmou o envio (ação explícita, SEPARADA de "mensagem preparada" —
--                    preparar o texto NUNCA marca como enviada por si só). O token já existe desde
--                    "gerada" + mensagem preparada (o texto copiável precisa trazer o link real),
--                    mas a página pública só HONRA o token quando status = 'enviada' — um token
--                    preparado e ainda não confirmado não dá acesso a ninguém.
--     aceita / recusada -> decisão do candidato, registrada com evidências.
--     substituida -> uma nova versão foi gerada antes desta ser decidida; o token desta versão
--                    para de ser aceito a partir daqui (ver CartaPropostaService::resolverToken()).
--
--   `validade_proposta` é a data-limite de aceite DEFINIDA PELO RH (regra de negócio, validada no
--   backend) — INDEPENDENTE de `token_expira_em` (prazo técnico do link, mesma defesa contra
--   vazamento/força bruta da Entrevista de Desligamento). Os dois são checados separadamente.
--
--   `comunicacao_id` liga a versão à mensagem preparada em `comunicacoes` (Sprint "Histórico de
--   Comunicação") — reaproveitado, nenhuma tabela de mensagens paralela criada aqui.
--
--   PDF em storage/cartas_propostas/ (fora do document root, .htaccess igual a storage/resumes),
--   nome gerado (nunca path previsível).
--
--   Idempotente (CREATE TABLE IF NOT EXISTS). Depende de `candidaturas`, `usuarios`, `comunicacoes`.
--   Rollback: 2026-11-06-cartas-propostas-rollback.sql.

CREATE TABLE IF NOT EXISTS cartas_propostas (
  id                        INT AUTO_INCREMENT PRIMARY KEY,
  candidatura_id            INT NOT NULL,
  versao                    INT NOT NULL,
  status                    ENUM('rascunho','gerada','enviada','aceita','recusada','substituida') NOT NULL DEFAULT 'rascunho',

  -- Snapshot das condições (preenchido o quanto possível a partir da candidatura/vaga/solicitação;
  -- sempre editável pelo RH até a emissão). Congelado a partir de status = 'gerada'.
  nome_candidato            VARCHAR(150) NOT NULL,
  cargo                     VARCHAR(150) NOT NULL,
  setor                     VARCHAR(150) NULL,
  empresa_contratante       VARCHAR(150) NOT NULL,
  salario_proposto          DECIMAL(12,2) NOT NULL,
  jornada_trabalho          VARCHAR(80) NOT NULL,
  escala_horario            VARCHAR(120) NULL,
  beneficios_texto          TEXT NULL,
  local_trabalho            VARCHAR(150) NULL,
  previsao_inicio           DATE NULL,
  validade_proposta         DATE NOT NULL,
  observacoes               TEXT NULL,

  pdf_nome_armazenado       VARCHAR(64) NULL,

  token_hash                CHAR(64) NULL,
  token_expira_em           DATETIME NULL,

  comunicacao_id            INT NULL,

  gerada_em                 DATETIME NULL,
  gerada_por_usuario_id     INT NOT NULL,
  enviada_em                DATETIME NULL,
  enviada_por_usuario_id    INT NULL,

  decisao_em                DATETIME NULL,
  decisao_ip                VARCHAR(45) NULL,
  decisao_user_agent        VARCHAR(255) NULL,
  motivo_recusa             TEXT NULL,

  created_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uk_cartas_propostas_candidatura_versao (candidatura_id, versao),
  UNIQUE KEY uk_cartas_propostas_token_hash (token_hash),
  KEY idx_cartas_propostas_candidatura (candidatura_id),
  KEY idx_cartas_propostas_status (status),
  CONSTRAINT fk_cartas_propostas_candidatura FOREIGN KEY (candidatura_id) REFERENCES candidaturas(id) ON DELETE CASCADE,
  CONSTRAINT fk_cartas_propostas_usuario_gerou FOREIGN KEY (gerada_por_usuario_id) REFERENCES usuarios(id),
  CONSTRAINT fk_cartas_propostas_usuario_enviou FOREIGN KEY (enviada_por_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT fk_cartas_propostas_comunicacao FOREIGN KEY (comunicacao_id) REFERENCES comunicacoes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
