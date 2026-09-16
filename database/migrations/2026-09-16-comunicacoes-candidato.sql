-- Migration: 2026-09-16-comunicacoes-candidato.sql
-- Objetivo:
--   Sprint "Histórico de Comunicação + Experiência do Candidato + Integração do Colaborador".
--   Cria `comunicacoes`: HISTÓRICO IMUTÁVEL de comunicações efetivamente preparadas/enviadas a um
--   candidato — nunca confundir com `mensagens` (os TEMPLATES editáveis). Cada linha aqui guarda
--   uma FOTOGRAFIA (`conteudo`) do texto já renderizado no momento — se o template em `mensagens`
--   for editado depois, o histórico não muda (por isso `mensagem_id` é só referência opcional,
--   nunca a fonte do texto exibido).
--
--   Vínculo com PARTICIPAÇÃO no processo, não com um cadastro "candidato" global: `candidatura_id`
--   aponta para `candidaturas` (entidade já existente que representa a inscrição de um candidato
--   numa vaga específica — o mesmo CPF pode ter várias linhas em `candidaturas`, uma por processo).
--
--   `situacao` é a situação PRINCIPAL do envio (preparada/enviada/erro) — "entregue" e
--   "visualizada" são TIMESTAMPS complementares (`entregue_em`/`visualizada_em`), nunca marcados
--   por presunção: ficam NULL até existir confirmação real de um provedor (Evolution API), o que
--   NÃO é implementado nesta sprint (nenhum webhook criado agora).
--
--   `origem` distingue MANUAL (ação humana — `usuario_id` obrigatório) de AUTOMATICA (nenhum
--   usuário fake "Sistema" — `usuario_id` fica NULL).
--
--   Compatibilidade: escrita para MySQL 8.4.x (dev) e MariaDB 11.8.9-log (produção).
--     - `CREATE TABLE IF NOT EXISTS` (idempotente nos dois bancos).
--     - ENUM para `situacao`/`origem`: domínio pequeno e fechado (mesmo padrão de
--       `usuario_setores.origem`, 2026-09-10). `canal`/`identificador_externo` ficam VARCHAR (sem
--       ENUM) porque canais futuros (e-mail, SMS) e formatos de ID de provedor não são fixos.
--     - `conteudo`/`erro_mensagem` em TEXT: suportam texto longo, emoji, quebras de linha
--       (utf8mb4 nos dois bancos).
--
--   Produção: MariaDB 11.8.9-log (u172743873_portalrh) — NÃO aplicada ainda. Aguardando aplicação
--   manual antes do push.

CREATE TABLE IF NOT EXISTS comunicacoes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  candidatura_id INT NOT NULL,
  mensagem_id INT NULL,
  conteudo TEXT NOT NULL,
  canal VARCHAR(30) NOT NULL DEFAULT 'whatsapp',
  situacao ENUM('preparada', 'enviada', 'erro') NOT NULL DEFAULT 'preparada',
  origem ENUM('MANUAL', 'AUTOMATICA') NOT NULL DEFAULT 'MANUAL',
  usuario_id INT NULL,
  enviada_em DATETIME NULL,
  entregue_em DATETIME NULL,
  visualizada_em DATETIME NULL,
  identificador_externo VARCHAR(191) NULL,
  erro_mensagem TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_comunicacoes_candidatura (candidatura_id),
  KEY idx_comunicacoes_mensagem (mensagem_id),
  KEY idx_comunicacoes_usuario (usuario_id),
  CONSTRAINT fk_comunicacoes_candidatura FOREIGN KEY (candidatura_id) REFERENCES candidaturas(id) ON DELETE CASCADE,
  CONSTRAINT fk_comunicacoes_mensagem FOREIGN KEY (mensagem_id) REFERENCES mensagens(id) ON DELETE SET NULL,
  CONSTRAINT fk_comunicacoes_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
