-- Migration: 2026-06-09-solicitacoes-vaga.sql
-- Objetivo:
--   - Estruturar o fluxo de solicitacao de vaga com aprovacoes, auditoria e controle interno RH.
--   - Criar tabelas auxiliares para integracao referencial com setores, cargos, beneficios, colaboradores e usuarios.

CREATE TABLE IF NOT EXISTS centros_custo (
  id INT AUTO_INCREMENT PRIMARY KEY,
  setor_id INT NOT NULL,
  codigo VARCHAR(30) NOT NULL,
  nome VARCHAR(120) NOT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_centros_custo_codigo (codigo),
  KEY idx_centros_custo_setor (setor_id),
  CONSTRAINT fk_centros_custo_setor FOREIGN KEY (setor_id) REFERENCES setores(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cargo_setores (
  cargo_id INT NOT NULL,
  setor_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (cargo_id, setor_id),
  KEY idx_cargo_setores_setor (setor_id),
  CONSTRAINT fk_cargo_setores_cargo FOREIGN KEY (cargo_id) REFERENCES cargos(id) ON DELETE CASCADE,
  CONSTRAINT fk_cargo_setores_setor FOREIGN KEY (setor_id) REFERENCES setores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cargo_faixas_salariais (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cargo_id INT NOT NULL,
  salario_min DECIMAL(12,2) NOT NULL,
  salario_max DECIMAL(12,2) NOT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_cargo_faixa (cargo_id),
  CONSTRAINT fk_cargo_faixas_salariais_cargo FOREIGN KEY (cargo_id) REFERENCES cargos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cargo_beneficios (
  cargo_id INT NOT NULL,
  beneficio_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (cargo_id, beneficio_id),
  KEY idx_cargo_beneficios_beneficio (beneficio_id),
  CONSTRAINT fk_cargo_beneficios_cargo FOREIGN KEY (cargo_id) REFERENCES cargos(id) ON DELETE CASCADE,
  CONSTRAINT fk_cargo_beneficios_beneficio FOREIGN KEY (beneficio_id) REFERENCES beneficios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS competencias (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(120) NOT NULL,
  tipo ENUM('tecnica','comportamental') NOT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_competencias_nome_tipo (nome, tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS usuario_colaboradores (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  colaborador_id INT NOT NULL,
  is_gestor TINYINT(1) NOT NULL DEFAULT 0,
  is_rh TINYINT(1) NOT NULL DEFAULT 0,
  lider_colaborador_id INT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_usuario_colaborador_usuario (usuario_id),
  UNIQUE KEY uniq_usuario_colaborador_colaborador (colaborador_id),
  KEY idx_usuario_colaboradores_lider (lider_colaborador_id),
  CONSTRAINT fk_usuario_colaboradores_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  CONSTRAINT fk_usuario_colaboradores_colaborador FOREIGN KEY (colaborador_id) REFERENCES colaboradores(id) ON DELETE CASCADE,
  CONSTRAINT fk_usuario_colaboradores_lider FOREIGN KEY (lider_colaborador_id) REFERENCES colaboradores(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS solicitacoes_vaga (
  id INT AUTO_INCREMENT PRIMARY KEY,
  setor_id INT NOT NULL,
  quantidade_vagas INT NOT NULL,
  cargo_id INT NOT NULL,
  maquina_operada_encrypted TEXT NULL,
  gestor_solicitante_colaborador_id INT NOT NULL,
  solicitante_usuario_id INT NOT NULL,
  tipo_vaga ENUM('nova_posicao','substituicao','aumento_quadro','projeto_temporario') NOT NULL,
  colaborador_substituido_id INT NULL,
  data_desligamento DATE NULL,
  motivo_saida ENUM('desligamento','promocao','transferencia','outros') NULL,
  motivo_saida_outros_encrypted TEXT NULL,
  tipo_contratacao ENUM('clt','temporario','terceiro','pj') NOT NULL,
  salario_previsto DECIMAL(12,2) NOT NULL,
  centro_custo_id INT NOT NULL,
  previsto_orcamento TINYINT(1) NOT NULL,
  justificativa_orcamento_encrypted TEXT NULL,
  jornada_trabalho VARCHAR(80) NOT NULL,
  escala_encrypted TEXT NULL,
  turno ENUM('diurno','noturno','misto') NULL,
  escolaridade_minima ENUM('fundamental','medio','tecnico','superior_incompleto','superior_completo','pos_graduacao') NOT NULL,
  formacao_academica_encrypted TEXT NULL,
  experiencia_necessaria_encrypted TEXT NULL,
  entregas_esperadas_encrypted MEDIUMTEXT NOT NULL,
  nivel_responsabilidade ENUM('operacional','tecnico','analitico','estrategico') NOT NULL,
  data_prevista_inicio DATE NOT NULL,
  urgencia ENUM('baixa','media','alta','critica') NOT NULL,
  data_limite_fechamento DATE NULL,
  lider_imediato_colaborador_id INT NULL,
  lider_imediato_usuario_id INT NULL,
  status_fluxo ENUM('pendente_lider','pendente_rh','aprovada','reprovada_lider','reprovada_rh','concluida') NOT NULL DEFAULT 'pendente_lider',
  nome_contratado_colaborador_id INT NULL,
  data_admissao DATE NULL,
  tempo_fechamento_dias INT NULL,
  avaliacao_90_dias ENUM('atendeu_plenamente','atendeu_parcialmente','nao_atendeu') NULL,
  observacoes_rh_encrypted TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_solicitacoes_status (status_fluxo),
  KEY idx_solicitacoes_setor (setor_id),
  KEY idx_solicitacoes_cargo (cargo_id),
  CONSTRAINT fk_solicitacoes_setor FOREIGN KEY (setor_id) REFERENCES setores(id) ON DELETE RESTRICT,
  CONSTRAINT fk_solicitacoes_cargo FOREIGN KEY (cargo_id) REFERENCES cargos(id) ON DELETE RESTRICT,
  CONSTRAINT fk_solicitacoes_gestor_colaborador FOREIGN KEY (gestor_solicitante_colaborador_id) REFERENCES colaboradores(id) ON DELETE RESTRICT,
  CONSTRAINT fk_solicitacoes_solicitante_usuario FOREIGN KEY (solicitante_usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
  CONSTRAINT fk_solicitacoes_colaborador_substituido FOREIGN KEY (colaborador_substituido_id) REFERENCES colaboradores(id) ON DELETE SET NULL,
  CONSTRAINT fk_solicitacoes_centro_custo FOREIGN KEY (centro_custo_id) REFERENCES centros_custo(id) ON DELETE RESTRICT,
  CONSTRAINT fk_solicitacoes_lider_colaborador FOREIGN KEY (lider_imediato_colaborador_id) REFERENCES colaboradores(id) ON DELETE SET NULL,
  CONSTRAINT fk_solicitacoes_lider_usuario FOREIGN KEY (lider_imediato_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT fk_solicitacoes_contratado FOREIGN KEY (nome_contratado_colaborador_id) REFERENCES colaboradores(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS solicitacao_vaga_beneficios (
  solicitacao_id INT NOT NULL,
  beneficio_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (solicitacao_id, beneficio_id),
  KEY idx_solicitacao_beneficios_beneficio (beneficio_id),
  CONSTRAINT fk_solicitacao_beneficios_solicitacao FOREIGN KEY (solicitacao_id) REFERENCES solicitacoes_vaga(id) ON DELETE CASCADE,
  CONSTRAINT fk_solicitacao_beneficios_beneficio FOREIGN KEY (beneficio_id) REFERENCES beneficios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS solicitacao_vaga_competencias (
  solicitacao_id INT NOT NULL,
  competencia_id INT NOT NULL,
  tipo ENUM('tecnica','comportamental') NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (solicitacao_id, competencia_id),
  KEY idx_solicitacao_competencias_tipo (tipo),
  CONSTRAINT fk_solicitacao_competencias_solicitacao FOREIGN KEY (solicitacao_id) REFERENCES solicitacoes_vaga(id) ON DELETE CASCADE,
  CONSTRAINT fk_solicitacao_competencias_competencia FOREIGN KEY (competencia_id) REFERENCES competencias(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS solicitacao_vaga_aprovacoes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  solicitacao_id INT NOT NULL,
  etapa ENUM('lider_imediato','rh') NOT NULL,
  destinatario_usuario_id INT NULL,
  status ENUM('pendente','aprovado','reprovado') NOT NULL DEFAULT 'pendente',
  aprovador_usuario_id INT NULL,
  observacao_encrypted TEXT NULL,
  assinatura_hash VARCHAR(64) NULL,
  aprovado_em DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_solicitacao_etapa (solicitacao_id, etapa),
  CONSTRAINT fk_solicitacao_aprovacoes_solicitacao FOREIGN KEY (solicitacao_id) REFERENCES solicitacoes_vaga(id) ON DELETE CASCADE,
  CONSTRAINT fk_solicitacao_aprovacoes_destinatario FOREIGN KEY (destinatario_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT fk_solicitacao_aprovacoes_aprovador FOREIGN KEY (aprovador_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS solicitacao_vaga_auditoria (
  id INT AUTO_INCREMENT PRIMARY KEY,
  solicitacao_id INT NOT NULL,
  actor_usuario_id INT NULL,
  event_type VARCHAR(60) NOT NULL,
  field_name VARCHAR(80) NULL,
  old_value_encrypted TEXT NULL,
  new_value_encrypted TEXT NULL,
  ip VARCHAR(45) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_solicitacao_auditoria_solicitacao FOREIGN KEY (solicitacao_id) REFERENCES solicitacoes_vaga(id) ON DELETE CASCADE,
  CONSTRAINT fk_solicitacao_auditoria_actor FOREIGN KEY (actor_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO cargo_setores (cargo_id, setor_id)
SELECT DISTINCT cargo_id, setor_id
FROM colaboradores
WHERE cargo_id IS NOT NULL AND setor_id IS NOT NULL;
