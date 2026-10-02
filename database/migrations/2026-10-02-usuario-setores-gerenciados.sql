-- Migration: 2026-10-02-usuario-setores-gerenciados.sql
-- Objetivo:
--   Bloco 7 (2026-10, pedido do RH) — nova relação explícita "Setores gerenciados": quais Setores
--   oficiais (catálogo local `setores`, espelhado do METADADOS) um usuário do Portal tem
--   responsabilidade GERENCIAL sobre. É a base do filtro por Gestor no Dashboard de Integração e no
--   People Analytics: `Usuário gestor -> Setores gerenciados -> colaboradores_metadados.codigo_setor`.
--
--   Decisão de negócio (RH, 2026-10): não existe vínculo individual colaborador -> gestor confiável
--   no METADADOS (investigado e descartado no Bloco 7 original). A responsabilidade gerencial passa
--   a ser declarada explicitamente pelo RH/Admin no cadastro do usuário, por Setor — nunca inferida.
--
--   Propositalmente SEPARADA de três relações existentes, que continuam com sua própria semântica,
--   sem nenhuma reinterpretação automática:
--     - `usuario_setores` (Setor principal + Setores adicionais de atuação) — contexto
--       organizacional/escopo manual do PRÓPRIO usuário, não hierarquia gerencial sobre outros;
--     - `usuarios.gestor_usuario_id` (Gestor Imediato) — hierarquia 1:1 entre usuários do Portal;
--     - cadastro legado de líderes (`usuario_colaboradores.lider_colaborador_id`) — já descontinuado
--       em outras telas.
--   Nenhum dado dessas três fontes é migrado/convertido automaticamente para esta tabela nova — os
--   setores gerenciados começam vazios, configurados explicitamente pelo RH/Admin a partir de agora.
--
--   Chave estável: `setor_id` é a FK para o catálogo local `setores` (mesmo padrão de
--   `usuario_setores.setor_id`), cuja coluna `codigo_setor` é o código oficial do METADADOS — nunca
--   se armazena nome textual do setor aqui. Mesmo setor pode ter MAIS de um gestor (responsabilidade
--   gerencial compartilhada é permitida de propósito — não existe exclusividade 1 setor = 1 gestor).
--
--   Idempotente (CREATE TABLE IF NOT EXISTS). Mesma estrutura replicada em
--   `app/core/SchemaManager.php` (schema fragmentado em três fontes — ver CLAUDE.md), para que um
--   ambiente novo (fresh install/teste) já nasça com a tabela.
--
--   Depende de `usuarios` e `setores` (schema já existente). NÃO aplicada em produção nesta rodada.
--   Rollback: 2026-10-02-usuario-setores-gerenciados-rollback.sql.

CREATE TABLE IF NOT EXISTS usuario_setores_gerenciados (
  usuario_id INT NOT NULL,
  setor_id INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (usuario_id, setor_id),
  KEY idx_usuario_setores_gerenciados_setor (setor_id),
  CONSTRAINT fk_usuario_setores_gerenciados_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  CONSTRAINT fk_usuario_setores_gerenciados_setor FOREIGN KEY (setor_id) REFERENCES setores(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
