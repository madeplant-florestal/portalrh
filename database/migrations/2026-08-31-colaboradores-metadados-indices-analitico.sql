-- Migration: 2026-08-31-colaboradores-metadados-indices-analitico.sql
-- Objetivo:
--   Fase 4 (camada analítica de RH) — adiciona índices em colaboradores_metadados para as
--   consultas de headcount histórico/admissões/desligamentos, que filtram e ordenam por
--   admissao/demissao. Puramente aditivo, não destrutivo: só ADD INDEX, nenhuma coluna ou linha
--   é alterada. Com 733 contratos (volume atual) o ganho ainda não é observável, mas o histórico
--   cresce continuamente com cada sincronização — este índice evita degradação futura sem custo
--   de escrita relevante hoje.

ALTER TABLE colaboradores_metadados
  ADD INDEX idx_colaboradores_metadados_admissao (admissao),
  ADD INDEX idx_colaboradores_metadados_demissao (demissao);
