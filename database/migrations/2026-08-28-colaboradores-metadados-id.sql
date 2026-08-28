-- Migration: 2026-08-28-colaboradores-metadados-id.sql
-- Objetivo:
--   Fase 3.2 da integração com o METADADOS — cria o vínculo opcional entre a tabela local
--   `colaboradores` (identificador estável, referenciado por 9 FKs em 4 tabelas) e o espelho
--   oficial `colaboradores_metadados` (uma linha por contrato, sincronizada do METADADOS).
--
--   colaboradores.id continua sendo o identificador local estável — NUNCA é substituído pelo
--   id do espelho. colaboradores.metadados_id aponta opcionalmente (0 ou 1) para
--   colaboradores_metadados.id.
--
--   UNIQUE em metadados_id impede que dois registros locais diferentes apontem para o mesmo
--   vínculo oficial (MySQL permite múltiplos NULL sob UNIQUE, então registros ainda não
--   reconciliados continuam livres).
--
--   ON DELETE RESTRICT (não CASCADE): colaboradores_metadados nunca deve apagar um colaborador
--   local silenciosamente. Na prática a sincronização nunca faz DELETE em colaboradores_metadados
--   (upsert puro, ver MetadadosSyncService), então este RESTRICT é uma proteção defensiva, não
--   uma dependência de comportamento esperado.
--
--   Esta migration só cria estrutura — nenhum metadados_id é preenchido aqui. A reconciliação
--   (ColaboradorMetadadosReconciliationService / scripts/reconciliar_colaboradores_metadados.php)
--   é somente leitura nesta fase; a aplicação de vínculos fica para uma fase futura, autorizada
--   separadamente.

ALTER TABLE colaboradores
  ADD COLUMN metadados_id INT NULL AFTER codigo,
  ADD UNIQUE KEY uk_colaboradores_metadados_id (metadados_id),
  ADD CONSTRAINT fk_colaboradores_metadados FOREIGN KEY (metadados_id) REFERENCES colaboradores_metadados(id) ON DELETE RESTRICT;
