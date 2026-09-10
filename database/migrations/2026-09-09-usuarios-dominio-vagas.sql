-- Migration: 2026-09-09-usuarios-dominio-vagas.sql
-- Objetivo:
--   Sprint "Usuários/Lideranças independentes de colaboradores" — move o domínio de
--   AUTORIZAÇÃO e HIERARQUIA da Solicitação de Vaga da tabela `usuario_colaboradores`
--   (1:1 com `colaboradores`, inviável para gestor PJ/terceiro) para `usuarios`.
--
--   Colunas novas (todas aditivas):
--     pode_solicitar_vaga     -> autorização canônica para abrir Solicitação de Vaga.
--                                Substitui usuario_colaboradores.pode_solicitar_vaga, que
--                                permanece só como legado temporário (nada é removido nesta sprint).
--     colaborador_metadados_id -> vínculo OPCIONAL com o contrato oficial do METADADOS
--                                (`colaboradores_metadados.id`). NULL é plenamente válido:
--                                gestor PJ/terceiro opera sem vínculo. UNIQUE (nullable) impede
--                                dois usuários apontando para o mesmo contrato oficial.
--     aprovador_usuario_id     -> líder imediato / aprovador da 1ª etapa, apontando para outro
--                                `usuarios.id`. Opcional. A proteção contra autoapontamento
--                                (aprovador_usuario_id <> id) e contra ciclo direto A<->B é
--                                aplicada na camada de aplicação (User::setVagaAccess): o MySQL
--                                8.4 recusa um CHECK sobre uma coluna que já participa de FK com
--                                ON DELETE SET NULL (erro 3823).
--
--   NÃO altera `usuario_colaboradores` (sem DROP, sem mudança de coluna).
--   NÃO cria/remove nenhuma linha de `colaboradores`.
--
--   Compatibilidade: escrita/testada em MySQL 8.4.3 (dev) — `ADD COLUMN IF NOT EXISTS` gera erro
--   1064 nesse servidor, então usa ALTER TABLE puro (mesmo padrão das migrations de 2026-08-27 em
--   diante). Reexecutar falha com "Duplicate column", comportamento esperado. A rede de segurança
--   em runtime (SchemaManager::ensure) só adiciona as COLUNAS simples em instalações antigas —
--   FK/UNIQUE permanecem responsabilidade exclusiva desta migration formal.
--
--   Produção: MariaDB 11.8.9-log (u172743873_portalrh) — aplicada e validada em 10/09/2026.
--   Pré-voo: 29 usuários, 1 vínculo ativo em usuario_colaboradores. Pós-backfill: pode_solicitar_vaga=1
--   em 1 usuário, aprovador_usuario_id em 0 (nenhum par legado resolvível), colaborador_metadados_id
--   em 0; 0 autoapontamentos; solicitações/aprovações intactas.
--   Nenhuma instrução SQL abaixo foi alterada depois da aplicação em produção.

ALTER TABLE usuarios
  ADD COLUMN pode_solicitar_vaga TINYINT(1) NOT NULL DEFAULT 0 AFTER role,
  ADD COLUMN colaborador_metadados_id INT NULL AFTER pode_solicitar_vaga,
  ADD COLUMN aprovador_usuario_id INT NULL AFTER colaborador_metadados_id;

ALTER TABLE usuarios
  ADD UNIQUE KEY uk_usuarios_colaborador_metadados (colaborador_metadados_id),
  ADD KEY idx_usuarios_aprovador (aprovador_usuario_id),
  ADD CONSTRAINT fk_usuarios_colaborador_metadados
      FOREIGN KEY (colaborador_metadados_id) REFERENCES colaboradores_metadados(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_usuarios_aprovador
      FOREIGN KEY (aprovador_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL;

-- Backfill 1 — autorização: quem já podia solicitar vaga pelo vínculo legado mantém a permissão.
UPDATE usuarios u
JOIN usuario_colaboradores uc ON uc.usuario_id = u.id
SET u.pode_solicitar_vaga = 1
WHERE uc.ativo = 1 AND uc.pode_solicitar_vaga = 1;

-- Backfill 2 — aprovador: só quando o relacionamento legado gestor -> líder resolve para um
-- usuário real (o colaborador líder precisa ter uma conta em usuario_colaboradores). Caso não
-- resolva, aprovador_usuario_id fica NULL e a configuração passa a ser feita manualmente em
-- /admin/usuarios (comportamento explícito, sem inferência).
UPDATE usuarios u
JOIN usuario_colaboradores uc  ON uc.usuario_id = u.id AND uc.ativo = 1
JOIN usuario_colaboradores ucl ON ucl.colaborador_id = uc.lider_colaborador_id AND ucl.ativo = 1
SET u.aprovador_usuario_id = ucl.usuario_id
WHERE uc.lider_colaborador_id IS NOT NULL
  AND ucl.usuario_id <> u.id;
