-- Migration: 2026-09-19-pesquisa-integracao-qr.sql
-- Objetivo:
--   Novo fluxo COLETIVO da Pesquisa de Integração via QR Code único e reutilizável
--   (/integracao): o colaborador se identifica na página pública por CPF + Data de Nascimento,
--   o sistema localiza o CONTRATO ATIVO no espelho oficial (`colaboradores_metadados`) e a
--   resposta fica associada diretamente a esse contrato (`metadados_id`) e ao EVENTO de integração
--   (`integracao_data_relacionada`) — sem materializar nada em `colaboradores` legado.
--   O fluxo individual existente (`/integracao/{token}`, geração pelo RH, tokens e respostas
--   antigas) continua funcionando integralmente: este migration é ADITIVO.
--
--   1) `sessoes_integracao` — a MENOR estrutura para saber "qual integração está aberta para
--      receber respostas pelo QR". Não é módulo de turmas/agenda/presença: só data da integração +
--      aberta/encerrada. `aberta` vale 1 (aberta) ou NULL (encerrada); `UNIQUE KEY (aberta)` garante
--      NO MÁXIMO UMA sessão aberta por vez (NULL não colide com NULL em MySQL/MariaDB) — proteção
--      no banco, não só na aplicação. A data dessa sessão é a que vira `integracao_data_relacionada`
--      das respostas (nunca a data da resposta — `respondida_em` continua sendo o instante real).
--
--   2) `pesquisas_integracao` (linhas históricas preservadas integralmente):
--      - `colaborador_id` passa a NULL-ável: o fluxo novo não tem colaborador local (e não cria
--        extensão em `colaboradores`). A FK e o UNIQUE (colaborador_id, integracao_data_relacionada)
--        continuam valendo para as linhas do fluxo individual (NULL não colide com NULL).
--      - `token_hash` passa a NULL-ável: o fluxo novo não tem token por pessoa (o UNIQUE de token
--        continua valendo para o fluxo individual).
--      - `metadados_id` (INT NULL): identidade OFICIAL do contrato (`colaboradores_metadados.id`),
--        o mesmo vínculo estrutural que `colaboradores.metadados_id` já usa. Sem FK: o espelho é
--        upsert-only (nunca há DELETE), e a sincronização não deve ser acoplada a esta tabela.
--      - `UNIQUE (metadados_id, integracao_data_relacionada)`: um contrato não responde duas vezes
--        à MESMA integração; uma integração futura do mesmo contrato NÃO fica impossível. Linhas
--        antigas têm metadados_id NULL e não são afetadas. A equivalência entre fluxo individual e
--        QR para linhas antigas (que só têm colaborador_id) é feita na aplicação, via
--        colaboradores.metadados_id, nunca por CPF.
--      - Nenhum CPF nem data de nascimento é persistido em `pesquisas_integracao`.
--
--   Compatibilidade: MySQL 8.4.x (dev) e MariaDB 11.8.9-log (produção). `ADD COLUMN IF NOT EXISTS`
--   não é usado (MySQL 8.4 não aceita) — o ALTER não é re-executável; o CREATE TABLE é idempotente.
--
--   Produção: NÃO aplicada ainda. Aplicação manual antes do push.

CREATE TABLE IF NOT EXISTS sessoes_integracao (
  id                      INT AUTO_INCREMENT PRIMARY KEY,
  data_integracao         DATE       NOT NULL,
  aberta                  TINYINT(1) NULL,
  criado_por_usuario_id   INT        NOT NULL,
  created_at              TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  encerrada_em            DATETIME   NULL,
  UNIQUE KEY uk_sessoes_integracao_aberta (aberta),
  KEY idx_sessoes_integracao_data (data_integracao),
  KEY idx_sessoes_integracao_usuario (criado_por_usuario_id),
  CONSTRAINT fk_sessoes_integracao_usuario FOREIGN KEY (criado_por_usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE pesquisas_integracao
  MODIFY colaborador_id INT NULL,
  MODIFY token_hash CHAR(64) NULL,
  ADD COLUMN metadados_id INT NULL AFTER colaborador_id,
  ADD UNIQUE KEY uk_pesquisas_integracao_contrato_evento (metadados_id, integracao_data_relacionada);
