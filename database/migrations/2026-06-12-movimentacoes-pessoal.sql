-- Migration: 2026-06-12-movimentacoes-pessoal.sql
-- Objetivo:
--   - Complementar o cadastro de colaboradores com metadados de RH
--   - Criar avaliações de desempenho
--   - Estruturar o formulário de solicitação de movimentação de pessoal com rascunho, assinaturas digitais e auditoria

ALTER TABLE colaboradores ADD COLUMN IF NOT EXISTS matricula VARCHAR(30) NULL AFTER nome;
ALTER TABLE colaboradores ADD COLUMN IF NOT EXISTS salario_atual DECIMAL(12,2) NULL AFTER setor_id;
ALTER TABLE colaboradores ADD COLUMN IF NOT EXISTS data_admissao DATE NULL AFTER salario_atual;
ALTER TABLE colaboradores ADD COLUMN IF NOT EXISTS data_inicio_cargo DATE NULL AFTER data_admissao;

CREATE TABLE IF NOT EXISTS colaborador_avaliacoes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  colaborador_id INT NOT NULL,
  titulo VARCHAR(160) NOT NULL,
  nota DECIMAL(5,2) NULL,
  periodo_referencia VARCHAR(80) NULL,
  resumo TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_colaborador_avaliacoes_colaborador (colaborador_id),
  CONSTRAINT fk_colaborador_avaliacoes_colaborador FOREIGN KEY (colaborador_id) REFERENCES colaboradores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS movimentacoes_pessoal (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tipo_movimentacao ENUM('merito','promocao','transferencia','alteracao_funcao') NOT NULL,
  data_solicitacao DATE NOT NULL,
  gestor_solicitante_usuario_id INT NOT NULL,
  gestor_solicitante_colaborador_id INT NULL,
  setor_id INT NOT NULL,
  colaborador_id INT NOT NULL,
  matricula_snapshot VARCHAR(30) NOT NULL,
  cargo_atual_id INT NOT NULL,
  tempo_cargo_meses INT NOT NULL DEFAULT 0,
  tempo_empresa_meses INT NOT NULL DEFAULT 0,
  salario_atual_snapshot DECIMAL(12,2) NOT NULL DEFAULT 0,
  novo_cargo_id INT NULL,
  nova_area_setor_id INT NULL,
  novo_salario DECIMAL(12,2) NULL,
  percentual_aumento DECIMAL(9,2) NULL,
  data_prevista_mudanca DATE NULL,
  justificativa_encrypted MEDIUMTEXT NULL,
  entregas_ultimos_6_meses_encrypted MEDIUMTEXT NULL,
  resultados_atingidos_encrypted MEDIUMTEXT NULL,
  avaliacao_desempenho_id INT NULL,
  pronto_proximo_nivel_encrypted MEDIUMTEXT NULL,
  competencias_tecnicas_encrypted MEDIUMTEXT NULL,
  competencias_comportamentais_encrypted MEDIUMTEXT NULL,
  pontos_desenvolvimento_encrypted MEDIUMTEXT NULL,
  aumento_mensal DECIMAL(12,2) NULL,
  impacto_anual DECIMAL(12,2) NULL,
  existe_orcamento_aprovado ENUM('sim','nao','em_validacao') NULL,
  posicao_atual_sera ENUM('extinta','substituida') NULL,
  existe_candidato_interno TINYINT(1) NULL,
  necessita_recrutamento_externo TINYINT(1) NULL,
  existe_risco_perda TINYINT(1) NULL,
  impacto_nao_aprovado_encrypted MEDIUMTEXT NULL,
  status_fluxo ENUM('rascunho','pendente_rh','aprovada') NOT NULL DEFAULT 'rascunho',
  gestor_assinatura_hash VARCHAR(64) NULL,
  gestor_assinado_em DATETIME NULL,
  gestor_assinado_por_usuario_id INT NULL,
  rh_assinatura_hash VARCHAR(64) NULL,
  rh_assinado_em DATETIME NULL,
  rh_assinado_por_usuario_id INT NULL,
  data_decisao DATE NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_movimentacoes_status (status_fluxo),
  CONSTRAINT fk_movimentacoes_gestor_usuario FOREIGN KEY (gestor_solicitante_usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
  CONSTRAINT fk_movimentacoes_gestor_colaborador FOREIGN KEY (gestor_solicitante_colaborador_id) REFERENCES colaboradores(id) ON DELETE SET NULL,
  CONSTRAINT fk_movimentacoes_setor FOREIGN KEY (setor_id) REFERENCES setores(id) ON DELETE RESTRICT,
  CONSTRAINT fk_movimentacoes_colaborador FOREIGN KEY (colaborador_id) REFERENCES colaboradores(id) ON DELETE RESTRICT,
  CONSTRAINT fk_movimentacoes_cargo_atual FOREIGN KEY (cargo_atual_id) REFERENCES cargos(id) ON DELETE RESTRICT,
  CONSTRAINT fk_movimentacoes_novo_cargo FOREIGN KEY (novo_cargo_id) REFERENCES cargos(id) ON DELETE SET NULL,
  CONSTRAINT fk_movimentacoes_nova_area FOREIGN KEY (nova_area_setor_id) REFERENCES setores(id) ON DELETE SET NULL,
  CONSTRAINT fk_movimentacoes_avaliacao FOREIGN KEY (avaliacao_desempenho_id) REFERENCES colaborador_avaliacoes(id) ON DELETE SET NULL,
  CONSTRAINT fk_movimentacoes_gestor_assinante FOREIGN KEY (gestor_assinado_por_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT fk_movimentacoes_rh_assinante FOREIGN KEY (rh_assinado_por_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS movimentacoes_pessoal_auditoria (
  id INT AUTO_INCREMENT PRIMARY KEY,
  movimentacao_id INT NOT NULL,
  actor_usuario_id INT NULL,
  event_type VARCHAR(60) NOT NULL,
  field_name VARCHAR(80) NULL,
  old_value_encrypted TEXT NULL,
  new_value_encrypted TEXT NULL,
  ip VARCHAR(45) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_movimentacao_auditoria_movimentacao FOREIGN KEY (movimentacao_id) REFERENCES movimentacoes_pessoal(id) ON DELETE CASCADE,
  CONSTRAINT fk_movimentacao_auditoria_actor FOREIGN KEY (actor_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
