-- Migration: 2026-09-04-empresas-unidades-metadados.sql
-- Objetivo:
--   Fase 5.1A da integração com o METADADOS — Estrutura Organizacional (Estratégia B: o METADADOS
--   é o cadastro mestre oficial de Empresas/Unidades/Setores/Cargos; o Portal sincroniza e usa
--   essas dimensões em vez de manter cadastros mestres paralelos). Ver docs/claude/roadmap-tecnico.md.
--
--   Esta migration trata APENAS de Empresas e Unidades — as duas dimensões cuja chave oficial já
--   está confirmada (RHEMPRESAS.EMPRESA; RHUNIDADES.(EMPRESA, UNIDADE), com RAZAOSOCIAL/DESCRICAO40
--   como descrição). Setores, Cargos e Centros de Custo NÃO são tocados aqui: continuam no modelo
--   legado até a fase seguinte.
--
--   É puramente aditiva:
--     * `empresas` ganha 4 colunas nullable + 1 UNIQUE — o CRUD atual (CadastroOrganizacional,
--       que usa lista de colunas explícita em todo SELECT/INSERT/UPDATE) continua intacto.
--       `id`, `nome`, `slug`, `ativo` são MANTIDOS: `id` segue sendo a referência estável das FKs
--       existentes (vagas, setores, colaboradores, solicitacoes_vaga...), `nome`/`slug` seguem
--       sendo a identidade de exibição legada durante a transição. `razao_social` é o nome oficial
--       vindo do METADADOS; a identidade oficial é `codigo_empresa` (nunca o nome).
--     * `unidades` é criada nova. FK para empresas(id) ON DELETE RESTRICT (nunca perder uma
--       unidade silenciosamente porque a empresa foi removida — empresas na prática nunca são
--       removidas). Chave oficial (codigo_empresa, codigo_unidade) — NUNCA codigo_unidade isolado.
--
--   Nenhuma FK nova aponta para `unidades` nesta fase. Nenhuma tela passa a consumir `unidades`
--   ainda — é fundação de dados.
--
--   Compatibilidade: MySQL 8.4.3 não aceita `ADD COLUMN IF NOT EXISTS` (erro 1064) — colunas que
--   nunca existiram usam `ALTER TABLE` puro. `CREATE TABLE IF NOT EXISTS` é seguro.
--   NÃO aplicar em produção nesta execução — aplicação manual, revisada, depois.

ALTER TABLE empresas
  ADD COLUMN codigo_empresa    VARCHAR(20)  NULL AFTER id,
  ADD COLUMN razao_social      VARCHAR(180) NULL AFTER nome,
  ADD COLUMN origem_metadados  VARCHAR(60)  NULL AFTER ativo,
  ADD COLUMN sincronizado_em   DATETIME     NULL AFTER origem_metadados,
  ADD UNIQUE KEY uk_empresas_codigo (codigo_empresa);

CREATE TABLE IF NOT EXISTS unidades (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  empresa_id        INT          NOT NULL,
  codigo_empresa    VARCHAR(20)  NOT NULL,
  codigo_unidade    VARCHAR(20)  NOT NULL,
  descricao         VARCHAR(180) NOT NULL,
  ativo             TINYINT(1)   NOT NULL DEFAULT 1,
  origem_metadados  VARCHAR(60)  NULL,
  sincronizado_em   DATETIME     NULL,
  created_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_unidades_codigo (codigo_empresa, codigo_unidade),
  KEY idx_unidades_empresa (empresa_id),
  KEY idx_unidades_ativo (ativo),
  CONSTRAINT fk_unidades_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
