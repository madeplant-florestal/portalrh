-- Migration: 2026-09-24-usuarios-gestor-imediato.sql
-- Objetivo:
--   Gestor Imediato como relação PRÓPRIA do Portal: `usuarios.gestor_usuario_id -> usuarios.id`.
--
--   Semântica: o usuário apontado é o Gestor Imediato daquele usuário nos fluxos atuais e futuros do Portal (hierarquia
--   operacional). É INDEPENDENTE de `usuarios.aprovador_usuario_id` (aprovador da Solicitação de Vaga) e NÃO aponta para
--   nenhuma estrutura legada (`colaboradores`, `usuario_colaboradores`, `lider_colaborador_id`, `is_gestor`). Nenhum dado é
--   copiado ou migrado: a coluna nasce toda NULL ("sem gestor definido").
--
--   - nullable (usuário sem gestor é permitido: Direção, técnico, RH...);
--   - índice `idx_usuarios_gestor` (consultar liderados diretos);
--   - FK `fk_usuarios_gestor` com ON DELETE SET NULL — mesma política de `fk_usuarios_aprovador`: apagar um gestor NUNCA
--     apaga os subordinados, só zera o vínculo;
--   - autorreferência e ciclos (A→B→A, A→B→C→A) são barrados na camada de negócio (UsuarioGestorService).
--
--   Idempotente e portável (MariaDB e MySQL): cada passo só roda se ainda não existir (INFORMATION_SCHEMA + PREPARE).
--   Sem seed. Rollback: 2026-09-24-usuarios-gestor-imediato-rollback.sql.

SET @col_existe := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'gestor_usuario_id'
);
SET @sql := IF(@col_existe = 0, 'ALTER TABLE usuarios ADD COLUMN gestor_usuario_id INT NULL AFTER aprovador_usuario_id', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_existe := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND INDEX_NAME = 'idx_usuarios_gestor'
);
SET @sql := IF(@idx_existe = 0, 'ALTER TABLE usuarios ADD INDEX idx_usuarios_gestor (gestor_usuario_id)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_existe := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME = 'fk_usuarios_gestor'
);
SET @sql := IF(@fk_existe = 0, 'ALTER TABLE usuarios ADD CONSTRAINT fk_usuarios_gestor FOREIGN KEY (gestor_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
