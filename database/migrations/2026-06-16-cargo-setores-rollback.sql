-- Rollback controlado da seed de cargo_setores
-- Remove apenas os vinculos previstos na seed 2026-06-16-cargo-setores-seed.sql
-- Nao remove cargos nem setores
-- Nao remove a estrutura da tabela cargo_setores

CREATE TEMPORARY TABLE tmp_cargo_setores_seed (
    setor_slug VARCHAR(180) NOT NULL,
    cargo_slug VARCHAR(180) NOT NULL,
    PRIMARY KEY (setor_slug, cargo_slug)
) ENGINE=Memory;

INSERT IGNORE INTO tmp_cargo_setores_seed (setor_slug, cargo_slug) VALUES
    ('producao', 'analista-de-pcp'),
    ('producao', 'assistente-de-pcp'),
    ('producao', 'lider-de-operacao-florestal'),
    ('producao', 'supervisor-de-operacao'),
    ('manutencao', 'supervisor-de-manutencao'),

    ('producao', 'operador-de-maquina-florestal'),
    ('producao', 'servicos-gerais'),
    ('logistica', 'motorista-de-carreta'),

    ('manutencao', 'motorista-de-comboio'),
    ('manutencao', 'soldador'),
    ('manutencao', 'auxiliar-servicos-gerais'),
    ('manutencao', 'mecanico'),
    ('manutencao', 'frentista'),
    ('manutencao', 'pintor-de-veiculos'),
    ('manutencao', 'caldeireiro'),
    ('manutencao', 'tecnico-mec-em-automacao'),

    ('logistica', 'analista-de-logistica'),
    ('logistica', 'supervisor-de-frota'),

    ('financeiro', 'assistente-financeiro'),
    ('financeiro', 'analista-financeiro'),

    ('rh-dp-sst', 'analista-de-rh'),
    ('rh-dp-sst', 'coordenador-de-rh'),
    ('rh-dp-sst', 'analista-de-dp'),
    ('rh-dp-sst', 'tecnico-em-seguranca-do-trabalho'),

    ('faturamento', 'encarregado-de-faturamento'),
    ('faturamento', 'analista-de-faturamento'),
    ('faturamento', 'assistente-de-faturamento'),

    ('suprimentos', 'analista-de-suprimentos'),
    ('suprimentos', 'almoxarife'),
    ('suprimentos', 'assistente-de-almoxarifado'),
    ('suprimentos', 'supervisor-de-suprimentos'),

    ('contabilidade', 'assistente-contabil'),
    ('contabilidade', 'contador'),
    ('contabilidade', 'analista-de-controladoria'),

    ('fiscal', 'assistente-fiscal'),
    ('fiscal', 'encarregada-fiscal'),

    ('controladoria', 'analista-de-controladoria'),
    ('controladoria', 'controller'),

    ('facilites', 'encarregado-facilites'),
    ('facilites', 'servicos-gerais'),
    ('facilites', 'assistente-administrativo'),

    ('ti', 'encarregado-ti'),
    ('ti', 'supervisor-de-ti');

DELETE cs
FROM cargo_setores cs
INNER JOIN cargos c
    ON c.id = cs.cargo_id
INNER JOIN setores s
    ON s.id = cs.setor_id
INNER JOIN tmp_cargo_setores_seed seed
    ON seed.cargo_slug = c.slug
   AND seed.setor_slug = s.slug;

DROP TEMPORARY TABLE IF EXISTS tmp_cargo_setores_seed;
