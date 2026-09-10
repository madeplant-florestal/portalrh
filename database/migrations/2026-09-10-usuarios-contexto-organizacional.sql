-- Migration: 2026-09-10-usuarios-contexto-organizacional.sql
-- Objetivo:
--   Sprint "Contexto Organizacional dos Usuários" — Etapa 1. Estrutura o contexto organizacional
--   de `usuarios`:
--     * `usuarios.cargo_id`  -> Cargo principal oficial do usuário (FK para o catálogo `cargos`).
--     * `usuario_setores`     -> relação N:N usuário <-> setores de atuação, com Setor principal e
--                                origem da associação (METADADOS x MANUAL).
--
--   Regra de negócio (ver docs/claude/roadmap-tecnico.md e a spec da sprint):
--     - Usuário COM vínculo `colaborador_metadados_id`: Cargo e Setor principal são herdados dos
--       códigos oficiais do contrato (`colaboradores_metadados.codigo_cargo` -> `cargos.codigo_cargo`,
--       `colaboradores_metadados.codigo_setor` -> `setores.codigo_setor`). Herança e trava ficam
--       na camada de aplicação (UsuarioContextoOrganizacionalService), não no banco.
--     - Usuário SEM vínculo: RH/Admin define Cargo e Setores manualmente, SOMENTE registros
--       oficiais (`codigo_cargo IS NOT NULL` / `codigo_setor IS NOT NULL`). Bloqueio de legado
--       também é responsabilidade da aplicação (sem trigger).
--     - No máximo 1 Setor principal por usuário — garantido no serviço, dentro da transação de
--       gravação (MySQL 8.4 e MariaDB 11.8 têm suporte divergente a índice único parcial, então
--       NÃO criamos índice parcial aqui).
--
--   Compatibilidade: escrita para MySQL 8.4.x (dev) e MariaDB 11.8.9-log (produção).
--     - `ALTER TABLE ... ADD COLUMN` puro (MySQL 8.4.3 não aceita `ADD COLUMN IF NOT EXISTS`,
--       erro 1064). Reaplicar retorna #1060/#1061 — sinal de "já aplicada", como as demais
--       migrations aditivas do projeto (ex.: 2026-09-09-usuarios-dominio-vagas.sql).
--     - `CREATE TABLE IF NOT EXISTS` para `usuario_setores` (idempotente nos dois bancos).
--     - `ENUM` e `FOREIGN KEY` são suportados por ambos. NÃO usamos CHECK (MariaDB rejeita CHECK
--       em coluna usada por FK com ação referencial — erro 3823, ver 2026-09-09-usuarios-dominio-vagas.sql).
--
--   Preserva 100% dos dados existentes. Nenhuma exclusão de usuário. Nenhum saneamento aqui.
--   Puramente estrutural: nenhum INSERT/UPDATE/DELETE, nenhum backfill.
--
--   Produção: MariaDB 11.8.9-log (u172743873_portalrh) — aplicada em 10/09/2026 (baseline 29
--   usuários, inalterado; `usuario_setores` criada vazia; nenhum usuário recebeu `cargo_id`).
--   Nenhuma instrução SQL abaixo foi alterada depois da aplicação.

ALTER TABLE usuarios
  ADD COLUMN cargo_id INT NULL AFTER colaborador_metadados_id,
  ADD KEY idx_usuarios_cargo (cargo_id),
  ADD CONSTRAINT fk_usuarios_cargo FOREIGN KEY (cargo_id) REFERENCES cargos(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS usuario_setores (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  setor_id INT NOT NULL,
  principal TINYINT(1) NOT NULL DEFAULT 0,
  origem ENUM('METADADOS', 'MANUAL') NOT NULL DEFAULT 'MANUAL',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_usuario_setores (usuario_id, setor_id),
  KEY idx_usuario_setores_usuario (usuario_id),
  KEY idx_usuario_setores_setor (setor_id),
  CONSTRAINT fk_usuario_setores_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  CONSTRAINT fk_usuario_setores_setor FOREIGN KEY (setor_id) REFERENCES setores(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
