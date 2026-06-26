-- Schema RH Madeplant - Gestao de Curriculos

CREATE TABLE IF NOT EXISTS usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(100) NOT NULL,
  email VARCHAR(120) NOT NULL UNIQUE,
  senha_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','rh','viewer') NOT NULL DEFAULT 'viewer',
  is_supervisor TINYINT(1) NOT NULL DEFAULT 0,
  email_verified_at DATETIME DEFAULT NULL,
  last_password_reset_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS empresas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(160) NOT NULL,
  slug VARCHAR(180) NOT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_empresas_nome (nome),
  UNIQUE KEY uk_empresas_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vagas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  titulo VARCHAR(150) NOT NULL,
  descricao TEXT NOT NULL,
  requisitos TEXT NOT NULL,
  area VARCHAR(100) DEFAULT NULL,
  local VARCHAR(100) DEFAULT NULL,
  empresa_id INT DEFAULT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_vagas_empresa_id (empresa_id),
  CONSTRAINT fk_vagas_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS candidaturas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vaga_id INT NOT NULL,
  nome VARCHAR(120) NOT NULL,
  email VARCHAR(120) NOT NULL,
  telefone VARCHAR(11) NOT NULL,
  cpf VARCHAR(11) NOT NULL UNIQUE,
  cargo_pretendido VARCHAR(120) NOT NULL,
  experiencia TEXT NOT NULL,
  pdf_path VARCHAR(255) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'novo',
  indicacao_colaborador TINYINT(1) NOT NULL DEFAULT 0,
  indicacao_colaborador_nome VARCHAR(120) DEFAULT NULL,
  indicacao_data_contratacao DATETIME DEFAULT NULL,
  indicacao_data_fim_experiencia DATE DEFAULT NULL,
  indicacao_pagamento_realizado TINYINT(1) NOT NULL DEFAULT 0,
  indicacao_pagamento_status VARCHAR(20) NOT NULL DEFAULT 'pendente',
  indicacao_valor_comissao DECIMAL(10,2) DEFAULT NULL,
  indicacao_data_pagamento DATE DEFAULT NULL,
  indicacao_metodo_pagamento VARCHAR(50) DEFAULT NULL,
  indicacao_pagamento_registrado_em DATETIME DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cand_vaga FOREIGN KEY (vaga_id) REFERENCES vagas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS candidatura_historico (
  id INT AUTO_INCREMENT PRIMARY KEY,
  candidatura_id INT NOT NULL,
  status_anterior VARCHAR(30) DEFAULT NULL,
  status_novo VARCHAR(30) NOT NULL,
  observacoes TEXT DEFAULT NULL,
  usuario_id INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_hist_candidatura FOREIGN KEY (candidatura_id) REFERENCES candidaturas(id) ON DELETE CASCADE,
  CONSTRAINT fk_hist_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS candidatura_stage_metadata (
  id INT AUTO_INCREMENT PRIMARY KEY,
  candidatura_id INT NOT NULL,
  interview_date DATE DEFAULT NULL,
  interview_time TIME DEFAULT NULL,
  interview_location VARCHAR(255) DEFAULT NULL,
  interview_link VARCHAR(255) DEFAULT NULL,
  admission_date DATE DEFAULT NULL,
  admission_notes TEXT DEFAULT NULL,
  test_name VARCHAR(150) DEFAULT NULL,
  deadline DATE DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_candidatura_stage_metadata_candidatura (candidatura_id),
  KEY idx_candidatura_stage_metadata_deadline (deadline),
  CONSTRAINT fk_candidatura_stage_metadata_candidatura FOREIGN KEY (candidatura_id) REFERENCES candidaturas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recruitment_webhook_settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  scope_key VARCHAR(100) NOT NULL,
  empresa_id INT DEFAULT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  webhook_url VARCHAR(500) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_recruitment_webhook_settings_scope (scope_key),
  KEY idx_recruitment_webhook_settings_empresa (empresa_id),
  CONSTRAINT fk_recruitment_webhook_settings_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS webhook_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT DEFAULT NULL,
  event_type VARCHAR(80) NOT NULL,
  payload_json LONGTEXT NOT NULL,
  webhook_url VARCHAR(500) DEFAULT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  response_code INT DEFAULT NULL,
  response_body MEDIUMTEXT DEFAULT NULL,
  last_error TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME DEFAULT NULL,
  retry_count INT NOT NULL DEFAULT 0,
  KEY idx_webhook_events_status (status),
  KEY idx_webhook_events_event_type (event_type),
  KEY idx_webhook_events_tenant (tenant_id),
  KEY idx_webhook_events_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS indicacao_pagamento_auditoria (
  id INT AUTO_INCREMENT PRIMARY KEY,
  candidatura_id INT NOT NULL,
  data_anterior DATE DEFAULT NULL,
  data_nova DATE NOT NULL,
  motivo VARCHAR(255) NOT NULL,
  usuario_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ind_pag_cand FOREIGN KEY (candidatura_id) REFERENCES candidaturas(id) ON DELETE CASCADE,
  CONSTRAINT fk_ind_pag_user FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  token_hash VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_password_reset_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS auditoria_usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  actor_usuario_id INT DEFAULT NULL,
  target_usuario_id INT DEFAULT NULL,
  action VARCHAR(80) NOT NULL,
  details TEXT DEFAULT NULL,
  ip VARCHAR(45) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_auditoria_actor FOREIGN KEY (actor_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT fk_auditoria_target FOREIGN KEY (target_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS setores (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(140) NOT NULL,
  slug VARCHAR(160) NOT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_setores_nome (nome),
  UNIQUE KEY uk_setores_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cargos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(160) NOT NULL,
  slug VARCHAR(180) NOT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_cargos_nome (nome),
  UNIQUE KEY uk_cargos_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS colaboradores (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(180) NOT NULL,
  matricula VARCHAR(30) NULL,
  codigo VARCHAR(30) NULL,
  cpf VARCHAR(11) NULL,
  slug VARCHAR(200) NOT NULL,
  cargo_id INT NOT NULL,
  empresa_id INT DEFAULT NULL,
  setor_id INT DEFAULT NULL,
  salario_atual DECIMAL(12,2) NULL,
  data_admissao DATE NULL,
  data_inicio_cargo DATE NULL,
  data_nascimento DATE NULL,
  data_demissao DATE NULL,
  motivo_rescisao VARCHAR(255) NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_colaboradores_slug (slug),
  KEY idx_colaboradores_cargo_id (cargo_id),
  KEY idx_colaboradores_empresa_id (empresa_id),
  KEY idx_colaboradores_setor_id (setor_id),
  KEY idx_colaboradores_codigo (codigo),
  KEY idx_colaboradores_cpf (cpf),
  KEY idx_colaboradores_empresa_codigo (empresa_id, codigo),
  KEY idx_colaboradores_empresa_matricula (empresa_id, matricula),
  KEY idx_colaboradores_cpf_admissao (cpf, data_admissao),
  KEY idx_colaboradores_empresa_cpf_ativo (empresa_id, cpf, ativo, data_demissao),
  KEY idx_colaboradores_data_admissao (data_admissao),
  KEY idx_colaboradores_data_demissao (data_demissao),
  CONSTRAINT fk_colaboradores_cargo FOREIGN KEY (cargo_id) REFERENCES cargos(id) ON DELETE RESTRICT,
  CONSTRAINT fk_colaboradores_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE SET NULL,
  CONSTRAINT fk_colaboradores_setor FOREIGN KEY (setor_id) REFERENCES setores(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Usuário admin padrão (ajuste e remova em produção)
-- UPDATE este bloco após criação para definir senha com bcrypt em PHP.
