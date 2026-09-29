-- Rollback: 2026-09-29-avaliacoes-desenvolvimento-rollback.sql
-- Reverte 2026-09-29-avaliacoes-desenvolvimento.sql. Ordem respeita as FKs: filhos antes dos pais.
-- DESTRUTIVO — apaga todos os dados de Avaliação de Experiência e Feedback já registrados. Confirmar
-- explicitamente com o Fabio antes de rodar, mesmo em dev (regra 6 do CLAUDE.md).

DROP TABLE IF EXISTS avaliacoes_desenvolvimento_ciencia;
DROP TABLE IF EXISTS avaliacoes_desenvolvimento_eventos;
DROP TABLE IF EXISTS feedback_valores;
DROP TABLE IF EXISTS feedbacks;
DROP TABLE IF EXISTS avaliacoes_experiencia_criterios;
DROP TABLE IF EXISTS avaliacoes_experiencia;
