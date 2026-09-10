-- Migration: 2026-09-09-solicitacoes-vaga-gestor-opcional.sql
-- Objetivo:
--   Sprint "Usuários/Lideranças independentes de colaboradores" — torna
--   `solicitacoes_vaga.gestor_solicitante_colaborador_id` OPCIONAL.
--
--   A identidade canônica do solicitante já é `solicitante_usuario_id` (FK -> usuarios,
--   NOT NULL, sempre preenchida com o usuário autenticado). `gestor_solicitante_colaborador_id`
--   passa a ser apenas um contexto legado/opcional, preenchido só quando houver um colaborador
--   local correspondente escolhido explicitamente por RH/Admin. Um gestor PJ/terceiro cria
--   Solicitação de Vaga sem existir em `colaboradores` — daí a coluna precisar aceitar NULL.
--
--   Mudança de contrato de schema (aprovada explicitamente para esta sprint):
--     - INT NOT NULL           -> INT NULL
--     - FK ON DELETE RESTRICT  -> FK ON DELETE SET NULL
--
--   As solicitações existentes mantêm seus valores atuais — NENHUMA alteração retrospectiva
--   de dados é feita aqui (só DDL estrutural).
--
--   Produção: MariaDB 11.8.9-log (u172743873_portalrh) — aplicada e validada em 10/09/2026.
--   Pré-voo: 2 solicitações, ambas com gestor preenchido, 2 aprovações. Pós: coluna nullable,
--   FK ON DELETE SET NULL, 0 solicitações com gestor NULL, aprovações e status intactos.
--   Nenhuma instrução SQL abaixo foi alterada depois da aplicação em produção.

ALTER TABLE solicitacoes_vaga
  DROP FOREIGN KEY fk_solicitacoes_gestor_colaborador;

ALTER TABLE solicitacoes_vaga
  MODIFY COLUMN gestor_solicitante_colaborador_id INT NULL;

ALTER TABLE solicitacoes_vaga
  ADD CONSTRAINT fk_solicitacoes_gestor_colaborador
      FOREIGN KEY (gestor_solicitante_colaborador_id) REFERENCES colaboradores(id) ON DELETE SET NULL;
