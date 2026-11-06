-- Migration: 2026-11-06-exames-admissionais.sql
-- Objetivo:
--   Fluxo "Carta Proposta -> Exame Admissional": depois do aceite eletrônico da Carta Proposta
--   (ver 2026-11-06-cartas-propostas.sql), o RH cadastra o agendamento do exame admissional
--   (clínica, endereço, data, horário, telefone de contato) e prepara/confirma o envio da
--   mensagem oficial ao candidato, pelo mesmo mecanismo de Comunicacao/MensagemService já usado
--   pela Carta Proposta — sem integração automática de envio.
--
--   `exames_admissionais`: UMA linha por candidatura (reagendar é editar os dados desta mesma
--   linha, nunca criar outra) — diferente da Carta Proposta, que versiona porque o PDF e o aceite
--   precisam ficar imutáveis por versão; aqui não há PDF nem aceite do candidato, só um
--   agendamento que o RH pode corrigir. status: rascunho -> mensagem_preparada -> enviado. Editar
--   qualquer um dos 5 campos de agendamento enquanto já havia mensagem_preparada/enviado invalida
--   a preparação anterior (volta para rascunho) — feito em ExameAdmissionalService, nunca aqui.
--
--   `exame_admissional_historico`: trilha de auditoria (criado/atualizado/reagendado/
--   mensagem_preparada/envio_confirmado), com snapshot JSON de antes/depois dos 5 campos de
--   agendamento — evita duplicar colunas do agendamento numa tabela de histórico à parte.
--
--   Compatibilidade: MySQL 8.4.x (dev) e MariaDB 11.8.9-log (produção), CREATE TABLE IF NOT
--   EXISTS idempotente, mesmo padrão de 2026-11-06-cartas-propostas.sql.
--
--   Produção: NÃO aplicada ainda — aplicação manual, como de costume.

CREATE TABLE IF NOT EXISTS exames_admissionais (
  id INT AUTO_INCREMENT PRIMARY KEY,
  candidatura_id INT NOT NULL,
  clinica VARCHAR(160) NULL,
  endereco VARCHAR(255) NULL,
  data_exame DATE NULL,
  horario TIME NULL,
  telefone_contato VARCHAR(30) NULL,
  status ENUM('rascunho','mensagem_preparada','enviado') NOT NULL DEFAULT 'rascunho',
  comunicacao_id INT NULL,
  mensagem_preparada_em DATETIME NULL,
  enviado_em DATETIME NULL,
  criado_por_usuario_id INT NOT NULL,
  enviado_por_usuario_id INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_exames_admissionais_candidatura (candidatura_id),
  KEY idx_exames_admissionais_status (status),
  CONSTRAINT fk_exames_admissionais_candidatura FOREIGN KEY (candidatura_id) REFERENCES candidaturas(id) ON DELETE CASCADE,
  CONSTRAINT fk_exames_admissionais_comunicacao FOREIGN KEY (comunicacao_id) REFERENCES comunicacoes(id) ON DELETE SET NULL,
  CONSTRAINT fk_exames_admissionais_criador FOREIGN KEY (criado_por_usuario_id) REFERENCES usuarios(id),
  CONSTRAINT fk_exames_admissionais_enviou FOREIGN KEY (enviado_por_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS exame_admissional_historico (
  id INT AUTO_INCREMENT PRIMARY KEY,
  exame_admissional_id INT NOT NULL,
  tipo_evento VARCHAR(30) NOT NULL,
  dados_anteriores TEXT NULL,
  dados_novos TEXT NULL,
  observacoes TEXT NULL,
  usuario_id INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_exame_hist_exame FOREIGN KEY (exame_admissional_id) REFERENCES exames_admissionais(id) ON DELETE CASCADE,
  CONSTRAINT fk_exame_hist_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
