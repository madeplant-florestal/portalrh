-- Migration: 2026-09-08-usuario-colaboradores-pode-solicitar-vaga.sql
-- Objetivo:
--   Sprint operacional de Solicitação/Publicação de Vagas. Separa, no domínio do Portal, os dois
--   conceitos que hoje estão colapsados em `is_gestor`:
--     - `is_gestor`            = "é líder" (já existe; usado para roteamento de aprovação e escopo
--                                de setor na solicitação — INTOCADO).
--     - `pode_solicitar_vaga`  = autorização EXPLÍCITA e administrada no Portal para abrir
--                                Solicitação de Vaga. Nunca inferida por cargo/nome.
--
--   Regra arquitetural: o METADADOS é fonte cadastral; quem pode solicitar vaga é regra do Portal.
--
--   Backfill: as linhas que hoje são `is_gestor = 1` recebem `pode_solicitar_vaga = 1` para
--   preservar exatamente o comportamento atual (hoje o gate de criação é `is_gestor = 1`). Só há
--   1 linha em produção — o backfill é conservador e não amplia acesso.
--
--   Puramente aditiva. `ALTER TABLE` puro (MySQL 8.4.3 não aceita ADD COLUMN IF NOT EXISTS).
--   `SolicitacaoVaga::ensureSchema()` também passa a garantir esta coluna em instalação nova
--   (padrão do projeto — não há runner de migrations, ver CLAUDE.md §3.7).
--   NÃO aplicar em produção nesta execução.

ALTER TABLE usuario_colaboradores
  ADD COLUMN pode_solicitar_vaga TINYINT(1) NOT NULL DEFAULT 0 AFTER is_rh;

UPDATE usuario_colaboradores SET pode_solicitar_vaga = 1 WHERE is_gestor = 1;
