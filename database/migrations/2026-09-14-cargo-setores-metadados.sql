-- Migration: 2026-09-14-cargo-setores-metadados.sql
-- Objetivo:
--   Sprint "Solicitação de Vaga" — matriz OFICIAL de Cargo x Setor. Corrige a direção da Etapa 2
--   (que tratava Cargo e Setor como independentes na Solicitação de Vaga) para a regra definitiva:
--   "Setor selecionado -> lista somente os Cargos oficialmente associados àquele Setor".
--
--   `cargo_setores_metadados` é um ESPELHO TÉCNICO do METADADOS — não um novo cadastro manual do
--   Portal. Não terá CRUD administrativo. A tabela LEGADA `cargo_setores` (importação manual
--   anterior à integração oficial) permanece intocada, mas NÃO participa mais da Solicitação de
--   Vaga — nenhuma leitura, nenhum fallback, nenhum cruzamento.
--
--   Fonte da relação, por ordem de prioridade (documentada aqui e em
--   docs/claude/roadmap-tecnico.md — nunca misturar as duas simultaneamente):
--     1. RHQUADROLOTCARGO (METADADOS) quando estiver populada — hoje tem 0 linhas na instância da
--        Madeplant (o módulo de Quadro de Lotação existe no schema do TOTVS RM mas nunca foi
--        operado pela empresa).
--     2. Enquanto RHQUADROLOTCARGO estiver vazia: combinações distintas observadas no histórico
--        completo de RHCONTRATOS.CARGO + RHCONTRATOS.SETOR (ativos e desligados — uma combinação
--        que já existiu oficialmente permanece válida para reabertura futura de vaga). É a carga
--        inicial desta migration (ver 2026-09-14-cargo-setores-metadados-seed.sql).
--
--   `origem_metadados` guarda de qual estrutura do METADADOS a linha foi derivada
--   ('RHCONTRATOS' hoje; 'RHQUADROLOTCARGO' quando a Madeplant passar a operar aquele módulo) —
--   nunca um rótulo genérico, para permitir auditoria de proveniência.
--
--   Identidade por `cargo_id`/`setor_id` (FK para os catálogos oficiais já sincronizados pela Fase
--   5.2), nunca por nome. PK composta (cargo_id, setor_id) — mesmo padrão de `cargo_setores`.
--
--   Sem CRUD manual: RH/Admin não editam esta tabela pelo Portal. Se faltar vínculo, a correção é
--   na fonte oficial (METADADOS), não no Portal — reforça a decisão de que o METADADOS é a fonte
--   absoluta.
--
--   Compatível MySQL 8.4.x (dev) / MariaDB 11.8.9-log (produção). DDL simples, sem procedure/
--   DELIMITER/CALL/trigger/CHECK/índice parcial.
--
--   Produção: NÃO aplicar nesta etapa (sprint de desenvolvimento).

CREATE TABLE IF NOT EXISTS cargo_setores_metadados (
  cargo_id          INT NOT NULL,
  setor_id          INT NOT NULL,
  origem_metadados  VARCHAR(60) NOT NULL,
  sincronizado_em   DATETIME NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (cargo_id, setor_id),
  KEY idx_cargo_setores_metadados_setor (setor_id),
  CONSTRAINT fk_cargo_setores_metadados_cargo FOREIGN KEY (cargo_id) REFERENCES cargos(id) ON DELETE CASCADE,
  CONSTRAINT fk_cargo_setores_metadados_setor FOREIGN KEY (setor_id) REFERENCES setores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
