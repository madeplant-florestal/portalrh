-- Rollback: 2026-09-09-solicitacoes-vaga-gestor-opcional.sql
-- Restaura `solicitacoes_vaga.gestor_solicitante_colaborador_id` para INT NOT NULL + FK RESTRICT.
--
-- FALHA DE PROPÓSITO se já existir qualquer solicitação com gestor_solicitante_colaborador_id
-- NULL (ex.: solicitação criada por gestor PJ após a migration). Nesse caso o MySQL rejeita o
-- `MODIFY ... NOT NULL` — comportamento esperado: não há como voltar ao modelo antigo sem
-- decidir o que fazer com essas solicitações. Resolva os NULLs manualmente antes de reverter.

ALTER TABLE solicitacoes_vaga
  DROP FOREIGN KEY fk_solicitacoes_gestor_colaborador;

ALTER TABLE solicitacoes_vaga
  MODIFY COLUMN gestor_solicitante_colaborador_id INT NOT NULL;

ALTER TABLE solicitacoes_vaga
  ADD CONSTRAINT fk_solicitacoes_gestor_colaborador
      FOREIGN KEY (gestor_solicitante_colaborador_id) REFERENCES colaboradores(id) ON DELETE RESTRICT;
