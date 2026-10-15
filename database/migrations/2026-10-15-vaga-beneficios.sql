-- Migration: 2026-10-15-vaga-beneficios.sql
-- Objetivo:
--   Corrige a página pública da vaga, que hoje exibe INDISCRIMINADAMENTE todo o catálogo ativo de
--   `beneficios` (HomeController::vaga() -> Beneficio::allActive()), sem nenhuma relação com a
--   vaga sendo vista. `beneficio cadastrado` passa a ser só o CATÁLOGO; cada vaga publicada tem
--   sua própria seleção explícita, definida pelo RH/Admin na tela de cadastro/edição de Vaga.
--
--   Tabela intermediária N:N `vaga_beneficios`, mesmo padrão já usado por
--   `solicitacao_vaga_beneficios` (SolicitacaoVaga::ensureSchema()): PK composta (vaga_id,
--   beneficio_id), FK para `vagas` com ON DELETE CASCADE (remover a vaga remove a seleção) e FK
--   para `beneficios` com ON DELETE RESTRICT (não permite apagar um benefício do catálogo
--   enquanto ele estiver vinculado a alguma vaga).
--
--   Sem seed: vagas existentes nascem SEM nenhum benefício associado — não reproduz o problema que
--   está sendo corrigido. O RH revisa e seleciona manualmente os benefícios de cada vaga aberta.
--
--   Compatível MySQL 8.4.x (dev) / MariaDB 11.8.9-log (produção). DDL simples, idempotente
--   (CREATE TABLE IF NOT EXISTS). Mesma tabela também é criada em runtime por
--   RecruitmentWebhookSchemaService::ensureSchema() (mesmo padrão de solicitacao_vaga_beneficios)
--   — rodar esta migration em produção é opcional/antecipado, nunca obrigatório para o código
--   funcionar; reaplicar não duplica nem falha.
--
--   Produção: NÃO aplicar nesta etapa (sprint de desenvolvimento).

CREATE TABLE IF NOT EXISTS vaga_beneficios (
  vaga_id       INT NOT NULL,
  beneficio_id  INT NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (vaga_id, beneficio_id),
  KEY idx_vaga_beneficios_beneficio (beneficio_id),
  CONSTRAINT fk_vaga_beneficios_vaga FOREIGN KEY (vaga_id) REFERENCES vagas(id) ON DELETE CASCADE,
  CONSTRAINT fk_vaga_beneficios_beneficio FOREIGN KEY (beneficio_id) REFERENCES beneficios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
