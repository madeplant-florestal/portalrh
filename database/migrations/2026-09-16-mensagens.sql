-- Migration: 2026-09-16-mensagens.sql
-- Objetivo:
--   Sprint "Módulo de Mensagens do Processo Seletivo". O Portal RH passa a ser a fonte OFICIAL
--   dos textos das mensagens automáticas de Recrutamento e Seleção — o n8n deixa de guardar cópia
--   própria do texto. Esta migration cria só o armazenamento; o disparo automático via
--   n8n/Evolution API fica para uma sprint futura (ver docs/claude/arquitetura.md).
--
--   Cria `mensagens`: um template de mensagem por linha, identificado por um CÓDIGO TÉCNICO
--   estável (`codigo`, nunca editável pela interface depois de criado — só título/descrição/
--   conteúdo/ativo mudam). Placeholders (`[Nome]`, `[Data]`, ...) vivem dentro do próprio
--   `conteudo` — não há coluna por variável (ver app/services/MensagemService.php).
--
--   Compatibilidade: escrita para MySQL 8.4.x (dev) e MariaDB 11.8.9-log (produção).
--     - `CREATE TABLE IF NOT EXISTS` (idempotente nos dois bancos).
--     - Sem ENUM (pedido explícito da sprint — nada trava etapas/tipos no schema).
--     - `TEXT` para `descricao`/`conteudo`: suporta mensagens longas de WhatsApp; `utf8mb4`
--       suporta emoji (4 bytes) nos dois bancos.
--     - Sem FK para nenhuma tabela do domínio de Recrutamento/METADADOS — mensagens é um catálogo
--       independente, só referenciado por CÓDIGO (string), nunca por ID, para não acoplar o
--       conteúdo a um Kanban/estágio específico.
--
--   Produção: MariaDB 11.8.9-log (u172743873_portalrh) — NÃO aplicada ainda. Aguardando aplicação
--   manual antes do push (ver processo da sprint, mesmo fluxo de 2026-09-15-permissoes-individuais).

CREATE TABLE IF NOT EXISTS mensagens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(80) NOT NULL,
  titulo VARCHAR(160) NOT NULL,
  descricao TEXT NULL,
  conteudo TEXT NOT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_mensagens_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
