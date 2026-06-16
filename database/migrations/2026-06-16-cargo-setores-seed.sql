-- Seed idempotente dos relacionamentos cargo_setores
-- Importante:
-- 1. Nao recria cargos nem setores
-- 2. Usa somente slugs existentes no cadastro atual
-- 3. Alguns nomes solicitados foram normalizados para a nomenclatura vigente:
--    - RH, DP e SST foram consolidados no setor rh-dp-sst
--    - FACILITIES foi consolidado no setor facilites
--    - Variantes senioridade/JI/JII/PI foram mapeadas para o cargo base existente
--    - Variantes sem cargo equivalente exato foram aproximadas pelo cargo base mais proximo ja cadastrado

CREATE TEMPORARY TABLE tmp_cargo_setores_seed (
    setor_slug VARCHAR(180) NOT NULL,
    cargo_slug VARCHAR(180) NOT NULL,
    origem_setor VARCHAR(180) NOT NULL,
    origem_cargo VARCHAR(180) NOT NULL,
    PRIMARY KEY (setor_slug, cargo_slug)
) ENGINE=Memory;

INSERT IGNORE INTO tmp_cargo_setores_seed (setor_slug, cargo_slug, origem_setor, origem_cargo) VALUES
    ('producao', 'analista-de-pcp', 'ADMINISTRATIVO', 'ANALISTA DE PCP SI'),
    ('producao', 'assistente-de-pcp', 'ADMINISTRATIVO', 'ASSISTENTE DE PCP'),
    ('producao', 'lider-de-operacao-florestal', 'ADMINISTRATIVO', 'LIDER DE OPERACAO FLORESTAL'),
    ('producao', 'supervisor-de-operacao', 'ADMINISTRATIVO', 'SUPERVISOR DE OPERACAO'),
    ('manutencao', 'supervisor-de-manutencao', 'ADMINISTRATIVO', 'SUPERVISOR DE MANUTENCAO'),

    ('producao', 'operador-de-maquina-florestal', 'OPERACAO', 'OPERADOR DE MAQUINAS FLORESTAIS'),
    ('producao', 'servicos-gerais', 'OPERACAO', 'SERVICOS GERAIS - AFIADOR'),
    ('logistica', 'motorista-de-carreta', 'OPERACAO', 'MOTORISTA DE CARRETA - PRANCHA'),

    ('manutencao', 'motorista-de-comboio', 'MANUTENCAO', 'MOTORISTA DE COMBOIO'),
    ('manutencao', 'soldador', 'MANUTENCAO', 'SOLDADOR J I'),
    ('manutencao', 'auxiliar-servicos-gerais', 'MANUTENCAO', 'AUXILIAR SERVICOS GERAIS - LAVADOR'),
    ('manutencao', 'mecanico', 'MANUTENCAO', 'MECANICO'),
    ('manutencao', 'frentista', 'MANUTENCAO', 'FRENTISTA'),
    ('manutencao', 'pintor-de-veiculos', 'MANUTENCAO', 'PINTOR DE VEICULOS'),
    ('manutencao', 'caldeireiro', 'MANUTENCAO', 'CALDEIREIRO'),
    ('manutencao', 'tecnico-mec-em-automacao', 'MANUTENCAO', 'TECNICO MEC. EM AUTOMACAO'),

    ('logistica', 'analista-de-logistica', 'LOGISTICA', 'ANALISTA DE LOGISTICA'),
    ('logistica', 'motorista-de-carreta', 'LOGISTICA', 'MOTORISTA DE CARRETA'),
    ('logistica', 'supervisor-de-frota', 'LOGISTICA', 'SUPERVISOR DE FROTA'),

    ('financeiro', 'assistente-financeiro', 'FINANCEIRO', 'ASSISTENTE FINANCEIRO'),
    ('financeiro', 'analista-financeiro', 'FINANCEIRO', 'ANALISTA FINANCEIRO'),

    ('rh-dp-sst', 'analista-de-rh', 'RECURSOS HUMANOS', 'ANALISTA DE RH P I'),
    ('rh-dp-sst', 'coordenador-de-rh', 'RECURSOS HUMANOS', 'COORDENADOR DE RH J I'),
    ('rh-dp-sst', 'analista-de-dp', 'DEPARTAMENTO PESSOAL', 'ANALISTA DE DP'),
    ('rh-dp-sst', 'tecnico-em-seguranca-do-trabalho', 'SEGURANCA DO TRABALHO', 'TECNICO SEGURANCA DO TRABALHO'),

    ('faturamento', 'encarregado-de-faturamento', 'FATURAMENTO', 'ENCARREGADO DE FATURAMENTO PI'),
    ('faturamento', 'analista-de-faturamento', 'FATURAMENTO', 'ANALISTA DE FATURAMENTO JI'),
    ('faturamento', 'assistente-de-faturamento', 'FATURAMENTO', 'ASSISTENTE DE FATURAMENTO JII'),

    ('suprimentos', 'analista-de-suprimentos', 'SUPRIMENTOS', 'ANALISTA DE SUPRIMENTOS'),
    ('suprimentos', 'almoxarife', 'SUPRIMENTOS', 'ALMOXARIFE JII'),
    ('suprimentos', 'assistente-de-almoxarifado', 'SUPRIMENTOS', 'ASSISTENTE DE ALMOXARIFADO J I'),
    ('suprimentos', 'supervisor-de-suprimentos', 'SUPRIMENTOS', 'SUPERVISOR DE SUPRIMENTOS'),

    ('contabilidade', 'assistente-contabil', 'CONTABILIDADE', 'ASSISTENTE CONTABIL'),
    ('contabilidade', 'contador', 'CONTABILIDADE', 'CONTADOR'),
    ('contabilidade', 'analista-de-controladoria', 'CONTABILIDADE', 'ANALISTA DE CONTROLADORIA'),

    ('fiscal', 'assistente-fiscal', 'FISCAL', 'ASSISTENTE FISCAL P I'),
    ('fiscal', 'encarregada-fiscal', 'FISCAL', 'ENCARREGADA FISCAL J III'),

    ('controladoria', 'analista-de-controladoria', 'CONTROLADORIA', 'ANALISTA DE CONTROLADORIA'),
    ('controladoria', 'controller', 'CONTROLADORIA', 'CONTROLLER'),

    ('facilites', 'encarregado-facilites', 'FACILITIES', 'ENCARREGADO FACILITES'),
    ('facilites', 'servicos-gerais', 'FACILITIES', 'SERVICOS GERAIS - LIMPEZA ADM'),
    ('facilites', 'assistente-administrativo', 'FACILITIES', 'ASSISTENTE ADMINISTRATIVO'),

    ('ti', 'encarregado-ti', 'TI', 'ENCARREGADO TI'),
    ('ti', 'supervisor-de-ti', 'TI', 'SUPERVISOR DE TI J I');

INSERT IGNORE INTO cargo_setores (cargo_id, setor_id)
SELECT c.id, s.id
FROM tmp_cargo_setores_seed seed
INNER JOIN setores s
    ON s.slug = seed.setor_slug
INNER JOIN cargos c
    ON c.slug = seed.cargo_slug;

DROP TEMPORARY TABLE IF EXISTS tmp_cargo_setores_seed;
