-- Migration: 2026-11-05-candidatura-documentos.sql
-- Objetivo:
--   Correção solicitada pelo RH (validação de 06/11/2026): permitir anexar múltiplos documentos
--   de contratação (RG, CPF, comprovantes etc.) a uma candidatura quando ela chega à etapa
--   Aprovado — mas disponível na tela de detalhamento a qualquer momento, não um upload único
--   amarrado à transição de etapa (pipeline_stages é dirigido por dados, sem constante "Aprovado"
--   no código). Os arquivos permanecem vinculados à candidatura mesmo que ela avance para outras
--   etapas depois.
--
--   Não existe estrutura de "anexos"/"documentos" genérica no projeto para reaproveitar
--   (Upload::savePdf() é hardcoded para currículo único, sem tabela própria) — esta é uma tabela
--   nova, dedicada a este domínio. N:1 com candidaturas (múltiplos arquivos por candidatura).
--
--   Storage fora de public/, bloqueado por .htaccess, mesmo padrão de storage/resumes/.
--
--   Idempotente (CREATE TABLE IF NOT EXISTS). Depende de `candidaturas` e `usuarios`.
--   Rollback: 2026-11-05-candidatura-documentos-rollback.sql.

CREATE TABLE IF NOT EXISTS candidatura_documentos (
  id                      INT AUTO_INCREMENT PRIMARY KEY,
  candidatura_id          INT          NOT NULL,
  nome_original           VARCHAR(255) NOT NULL,
  nome_armazenado         VARCHAR(64)  NOT NULL,
  mime                    VARCHAR(100) NOT NULL,
  tamanho_bytes           INT          NOT NULL,
  enviado_por_usuario_id  INT          NOT NULL,
  criado_em               DATETIME     NOT NULL,
  KEY idx_canddoc_candidatura (candidatura_id),
  CONSTRAINT fk_canddoc_candidatura FOREIGN KEY (candidatura_id) REFERENCES candidaturas(id) ON DELETE CASCADE,
  CONSTRAINT fk_canddoc_usuario FOREIGN KEY (enviado_por_usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
