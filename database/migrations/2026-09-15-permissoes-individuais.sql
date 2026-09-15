-- Migration: 2026-09-15-permissoes-individuais.sql
-- Objetivo:
--   Sprint "Controle de Acesso por Permissões Individuais" — fundação. A fonte efetiva de
--   autorização funcional do Portal passa a ser a PERMISSÃO INDIVIDUAL atribuída ao usuário,
--   não mais um booleano novo por módulo em `usuarios`.
--
--   Cria:
--     * `permissoes`         -> catálogo de capacidades (código `modulo.acao`, ex.:
--                                `solicitacao_vaga.criar`, `kanban_vagas.movimentar`).
--     * `usuario_permissoes` -> relação N:N usuário <-> permissão concedida individualmente.
--
--   Regra de negócio (ver spec da sprint / docs/claude/roadmap-tecnico.md):
--     - Nenhuma coluna booleana nova em `usuarios` por módulo. `pode_solicitar_vaga` permanece
--       por compatibilidade temporária (não removida, não usada por módulos novos).
--     - Bypass de Admin fica centralizado em UM ÚNICO ponto (app/core/Authorization.php) — nunca
--       espalhado em `if role === admin` pelos módulos.
--     - RH não recebe acesso total só por `role = rh`; supervisor não recebe acesso total só por
--       `is_supervisor = 1`. Autorização funcional de módulos novos vem de permissão individual.
--
--   Compatibilidade: escrita para MySQL 8.4.x (dev) e MariaDB 11.8.9-log (produção).
--     - `CREATE TABLE IF NOT EXISTS` para as duas tabelas (idempotente nos dois bancos).
--     - Sem ENUM (código livre `modulo.acao`, validado na aplicação — permite adicionar módulos
--       futuros sem alterar o schema).
--     - Sem CHECK (MariaDB rejeita CHECK em coluna usada por FK com ação referencial — erro 3823,
--       já registrado em 2026-09-09-usuarios-dominio-vagas.sql).
--
--   Preserva 100% dos dados existentes. Nenhuma exclusão. Puramente estrutural + seed idempotente
--   (INSERT IGNORE) no arquivo `-seed.sql` — aplicado em separado, na mesma sessão de deploy.
--
--   Produção: MariaDB 11.8.9-log (u172743873_portalrh) — NÃO aplicada ainda. Aguardando aplicação
--   manual antes do push (ver processo da sprint).

CREATE TABLE IF NOT EXISTS permissoes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(80) NOT NULL,
  modulo VARCHAR(40) NOT NULL,
  nome VARCHAR(120) NOT NULL,
  descricao VARCHAR(255) NULL,
  ordem INT NOT NULL DEFAULT 0,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_permissoes_codigo (codigo),
  KEY idx_permissoes_modulo (modulo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS usuario_permissoes (
  usuario_id INT NOT NULL,
  permissao_id INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (usuario_id, permissao_id),
  KEY idx_usuario_permissoes_permissao (permissao_id),
  CONSTRAINT fk_usuario_permissoes_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  CONSTRAINT fk_usuario_permissoes_permissao FOREIGN KEY (permissao_id) REFERENCES permissoes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
