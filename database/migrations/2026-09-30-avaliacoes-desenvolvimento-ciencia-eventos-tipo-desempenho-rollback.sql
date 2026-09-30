-- Rollback: 2026-09-30-avaliacoes-desenvolvimento-ciencia-eventos-tipo-desempenho-rollback.sql
-- Reverte 2026-09-30-avaliacoes-desenvolvimento-ciencia-eventos-tipo-desempenho.sql. Só é seguro
-- rodar se nenhuma linha com documento_tipo='avaliacao_desempenho' existir nas duas tabelas
-- (senão o CHECK restrito volta a valer e passaria a rejeitar linhas já existentes em SELECTs de
-- validação futura — o MySQL/MariaDB não revalida linhas existentes ao recriar o CHECK, mas o
-- Service deixaria de conseguir inserir novas).

ALTER TABLE avaliacoes_desenvolvimento_ciencia
  DROP CHECK chk_avdesenv_ciencia_tipo_doc,
  ADD CONSTRAINT chk_avdesenv_ciencia_tipo_doc CHECK (documento_tipo IN ('avaliacao_experiencia','feedback'));

ALTER TABLE avaliacoes_desenvolvimento_eventos
  DROP CHECK chk_avdesenv_eventos_tipo_doc,
  ADD CONSTRAINT chk_avdesenv_eventos_tipo_doc CHECK (documento_tipo IN ('avaliacao_experiencia','feedback'));
