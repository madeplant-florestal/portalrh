-- Migration: 2026-08-27-colaboradores-metadados.sql
-- Objetivo:
--   Fase 1 da integração com o METADADOS (sistema oficial de RH/DP, base em SQL Server) —
--   ver auditoria registrada na conversa da sprint "Integração Portal RH com a base oficial
--   METADADOS". Cria a tabela espelho de LEITURA colaboradores_metadados, populada
--   exclusivamente por MetadadosSyncService/scripts/sync_metadados_colaboradores.php.
--
--   Esta tabela é desacoplada de propósito de `colaboradores` (tabela hub legada do Portal):
--   nenhuma FK nova é criada nesta fase, nenhuma tela ou indicador passa a lê-la ainda. É
--   puramente aditiva — a Fase 1 do plano de transição em 5 fases (consumir METADADOS + tabela
--   espelho; comparar; mapear vínculos; migrar telas; descontinuar cadastro duplicado).
--
--   Cada linha representa um CONTRATO (não uma pessoa) — uma pessoa readmitida aparece em
--   múltiplas linhas, cada uma com numero_contrato diferente. Isso é intencional e necessário
--   para turnover/histórico/tempo de casa (ver §7 da auditoria). Nunca fazer DELETE de vínculos
--   encerrados nesta tabela: a sincronização é upsert puro (insere o que não existe, atualiza o
--   que mudou, nunca remove).
--
--   Chave técnica de vínculo: (codigo_empresa, codigo_unidade, numero_contrato) — nunca CPF
--   isolado, porque uma pessoa pode ter múltiplos contratos ao longo do tempo.

CREATE TABLE IF NOT EXISTS colaboradores_metadados (
  id                          INT AUTO_INCREMENT PRIMARY KEY,
  identificador               VARCHAR(60)  NOT NULL,
  codigo_empresa              VARCHAR(20)  NOT NULL,
  codigo_unidade              VARCHAR(20)  NOT NULL,
  numero_contrato             VARCHAR(20)  NOT NULL,
  codigo_pessoa               VARCHAR(20)  NOT NULL,
  cpf                         VARCHAR(11)  NULL,
  nome                        VARCHAR(180) NOT NULL,
  empresa                     VARCHAR(180) NULL,
  nascimento                  DATE NULL,
  admissao                    DATE NULL,
  cargo                       VARCHAR(180) NULL,
  demissao                    DATE NULL,
  motivo_rescisao_codigo      VARCHAR(20)  NULL,
  motivo_rescisao_descricao   VARCHAR(180) NULL,
  unidade                     VARCHAR(180) NULL,
  setor                       VARCHAR(180) NULL,
  centro_custo                VARCHAR(180) NULL,
  ativo                       TINYINT(1) NULL,
  atualizado_em_origem        DATETIME NULL,
  sincronizado_em             TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at                  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_colaboradores_metadados_vinculo (codigo_empresa, codigo_unidade, numero_contrato),
  KEY idx_colaboradores_metadados_pessoa (codigo_pessoa),
  KEY idx_colaboradores_metadados_cpf (cpf),
  KEY idx_colaboradores_metadados_ativo (ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
