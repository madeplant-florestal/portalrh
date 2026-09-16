-- Migration: 2026-09-16-pesquisa-experiencia.sql
-- Objetivo:
--   Sprint "Histórico de Comunicação + Experiência do Candidato + Integração do Colaborador".
--   Cria `pesquisas_experiencia`: uma pesquisa de satisfação por PARTICIPAÇÃO no processo
--   seletivo (`candidatura_id`, não CPF/candidato global — o mesmo candidato pode responder uma
--   pesquisa por vaga em que participou). `UNIQUE KEY` em `candidatura_id` impede mais de uma
--   pesquisa por participação a nível de banco (defesa em profundidade, além da checagem de
--   aplicação em `PesquisaExperienciaService::criarParaParticipacao()`).
--
--   Três critérios independentes (1 a 5, nunca uma nota única/global): `nota_clareza`,
--   `nota_tempo_retorno`, `nota_atendimento`. `comentarios` opcional.
--
--   Acesso público sem login via TOKEN — mesmo padrão de segurança de `password_resets`
--   (2026-06-xx, ver app/models/PasswordReset.php): só o HASH (`token_hash`, sha256) fica no
--   banco, nunca o token bruto. O token bruto (aleatório, 32 bytes/64 hex, `random_bytes()`) não é
--   derivado de CPF/ID do candidato/telefone — vai só na URL enviada ao candidato
--   (`/experiencia/{token}`), nunca fica gravado em texto puro no Portal.
--
--   `respondida_em NULL` = pesquisa pendente de resposta; setado = respondida, e o token deixa de
--   aceitar novo envio (checado na aplicação). SEM expiração nesta versão (não há requisito de
--   negócio para isso ainda — evita complexidade desnecessária).
--
--   Compatibilidade: escrita para MySQL 8.4.x (dev) e MariaDB 11.8.9-log (produção).
--     - `CREATE TABLE IF NOT EXISTS` (idempotente nos dois bancos).
--     - `TINYINT` para as notas (1-5, validado só na aplicação — sem CHECK, mesmo motivo já
--       registrado em migrations anteriores: evitar divergência de suporte a CHECK entre os dois
--       bancos quando a coluna participa de qualquer lógica adicional).
--
--   Produção: MariaDB 11.8.9-log (u172743873_portalrh) — NÃO aplicada ainda. Aguardando aplicação
--   manual antes do push.

CREATE TABLE IF NOT EXISTS pesquisas_experiencia (
  id INT AUTO_INCREMENT PRIMARY KEY,
  candidatura_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  nota_clareza TINYINT NULL,
  nota_tempo_retorno TINYINT NULL,
  nota_atendimento TINYINT NULL,
  comentarios TEXT NULL,
  respondida_em DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_pesquisas_experiencia_candidatura (candidatura_id),
  UNIQUE KEY uk_pesquisas_experiencia_token (token_hash),
  CONSTRAINT fk_pesquisas_experiencia_candidatura FOREIGN KEY (candidatura_id) REFERENCES candidaturas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
