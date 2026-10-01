-- Rollback: 2026-09-30-pdi-origens-rollback.sql
-- Reverte 2026-09-30-pdi-origens.sql. DESTRUTIVO: apaga todos os vínculos PDI↔origem registrados
-- (os documentos originais — avaliações/feedbacks — e os próprios PDIs NÃO são afetados, só perde-se
-- a rastreabilidade adicional). Só rodar com aprovação explícita e separada.

DROP TABLE IF EXISTS pdi_origens;
