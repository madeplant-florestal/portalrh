-- Migration: 2026-09-16-integracao-colaborador.sql
-- Objetivo:
--   Sprint "Histórico de Comunicação + Experiência do Candidato + Integração do Colaborador".
--   Adiciona o controle operacional de INTEGRAÇÃO (onboarding) diretamente em `colaboradores` —
--   NÃO no METADADOS. `colaboradores` já é a extensão operacional LOCAL do Portal para dados que
--   não vêm do METADADOS como mestre (mesmo padrão de `salario_atual`/`data_admissao`/
--   `data_inicio_cargo`/`motivo_rescisao`, todos campos locais geridos por
--   Colaborador::updateRhData() — nunca sincronizados de volta para `colaboradores_metadados` nem
--   para o RHMADEPLANT). Nenhuma escrita no SQL Server METADADOS acontece por causa desta
--   migration ou do código que a usa.
--
--   `integracao_status`: domínio fechado e pequeno (Pendente/Realizada) -> ENUM, mesmo padrão de
--   `usuario_setores.origem`. `integracao_data`/`integracao_responsavel_usuario_id` ficam NULL
--   enquanto Pendente; a aplicação (Colaborador::updateIntegracao()) exige os dois quando o status
--   muda para Realizada — validação de negócio na aplicação, não em CHECK/trigger de banco.
--
--   `integracao_responsavel_usuario_id` referencia `usuarios.id` (nunca texto livre) — a tela
--   mostra o nome do usuário correspondente.
--
--   Compatibilidade: escrita para MySQL 8.4.x (dev) e MariaDB 11.8.9-log (produção).
--     - `ALTER TABLE ... ADD COLUMN` puro (sem `IF NOT EXISTS` — MySQL 8.4.3 não aceita nessa
--       sintaxe). Reaplicar retorna #1060/#1061 — sinal de "já aplicada", mesmo padrão das demais
--       migrations aditivas do projeto.
--
--   Preserva 100% dos dados existentes de `colaboradores`. Nenhuma exclusão, nenhum backfill.
--
--   Produção: MariaDB 11.8.9-log (u172743873_portalrh) — NÃO aplicada ainda. Aguardando aplicação
--   manual antes do push.

ALTER TABLE colaboradores
  ADD COLUMN integracao_status ENUM('pendente', 'realizada') NOT NULL DEFAULT 'pendente' AFTER motivo_rescisao,
  ADD COLUMN integracao_data DATE NULL AFTER integracao_status,
  ADD COLUMN integracao_responsavel_usuario_id INT NULL AFTER integracao_data,
  ADD KEY idx_colaboradores_integracao_responsavel (integracao_responsavel_usuario_id),
  ADD CONSTRAINT fk_colaboradores_integracao_responsavel FOREIGN KEY (integracao_responsavel_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL;
