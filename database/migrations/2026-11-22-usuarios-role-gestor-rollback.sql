-- Rollback: 2026-11-22-usuarios-role-gestor-rollback.sql
-- Desfaz a inclusao do perfil 'gestor' no ENUM de usuarios.role (volta a enum('admin','rh','viewer')).
--
-- *** ESTE ROLLBACK NUNCA CONVERTE CONTAS 'gestor' EM OUTRO PERFIL. ***
-- Se existir QUALQUER usuario com role = 'gestor', o rollback e BLOQUEADO e exige DECISAO ADMINISTRATIVA EXPLICITA: reclassificar cada
-- conta (rh ou viewer) pela tela de Usuarios, ou excluir/decidir sobre ela, ANTES de repetir este script. Nunca rebaixe em massa.
--
-- PASSO 1 - VERIFICACAO OBRIGATORIA (somente leitura). Se 'gestores' for maior que 0, PARE aqui:
--
--   SELECT COUNT(*) AS gestores FROM usuarios WHERE role = 'gestor';
--
-- PASSO 2 - TRAVA TECNICA: o modo estrito faz o ALTER FALHAR (sem alterar nada) se ainda houver linhas com 'gestor'. Sem ele, um
-- modo SQL permissivo converteria essas contas em role vazio ('') em silencio — o que este rollback proibe.
SET SESSION sql_mode = CONCAT_WS(',', @@sql_mode, 'STRICT_ALL_TABLES');

-- PASSO 3 - ALTER (mesmos charset/collation/NULL/DEFAULT da definicao original; ajuste se o PASSO 0 da migration mostrou diferenca)
ALTER TABLE usuarios
  MODIFY COLUMN role ENUM('admin','rh','viewer')
  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci
  NOT NULL DEFAULT 'viewer';

-- PASSO 4 - VERIFICACAO (somente leitura). Esperado: enum('admin','rh','viewer'), NO, viewer.
SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'role';

-- Se o codigo ja estiver publicado, ele volta sozinho ao modo sem 'gestor' (consulta o schema): nenhuma alteracao de codigo e necessaria.
