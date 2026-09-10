-- Migration: 2026-09-08-setores-cargos-metadados.sql
-- Objetivo:
--   Fase 5.2 da integração com o METADADOS — Setores e Cargos como dimensões oficiais.
--   Identidade oficial CONFIRMADA por diagnóstico direto no SQL Server RHMADEPLANT (08/09/2026):
--     * RHSETORES.SETOR  VARCHAR(8) — chave GLOBAL (a tabela NÃO tem EMPRESA nem UNIDADE),
--       12 setores, 0 duplicidade, 0 código em contrato sem correspondência.
--     * RHCARGOS.CARGO   VARCHAR(8) — chave GLOBAL (idem), 184 cargos, 0 duplicidade, 0 órfão.
--     * Descrição oficial: DESCRICAO40 (VARCHAR(40)) em ambas.
--     * Situação oficial: coluna ATIVADESATIVADA CHAR(1) em ambas — a SEMÂNTICA dos valores
--       (ativo x desativado) ainda NÃO foi confirmada; por isso guardamos o valor BRUTO em
--       `situacao_metadados` e NÃO desativamos registros locais automaticamente nesta fase.
--
--   Os códigos são STRINGS OPACAS: preservados exatamente como o METADADOS entrega
--   (ex.: '0120'). Nunca convertidos para inteiro, nunca normalizados numericamente, nunca
--   têm zeros à esquerda removidos/adicionados. A identidade é o texto exato.
--
--   Puramente aditiva. As tabelas `setores` e `cargos` NÃO são recriadas; `id`, `nome`, `slug`,
--   `ativo`, `empresa_id` (legado do Portal, NÃO faz parte da identidade oficial — RHSETORES não
--   tem empresa) e todas as 12 FKs que apontam para `setores(id)`/`cargos(id)` (colaboradores,
--   movimentacoes_pessoal, solicitacoes_vaga, cargo_setores, centros_custo, cargo_beneficios,
--   cargo_faixas_salariais) permanecem intactas.
--
--   `setores.empresa_id` JÁ é NULLABLE no schema atual — nenhuma alteração necessária ali; um
--   setor oficial novo entra com `empresa_id = NULL` (nunca associado artificialmente a uma
--   empresa).
--
--   `metadados_sync_execucoes.dimensao` (VARCHAR(20), criada na Fase 5.1A) já aceita os valores
--   'setores'/'cargos' — nenhuma migration adicional.
--
--   Compatibilidade: escrita/testada em MySQL 8.4.3 (dev) — não aceita `ADD COLUMN IF NOT EXISTS`
--   (erro 1064), por isso `ALTER TABLE` puro para colunas que nunca existiram.
--
--   Produção: MariaDB 11.8.9-log (u172743873_portalrh) — aplicada em 10/09/2026.
--   Nenhuma instrução SQL abaixo foi alterada depois da aplicação.

ALTER TABLE setores
  ADD COLUMN codigo_setor        VARCHAR(8)  NULL AFTER id,
  ADD COLUMN descricao_oficial   VARCHAR(40) NULL AFTER nome,
  ADD COLUMN situacao_metadados  VARCHAR(10) NULL AFTER ativo,
  ADD COLUMN origem_metadados    VARCHAR(60) NULL AFTER situacao_metadados,
  ADD COLUMN sincronizado_em     DATETIME    NULL AFTER origem_metadados,
  ADD UNIQUE KEY uk_setores_codigo (codigo_setor);

ALTER TABLE cargos
  ADD COLUMN codigo_cargo        VARCHAR(8)  NULL AFTER id,
  ADD COLUMN descricao_oficial   VARCHAR(40) NULL AFTER nome,
  ADD COLUMN situacao_metadados  VARCHAR(10) NULL AFTER ativo,
  ADD COLUMN origem_metadados    VARCHAR(60) NULL AFTER situacao_metadados,
  ADD COLUMN sincronizado_em     DATETIME    NULL AFTER origem_metadados,
  ADD UNIQUE KEY uk_cargos_codigo (codigo_cargo);
