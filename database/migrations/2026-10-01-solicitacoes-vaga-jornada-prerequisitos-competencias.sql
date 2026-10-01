-- Migration: 2026-10-01-solicitacoes-vaga-jornada-prerequisitos-competencias.sql
-- Objetivo:
--   Correções de RH na Solicitação de Vaga (Bloco 1, 2026-10):
--
--   1. jornadas_trabalho — cadastro novo (mesmo padrão minimalista de `competencias`/`beneficios`:
--      id/nome/slug/ativo/created_at), reaproveitado via CadastroOrganizacional/AdminCatalogosController
--      (mesma infraestrutura genérica de Empresas/Setores/Cargos — sem view nova). Substitui a
--      digitação livre de `solicitacoes_vaga.jornada_trabalho` (VARCHAR texto) por uma seleção
--      estruturada: `jornada_trabalho_id` (FK, NULLABLE — solicitações antigas não têm). A coluna
--      de texto ORIGINAL é preservada e continua sendo gravada (agora como SNAPSHOT do nome da
--      jornada escolhida no catálogo, no mesmo padrão de snapshot já usado em outras partes do
--      sistema) — nenhuma tela ou consulta existente que lê `jornada_trabalho` (texto) precisa
--      mudar, inclusive para registros antigos.
--
--   2. `escala_encrypted` — SEM alteração de schema. O campo some do formulário de criação (decisão
--      de negócio do RH), mas a coluna e os dados de solicitações antigas são preservados.
--
--   3. `pre_requisitos_encrypted` — campo novo, texto livre opcional (ex.: "CNH A/B, veículo
--      próprio, certificações"), mesmo padrão de `experiencia_necessaria_encrypted`.
--
--   4. `competencias_tecnicas_encrypted` / `competencias_comportamentais_encrypted` — campos novos,
--      texto livre opcional, substituem a seleção por catálogo (`solicitacao_vaga_competencias`)
--      APENAS no formulário de criação da Solicitação de Vaga. A tabela `competencias` e o pivot
--      `solicitacao_vaga_competencias` NÃO são alterados nem removidos — continuam existindo e
--      alimentando a exibição de solicitações antigas (histórico preservado); só deixam de receber
--      novas linhas a partir desta mudança.
--
--   Sem DROP de tabela/coluna, sem dado destrutivo. Idempotente (ADD COLUMN IF NOT EXISTS / CREATE
--   TABLE IF NOT EXISTS). NÃO aplicada em produção nesta rodada — ver CLAUDE.md §3.
--   Depende de `solicitacoes_vaga` (schema já existente). Rollback:
--   2026-10-01-solicitacoes-vaga-jornada-prerequisitos-competencias-rollback.sql.

CREATE TABLE IF NOT EXISTS jornadas_trabalho (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(120) NOT NULL,
  slug VARCHAR(160) NOT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_jornadas_trabalho_nome (nome),
  UNIQUE KEY uniq_jornadas_trabalho_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE solicitacoes_vaga ADD COLUMN IF NOT EXISTS jornada_trabalho_id INT NULL AFTER jornada_trabalho;
ALTER TABLE solicitacoes_vaga ADD COLUMN IF NOT EXISTS pre_requisitos_encrypted TEXT NULL AFTER experiencia_necessaria_encrypted;
ALTER TABLE solicitacoes_vaga ADD COLUMN IF NOT EXISTS competencias_tecnicas_encrypted TEXT NULL AFTER pre_requisitos_encrypted;
ALTER TABLE solicitacoes_vaga ADD COLUMN IF NOT EXISTS competencias_comportamentais_encrypted TEXT NULL AFTER competencias_tecnicas_encrypted;

ALTER TABLE solicitacoes_vaga ADD CONSTRAINT fk_solicitacoes_jornada_trabalho FOREIGN KEY (jornada_trabalho_id) REFERENCES jornadas_trabalho(id) ON DELETE RESTRICT;
