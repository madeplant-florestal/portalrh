-- Migration: 2026-09-24-colaboradores-metadados-sexo.sql
-- Objetivo:
--   Diagnóstico "Transferências + Turnover por Sexo/Gênero" — traz para o espelho
--   `colaboradores_metadados` o campo oficial RHPESSOAS.SEXO (RHMADEPLANT), confirmado como
--   CHAR(1) NOT NULL na origem, só com os valores M/F (867/867 pessoas, cobertura 100% em todos
--   os 731 contratos testados). O JOIN necessário (RHPESSOAS por EMPRESA+PESSOA) já existe em
--   MetadadosSyncService::QUERY — esta mudança só amplia o SELECT com `pes.SEXO AS sexo`.
--
--   Nome da coluna é `sexo` (não `genero`), de propósito: a fonte técnica oficial se chama SEXO
--   (sexo cadastral) — a decisão sobre o rótulo exibido na tela ("Turnover por Sexo" ou "por
--   Gênero") ainda depende do RH e não deve vazar para o nome da coluna/camada de dados.
--
--   NULLable no Portal (diferente de NOT NULL na origem): registros já sincronizados antes desta
--   migration ficam com `sexo = NULL` até o próximo sync completo repovoar o campo; o valor bruto
--   recebido (M/F) é guardado sem normalização adicional — nenhuma tradução, nenhuma tabela de
--   domínio, nenhum ENUM (só 2 códigos oficiais, não justifica).
--
--   Idempotente e portável (MariaDB 11.8 em produção e MySQL 8.4 em desenvolvimento): roda só se a
--   coluna ainda não existir (INFORMATION_SCHEMA + PREPARE/EXECUTE — mesmo padrão já usado em
--   2026-09-24-usuarios-gestor-imediato.sql). Deliberadamente NÃO usa `ADD COLUMN IF NOT EXISTS`:
--   já confirmado empiricamente que o MySQL 8.4.3 deste ambiente rejeita essa cláusula com erro de
--   sintaxe 1064 (ver migrations anteriores de colaboradores_metadados) — sem garantia de
--   comportamento idêntico entre MySQL 8.4 e MariaDB 11.8, a via segura é a checagem explícita.
--   NÃO aplicar em produção nesta execução.

SET @col_existe := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colaboradores_metadados' AND COLUMN_NAME = 'sexo'
);
SET @sql := IF(@col_existe = 0, 'ALTER TABLE colaboradores_metadados ADD COLUMN sexo CHAR(1) NULL AFTER nascimento', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
