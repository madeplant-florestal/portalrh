-- Rollback: 2026-09-11-solicitacoes-vaga-centro-custo-opcional.sql
--
--   Restaura `solicitacoes_vaga.centro_custo_id` para NOT NULL.
--
--   ATENÇÃO: só executa sem erro se NÃO houver nenhuma linha com `centro_custo_id IS NULL` no
--   momento (MySQL/MariaDB rejeitam o MODIFY para NOT NULL enquanto existir NULL). Em
--   desenvolvimento, remova/preencha as solicitações de teste com `centro_custo_id NULL` antes de
--   rodar este rollback. Nenhuma solicitação real de produção é afetada por esta migration (ela
--   nunca foi aplicada em produção).

ALTER TABLE solicitacoes_vaga
  MODIFY COLUMN centro_custo_id INT NOT NULL;
