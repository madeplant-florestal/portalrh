-- Migration: 2026-08-27-colaboradores-metadados-salario-cargo.sql
-- Objetivo:
--   Fase 3.1 da integração com o METADADOS — amplia a tabela espelho
--   colaboradores_metadados com dois campos oficiais adicionais, validados contra o banco
--   real RHMADEPLANT (todos os 7 JOINs da extração já estavam 100% corretos; esta migration
--   não mexe em nenhum deles, só adiciona colunas novas ao SELECT).
--
--   salario_atual      <- RHCONTRATOS.SALARIOCONTRATUAL (salário-base oficial do contrato;
--                         SALARIOMES é numericamente idêntico em 100% dos 725 contratos
--                         preenchidos em RHMADEPLANT, mas SALARIOCONTRATUAL foi escolhido por
--                         precisão semântica — é o termo padrão de folha para "salário
--                         contratual registrado", nunca total recebido no mês).
--   data_inicio_cargo  <- RHCONTRATOS.DATAULTALTCARGO (data da última alteração de cargo;
--                         100% preenchido em RHMADEPLANT; nunca usar data_admissao como
--                         fallback — são conceitos diferentes quando há promoção/mudança de
--                         cargo após a admissão).
--
--   Puramente aditiva: nenhuma coluna existente é alterada, nenhuma linha é tocada além do
--   DEFAULT NULL natural do ALTER TABLE. Registros já sincronizados permanecem intactos.
--
--   Nota de compatibilidade: "ADD COLUMN IF NOT EXISTS"/"DROP COLUMN IF EXISTS" falham com erro
--   de sintaxe (1064) no MySQL 8.4.3 usado neste ambiente de desenvolvimento — testado
--   empiricamente antes de escrever esta migration. Por isso este arquivo usa ALTER TABLE puro,
--   sem a cláusula IF [NOT] EXISTS (diferente do padrão de 2026-06-23-colaboradores-required-
--   columns.sql, que assume suporte a essa cláusula). Como esta é a primeira vez que estas
--   colunas são adicionadas a colaboradores_metadados, ALTER TABLE puro é seguro; reexecutar
--   esta migration numa base onde ela já rodou vai falhar com "Duplicate column", o que é o
--   comportamento esperado (mesmo risco de qualquer ALTER TABLE não condicional).

ALTER TABLE colaboradores_metadados
  ADD COLUMN salario_atual DECIMAL(11,2) NULL AFTER centro_custo,
  ADD COLUMN data_inicio_cargo DATE NULL AFTER salario_atual;
