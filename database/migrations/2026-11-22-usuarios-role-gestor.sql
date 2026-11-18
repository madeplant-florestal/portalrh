-- Migration: 2026-11-22-usuarios-role-gestor.sql
-- Etapa 2C.1 — inclui o perfil 'gestor' no ENUM de usuarios.role.
--
-- DEFINICAO REAL ANTES (inspecionada no ambiente de desenvolvimento e em database/schema.sql):
--   role ENUM('admin','rh','viewer') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'viewer'
-- DEPOIS:
--   role ENUM('admin','rh','viewer','gestor') ... NOT NULL DEFAULT 'viewer'      (mesmos charset/collation/NULL/DEFAULT)
-- O novo valor entra no FIM da lista: os indices numericos internos dos valores atuais nao mudam, nenhuma linha e reescrita
-- por conta do valor e NENHUM usuario muda de perfil. Nenhuma conta e criada ou promovida a 'gestor' por esta migration.
--
-- ORDEM SEGURA DE IMPLANTACAO (o codigo publicado ANTES desta migration continua funcionando com admin/rh/viewer: ele consulta
-- o schema e so oferece/aceita 'gestor' depois que o ENUM suportar o valor):
--   1. (opcional) publicar o codigo;  2. executar ESTE script no phpMyAdmin, com o banco u172743873_portalrh SELECIONADO;
--   3. conferir com o bloco de VERIFICACAO abaixo.
--
-- PASSO 0 - CONFERIR A DEFINICAO REAL EM PRODUCAO (somente leitura). Se COLLATION_NAME nao for utf8mb4_general_ci, ou houver
-- COLUMN_COMMENT, ajuste o ALTER do PASSO 1 para repetir EXATAMENTE esses atributos antes de executar:
--
--   SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_COMMENT
--   FROM INFORMATION_SCHEMA.COLUMNS
--   WHERE TABLE_SCHEMA = 'u172743873_portalrh' AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'role';
--
-- Compativel com MariaDB 11.8 e MySQL 8. Idempotente na pratica: reexecutar produz a mesma definicao.
-- Rollback: 2026-11-22-usuarios-role-gestor-rollback.sql (BLOQUEADO se existir qualquer conta 'gestor').

-- PASSO 1 - ALTER (acrescenta 'gestor' ao fim, preservando todo o resto)
ALTER TABLE usuarios
  MODIFY COLUMN role ENUM('admin','rh','viewer','gestor')
  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci
  NOT NULL DEFAULT 'viewer';

-- PASSO 2 - VERIFICACAO (somente leitura). Esperado: enum('admin','rh','viewer','gestor'), NO, viewer.
SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, CHARACTER_SET_NAME, COLLATION_NAME
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'role';

-- PASSO 3 - CONFERIR QUE NINGUEM MUDOU DE PERFIL (a contagem por perfil deve ser a mesma de antes; 'gestor' = 0).
SELECT role, COUNT(*) AS usuarios FROM usuarios GROUP BY role ORDER BY role;
