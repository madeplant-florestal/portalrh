-- Rollback: 2026-11-24-vagas-setor-responsavel-rollback.sql
-- Desfaz a Etapa 3.2A: remove vagas.setor_id (FK, indice e coluna).
-- ATENCAO: perde os setores atribuidos pelo novo fluxo (criacao/edicao/regularizacao). Vagas, candidaturas, documentos e movimentacoes nao sao tocados.
-- O codigo da 3.2A detecta a ausencia da coluna e volta ao comportamento anterior (sem exigir setor).
-- PASSO 0 (opcional, somente leitura) - guardar o que sera perdido:
--   SELECT id, titulo, setor_id FROM vagas WHERE setor_id IS NOT NULL;
-- Ordem: FK -> indice -> coluna.
ALTER TABLE vagas DROP FOREIGN KEY fk_vagas_setor;
ALTER TABLE vagas DROP KEY idx_vagas_setor_id;
ALTER TABLE vagas DROP COLUMN setor_id;
-- VERIFICACAO: esperado 0 linhas.
SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vagas' AND COLUMN_NAME = 'setor_id';
