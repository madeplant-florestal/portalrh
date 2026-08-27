-- Migration: 2026-07-14-candidatura-propostas.sql
-- Sprint 1.1 — Reestruturação do fluxo de recrutamento (Carta Proposta / Contratado).
--
-- Estrutura dedicada para propostas de contratação, independente de
-- candidatura_stage_metadata (que é upsert de uma única linha por candidatura e não
-- suporta histórico/múltiplas propostas para o mesmo candidato).
--
-- beneficios_registrados é um snapshot imutável (JSON) dos benefícios selecionados no
-- catálogo (Beneficio::allActive()) no momento do envio — mudanças futuras no cadastro de
-- benefícios não alteram propostas já emitidas.
--
-- status é fechado (ENUM), sem texto livre, conforme decisão da Sprint 1.1.

CREATE TABLE IF NOT EXISTS candidatura_propostas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  candidatura_id INT NOT NULL,
  versao INT NOT NULL DEFAULT 1,
  identificador_publico VARCHAR(64) NOT NULL,
  salario DECIMAL(12,2) NOT NULL,
  cargo VARCHAR(150) NOT NULL,
  tipo_contratacao VARCHAR(30) NOT NULL,
  carga_horaria VARCHAR(60) NOT NULL,
  beneficios_registrados JSON NOT NULL,
  data_prevista_admissao DATE NOT NULL,
  validade_proposta DATE NOT NULL,
  observacoes TEXT NOT NULL,
  status ENUM('RASCUNHO','ENVIADA','VISUALIZADA','ACEITA','RECUSADA','EXPIRADA','CANCELADA') NOT NULL DEFAULT 'ENVIADA',
  pdf_path VARCHAR(255) NULL,
  enviada_em DATETIME NULL,
  data_resposta DATETIME NULL,
  criado_por INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_candidatura_propostas_identificador (identificador_publico),
  KEY idx_candidatura_propostas_candidatura (candidatura_id),
  CONSTRAINT fk_candidatura_propostas_candidatura FOREIGN KEY (candidatura_id) REFERENCES candidaturas(id) ON DELETE CASCADE,
  CONSTRAINT fk_candidatura_propostas_criado_por FOREIGN KEY (criado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
