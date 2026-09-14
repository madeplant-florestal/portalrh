-- Seed: 2026-09-14-cargo-setores-metadados-seed.sql
-- Requer 2026-09-14-cargo-setores-metadados.sql aplicada antes.
--
--   Carga INICIAL da matriz oficial Cargo x Setor — 53 combinações distintas observadas no
--   histórico completo (ativos + desligados) de RHCONTRATOS.CARGO + RHCONTRATOS.SETOR no
--   METADADOS (SQL Server RHMADEPLANT, consulta somente leitura em 11/09/2026). Identidade
--   exclusivamente por código oficial opaco (`codigo_cargo`/`codigo_setor`) — nunca por nome.
--   Não usa nenhuma informação de `cargo_setores` (tabela legada).
--
--   `origem_metadados = 'RHCONTRATOS'` (não um rótulo genérico) — documenta a proveniência real.
--   Quando a Madeplant passar a operar RHQUADROLOTCARGO, uma recarga futura substituirá estas
--   linhas com `origem_metadados = 'RHQUADROLOTCARGO'` (fonte de maior prioridade — não misturar
--   as duas simultaneamente). Esta migration não implementa esse pipeline de sincronização.
--
--   Idempotente: `INSERT IGNORE` sobre a PK composta (cargo_id, setor_id) — reaplicar não duplica.
--   Cargos/Setores que não existirem localmente (nunca deveria ocorrer — os 184/12 já estão
--   sincronizados pela Fase 5.2) simplesmente não geram linha (INNER JOIN), sem erro.
--
--   Compatível MySQL 8.4.x / MariaDB 11.8.9-log. NÃO aplicar em produção nesta etapa.

-- Collation explícita utf8mb4_general_ci (mesma de cargos.codigo_cargo/setores.codigo_setor) —
-- evita #1267 "Illegal mix of collations" independente da collation padrão do servidor/banco
-- (dívida técnica já registrada: MySQL 8.4 dev usa utf8mb4_0900_ai_ci por padrão, produção
-- MariaDB usa utf8mb4_uca1400_ai_ci; aqui coagimos a tabela temporária, não o catálogo oficial).
CREATE TEMPORARY TABLE tmp_cargo_setores_metadados_seed (
    codigo_cargo VARCHAR(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
    codigo_setor VARCHAR(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
    PRIMARY KEY (codigo_cargo, codigo_setor)
) ENGINE=Memory DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO tmp_cargo_setores_metadados_seed (codigo_cargo, codigo_setor) VALUES
    ('0030', '6'),
    ('0044', '6'),
    ('0136', '6'),
    ('0041', '6'),
    ('0024', '11'),
    ('0130', '11'),
    ('0001', '11'),
    ('0111', '11'),
    ('0153', '6'),
    ('0137', '6'),
    ('047', '5'),
    ('0152', '7'),
    ('0008', '7'),
    ('0090', '3'),
    ('0102', '7'),
    ('0157', '1'),
    ('0148', '2'),
    ('0140', '8'),
    ('0093', '4'),
    ('0100', '7'),
    ('049', '6'),
    ('0092', '2'),
    ('0144', '1'),
    ('0086', '1'),
    ('0095', '4'),
    ('0104', '1'),
    ('0149', '2'),
    ('0088', '8'),
    ('0146', '9'),
    ('0147', '2'),
    ('047', '4'),
    ('0033', '7'),
    ('0120', '4'),
    ('0121', '7'),
    ('0035', '8'),
    ('0132', '5'),
    ('0134', '7'),
    ('049', '7'),
    ('0084', '4'),
    ('0096', '8'),
    ('0012', '1'),
    ('0089', '3'),
    ('0003', '3'),
    ('0097', '2'),
    ('0151', '10'),
    ('0092', '5'),
    ('0154', '1'),
    ('0155', '9'),
    ('0156', '4'),
    ('0074', '1'),
    ('0159', '4'),
    ('0161', '9'),
    ('0109', '11');

INSERT IGNORE INTO cargo_setores_metadados (cargo_id, setor_id, origem_metadados, sincronizado_em)
SELECT c.id, s.id, 'RHCONTRATOS', NOW()
FROM tmp_cargo_setores_metadados_seed seed
INNER JOIN cargos  c ON c.codigo_cargo = seed.codigo_cargo
INNER JOIN setores s ON s.codigo_setor = seed.codigo_setor;

DROP TEMPORARY TABLE IF EXISTS tmp_cargo_setores_metadados_seed;
