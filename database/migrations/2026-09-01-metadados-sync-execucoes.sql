-- Migration: 2026-09-01-metadados-sync-execucoes.sql
-- Objetivo:
--   Sincronização operacional do METADADOS — Etapa 1 (camada Portal RH). Cria o histórico
--   operacional das sincronizações RECEBIDAS pelo Portal (POST /internal/metadados/colaboradores/
--   sync), para que o Dashboard de Indicadores possa mostrar "Última atualização: DD/MM/AAAA às
--   HH:mm", acompanhar o resultado da última sincronização e, futuramente, disparar uma
--   sincronização sob demanda através de uma camada de orquestração interna (n8n) — nunca
--   acessando o SQL Server diretamente.
--
--   Uma linha por SINCRONIZAÇÃO (lote), não por contrato. É puramente aditiva: nenhuma tabela
--   existente é alterada, nenhuma FK nova aponta para colaboradores/colaboradores_metadados.
--   A única FK é para usuarios(id) (quem solicitou a sincronização manual), ON DELETE SET NULL.
--
--   NUNCA armazena: segredo HMAC, senha, payload completo, CPF, nome individual, salário
--   individual ou qualquer credencial. `mensagem_tecnica` recebe apenas texto de exceção nossa,
--   já sanitizado por MetadadosSyncExecucaoRepository::sanitizarMensagem() (colapsa espaços,
--   remove sequências hexadecimais longas que poderiam ser segredo, trunca em 500).
--
--   Ciclo de status:
--     solicitada        -> criada pelo Dashboard ao acionar o orquestrador (só sync manual)
--     processando       -> reservado para uso futuro pelo orquestrador
--     sucesso           -> lote recebido e aplicado sem erro por linha
--     sucesso_com_erros -> lote aplicado, mas com N erros por linha (erros > 0)
--     falha             -> recusado após autenticação (ex.: conflito de origem) ou orquestrador indisponível
--     expirada          -> solicitada/processando que não concluiu dentro da janela esperada
--
--   correlacao_id (UUID) é gerado pelo Portal ao solicitar a sincronização manual e devolvido
--   pelo sender no envelope do lote, permitindo ao receiver fechar exatamente aquela solicitação.
--   NULL para sincronizações iniciadas fora do Portal (CLI, agendamento interno).

CREATE TABLE IF NOT EXISTS metadados_sync_execucoes (
  id                        INT AUTO_INCREMENT PRIMARY KEY,
  correlacao_id             CHAR(36)     NULL,
  gatilho                   VARCHAR(20)  NOT NULL DEFAULT 'desconhecido',
  solicitado_por_usuario_id INT          NULL,
  status                    VARCHAR(20)  NOT NULL,
  origem                    VARCHAR(60)  NULL,
  solicitado_em             DATETIME     NULL,
  iniciado_em               DATETIME     NULL,
  concluido_em              DATETIME     NULL,
  registros_recebidos       INT          NULL,
  inseridos                 INT          NULL,
  atualizados               INT          NULL,
  inalterados               INT          NULL,
  erros                     INT          NULL,
  hash_lote                 CHAR(64)     NULL,
  mensagem_tecnica          VARCHAR(500) NULL,
  created_at                TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mse_status (status),
  KEY idx_mse_correlacao (correlacao_id),
  KEY idx_mse_concluido (concluido_em),
  CONSTRAINT fk_mse_usuario FOREIGN KEY (solicitado_por_usuario_id)
    REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
