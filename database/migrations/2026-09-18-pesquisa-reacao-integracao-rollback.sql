-- Rollback: 2026-09-18-pesquisa-reacao-integracao-rollback.sql
-- Reverte 2026-09-18-pesquisa-reacao-integracao.sql — DROP na ordem inversa da criação (filha
-- antes da mãe, por causa da FK). DESTRUTIVO: apaga todas as campanhas e respostas já coletadas.
-- Só executar com aprovação explícita e separada (regra 6 do CLAUDE.md), nunca como parte de rotina.

DROP TABLE IF EXISTS respostas_pesquisa_reacao_integracao;
DROP TABLE IF EXISTS campanhas_pesquisa_reacao_integracao;
