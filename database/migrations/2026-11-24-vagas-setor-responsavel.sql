-- Migration: 2026-11-24-vagas-setor-responsavel.sql
-- Etapa 3.2A — vinculo ESTRUTURADO e estavel entre a vaga e o setor responsavel (catalogo oficial `setores`).
--
-- POR QUE: `vagas.area` e texto livre e `vagas.solicitacao_vaga_id` e opcional; nao ha como saber, de forma confiavel, de que setor e uma vaga.
-- Candidaturas e Pipeline dependem da vaga. Esta coluna e a BASE da futura autorizacao de Gestores (Etapas 3.2B/3.2C) — nao libera nada sozinha.
--
-- MODELAGEM (reaproveita o conceito que ja existe; nao cria um segundo conceito de setor):
--   vagas.setor_id INT NULL  ->  setores.id   (mesma chave usada por solicitacoes_vaga.setor_id e usuario_setores_gerenciados.setor_id)
--   * `setores.id` e imutavel: renomear o setor (nome/descricao_oficial), inativar (`ativo = 0`) ou reconciliar com o METADADOS NAO quebra o vinculo
--     historico. O codigo oficial (`codigo_setor`) continua sendo lido por JOIN quando for preciso resolver o escopo do Gestor.
--   * FK ON DELETE RESTRICT: um setor com vagas nunca e apagado em silencio (o Portal nao apaga setores; a regra protege tambem acessos manuais).
--   * FK ON UPDATE CASCADE e KEY idx_vagas_setor_id para filtros por setor.
--   * NULLABLE DE PROPOSITO (decisao de transicao): existem vagas historicas sem setor identificavel; a migration NAO pode falhar por causa delas,
--     NAO atribui setor presumido nem generico e NAO faz UPDATE de dados. A obrigatoriedade para vagas NOVAS e imposta pela aplicacao (servidor).
--     O endurecimento para NOT NULL so deve ocorrer depois da regularizacao integral (ver diagnostico e docs/claude/vagas-setor-responsavel.md).
--
-- PRE-REQUISITOS: tabelas `vagas` e `setores` (InnoDB) existentes; usuario do banco com ALTER. Compativel com MariaDB 11.8 e MySQL 8.
-- IMPACTO: tabela pequena (centenas de linhas): ALTER INSTANTANEO na pratica; adiciona coluna + indice + FK (validacao da FK le so linhas com setor_id
-- nao nulo = nenhuma). Sem perda de dados. Pode ser executada com o codigo antigo no ar (ele ignora a coluna).
-- ORDEM DE IMPLANTACAO: 1) executar ESTE script (phpMyAdmin, banco u172743873_portalrh SELECIONADO); 2) publicar o codigo.
-- (O codigo publicado ANTES da migration continua funcionando como antes: detecta a ausencia da coluna e nao exige setor.)
-- Rollback: 2026-11-24-vagas-setor-responsavel-rollback.sql (remove a coluna; perde SOMENTE os setores ja atribuidos pelo novo fluxo).

-- PASSO 0 - PRE-VERIFICACAO (somente leitura). Esperado: 0 linhas em (a) e a tabela `setores` com PK `id` INT em (b).
--   (a) SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vagas' AND COLUMN_NAME = 'setor_id';
--   (b) SELECT COLUMN_TYPE, COLUMN_KEY FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'setores' AND COLUMN_NAME = 'id';
--   (c) SELECT COUNT(*) AS vagas, SUM(solicitacao_vaga_id IS NOT NULL) AS com_solicitacao FROM vagas;   -- registre para comparar no PASSO 3

-- PASSO 1 - ALTER (um unico comando: coluna + indice + FK)
ALTER TABLE vagas
  ADD COLUMN setor_id INT NULL AFTER empresa_id,
  ADD KEY idx_vagas_setor_id (setor_id),
  ADD CONSTRAINT fk_vagas_setor FOREIGN KEY (setor_id) REFERENCES setores (id) ON DELETE RESTRICT ON UPDATE CASCADE;

-- PASSO 2 - VERIFICACAO (somente leitura). Esperado: setor_id, int, YES, MUL, e a FK fk_vagas_setor -> setores.id.
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vagas' AND COLUMN_NAME = 'setor_id';

SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vagas' AND CONSTRAINT_NAME = 'fk_vagas_setor';

-- PASSO 3 - NADA MUDOU NOS DADOS. Esperado: o mesmo total do PASSO 0(c) e com_setor = 0 (nenhuma vaga recebeu setor por esta migration).
SELECT COUNT(*) AS vagas, SUM(solicitacao_vaga_id IS NOT NULL) AS com_solicitacao, SUM(setor_id IS NOT NULL) AS com_setor FROM vagas;
