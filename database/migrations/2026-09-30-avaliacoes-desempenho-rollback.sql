-- Rollback: 2026-09-30-avaliacoes-desempenho-rollback.sql
-- Reverte 2026-09-30-avaliacoes-desempenho.sql. Ordem respeita as FKs: filhos antes dos pais.
-- DESTRUTIVO — apaga todas as Avaliações de Desempenho já registradas na base nova. Confirmar
-- explicitamente com o Fabio antes de rodar, mesmo em dev (regra 6 do CLAUDE.md).

DROP TABLE IF EXISTS avaliacoes_desempenho_criterios;
DROP TABLE IF EXISTS avaliacoes_desempenho;
