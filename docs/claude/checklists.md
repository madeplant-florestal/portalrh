# Checklists — RH Madeplant

> Consultar antes de reportar concluído, e sempre que a tarefa tocar banco, deploy, segurança,
> performance ou testes.

## Revisão (obrigatório antes de reportar "concluído")

- [ ] O diff foi lido linha a linha, não só executado.
- [ ] Nenhuma funcionalidade existente foi removida ou alterada sem aprovação.
- [ ] Nenhum efeito colateral em módulos que compartilham tabela/model/helper.
- [ ] `Auth::requireRole()` está presente e correto em toda ação nova de controller admin
      (lembrando que supervisor real sempre ignora a lista, ver `arquitetura.md`).
- [ ] `Security::csrfCheck()` está presente em toda ação `POST`.
- [ ] Toda saída para HTML passa por `Security::e()` (sem XSS).
- [ ] Toda query usa prepared statement com parâmetros (sem SQLi).
- [ ] Migration + rollback criados, se houve mudança estrutural de banco.
- [ ] Nenhum segredo (senha, chave, token) foi commitado ou logado em texto plano.
- [ ] Riscos de segurança identificados foram reportados ao usuário antes de qualquer correção
      ser aplicada.
- [ ] Testes relevantes (PHP e/ou Playwright) passam.
- [ ] Autocrítica registrada: bugs potenciais, regressões, e melhorias futuras notadas mas fora de
      escopo (documentadas, não implementadas silenciosamente).

## Alterações de banco

- [ ] Migration criada em `database/migrations/AAAA-MM-DD-descricao.sql`.
- [ ] Rollback criado como `AAAA-MM-DD-descricao-rollback.sql`.
- [ ] Mudança usa `IF NOT EXISTS`/`IF EXISTS` onde aplicável e suportado pelo servidor alvo (ver
      ressalva de compatibilidade MySQL 8.4.3 em `padroes-codigo.md`), para ser idempotente e
      segura de reexecutar.
- [ ] Dados existentes preservados — nenhuma perda implícita.
- [ ] Se a mudança é destrutiva (DROP/TRUNCATE/remoção de coluna com dados): aprovação explícita
      e isolada obtida, **não** empacotada dentro da aprovação geral da feature.
- [ ] Impacto em downtime avaliado — preferir `ALTER TABLE` compatível com produção viva a
      operações bloqueantes longas.
- [ ] Se a mudança envolve dado sensível (PII, financeiro), avaliado se precisa de
      `Cipher::encrypt()`/coluna `*_encrypted`, seguindo o padrão já usado em
      `solicitacoes_vaga`/`movimentacoes_pessoal`.
- [ ] Se for correção de dados em massa, seguir o padrão transacional + auditoria da migration
      `2026-06-25-colaboradores-data-backfill-atomic.sql` (validação de FK órfã, staging em
      tabela temporária, log de erro dedicado).
- [ ] Testado localmente contra uma cópia do schema antes de considerar pronto para produção.

## Deploy

- [ ] `npm run build:css` executado (gera `assets/tailwind.css` e copia para
      `public/assets/tailwind.css`).
- [ ] `scripts/preflight.php` (ou `scripts/deploy_quick.ps1`, que já o invoca) rodado e todos os
      checks `[OK]`: PHP ≥ 8.1, extensão `pdo_mysql`, arquivos essenciais presentes,
      conectividade de banco.
- [ ] `app/config/config.php` de produção revisado — sem placeholders do `config.example.php`.
- [ ] `logging.viewer_key` configurada (protege `/logs.php`) e nunca exposta publicamente.
- [ ] `logging.alert_email` configurado para alertas de `ERROR`/`CRITICAL`.
- [ ] Diretórios graváveis confirmados: `storage/*` e `public/uploads` (o bootstrap tenta criá-los
      com `@mkdir`, mas permissões de servidor podem bloquear).
- [ ] Se via cPanel (`.cpanel.yml`): confirmar que `storage/` no destino não é sobrescrito (o
      script já preserva `storage` explicitamente, não altere essa exclusão sem entender o
      motivo).
- [ ] Pós-deploy: `/` responde, `/install.php` responde (e é removido/travado se a instalação já
      foi concluída), `/logs.php?key=...` acessível só com a chave correta, log JSONL do dia
      sendo criado em `storage/logs/`.
- [ ] Migrations pendentes aplicadas manualmente no banco de produção (não há runner automático de
      migrations).

## Segurança

- [ ] **Autenticação**: fluxo de login não contorna `email_verified_at`; timeout de inatividade
      (1200s) preservado; `session_regenerate_id()` mantido em pontos de elevação de privilégio.
- [ ] **Autorização**: toda ação sensível protegida por `Auth::requireRole()` com a lista de
      papéis correta — ciente de que supervisor real sempre passa (ver `arquitetura.md`), então
      nunca dependa de `requireRole` para *excluir* admin/supervisor de uma ação.
- [ ] **CSRF**: todo `POST` que muta estado chama `Security::csrfCheck()` antes de qualquer efeito
      colateral.
- [ ] **XSS**: toda interpolação de dado dinâmico em view passa por `Security::e()`; nunca
      imprimir `$_GET`/`$_POST`/dado de banco cru em HTML.
- [ ] **SQL Injection**: nenhuma concatenação de input em SQL; nomes de tabela/coluna dinâmicos só
      a partir de whitelist fixa no código.
- [ ] **Upload**: MIME real validado via `finfo` (não apenas extensão), tamanho máximo respeitado,
      arquivo salvo com nome não previsível ou higienizado, currículos fora do document root
      público (`storage/`), logos dentro do público mas com nome aleatório.
- [ ] **Sessões**: cookie `httponly` + `SameSite=Lax` + `secure` quando HTTPS; sessão armazenada em
      `storage/sessions/` (fora do document root público).
- [ ] **Criptografia de dados sensíveis**: campos `*_encrypted` usam `Cipher` (AES-256-CBC);
      confirme que `security.data_encryption_key` está definida em produção — sem ela, `Cipher`
      usa chave derivada fraca (risco conhecido, ver `riscos-conhecidos.md`).
- [ ] **Auditoria**: ações administrativas sensíveis (troca de senha por admin, mudança de role,
      correção de dados em massa) registradas via `AuditLog` ou tabela de auditoria dedicada.
- [ ] **Segredos**: nenhuma senha/chave/token em texto plano commitado; `Logger` redige
      automaticamente por nome de chave — não desative isso.
- [ ] Qualquer risco de segurança identificado — mesmo incidental à tarefa — foi **reportado ao
      usuário antes** de qualquer correção ser implementada.
- [ ] Se a tarefa envolver o METADADOS (SQL Server): confirmar que só `SELECT` é emitido contra a
      origem — nunca INSERT/UPDATE/DELETE/DDL (ver `roadmap-tecnico.md`).

## Performance

- [ ] Queries de listagem usam paginação (`paginateAdmin` ou equivalente) — não carregam tabela
      inteira em memória. `Colaborador::all()` sem filtro é um atalho para até 100 registros; não
      use para listagens irrestritas.
- [ ] `JOIN`s preferidos a N+1 queries em loop de PHP.
- [ ] Índices considerados para colunas usadas em `WHERE`/`ORDER BY` frequentes (ver padrão em
      `2026-06-23-colaboradores-required-columns.sql`, que adiciona índices dedicados para
      `codigo`, `cpf`, `data_admissao`, `data_demissao`).
- [ ] `SchemaManager::ensure()` e `ensure*Schema()` dos models fazem checagem via
      `INFORMATION_SCHEMA` a cada primeira chamada por request — evite adicionar novas checagens
      desse tipo em caminhos de alta frequência sem necessidade.
- [ ] Assets front-end: CSS gerado via Tailwind com `content` restrito a `app/views/**/*.php` —
      classes usadas apenas via JS dinâmico devem entrar no `safelist` do `tailwind.config.js`,
      senão são purgadas no build.
- [ ] Operações de dados em massa (import XLSX, backfill, sincronização METADADOS) rodam em
      lote/streaming quando o volume justificar, não carregando o dataset inteiro sem necessidade.

## Testes

- [ ] **PHP** (`tests/php/*.php`): scripts standalone, sem framework. Rodam contra banco real via
      `require bootstrap.php`. Convenção de nome: `unit_*.php` (lógica pura, sem banco) e
      `integration_*.php` (toca banco/models/services). Devem imprimir `OK <nome>` e `exit(0)`, ou
      `fwrite(STDERR, ...)` + `exit(1)` em falha. Execute individualmente via
      `php tests/php/nome_do_teste.php` — não há runner agregador hoje.
- [ ] **JS unitário** (`tests/unit/*.test.js`): scripts Node standalone, assertions manuais, sem
      Jest. Agregados nos scripts `test:unit` do `package.json`.
- [ ] **Visual/E2E** (`tests/*.spec.js`): Playwright, `npm run test:visual`. Screenshots
      comparados com `maxDiffPixelRatio: 0.02` — mudanças visuais legítimas exigem
      `npm run test:visual:update` para atualizar snapshots (revisar o diff visual antes de
      aceitar).
- [ ] Nova funcionalidade de backend com regra de negócio: adicionar `integration_*.php` cobrindo
      caminho feliz + pelo menos uma rejeição.
- [ ] Nova tela ou alteração visual: cobrir com `*.spec.js` se o módulo já tiver um spec análogo (a
      maioria dos módulos admin já tem, ver `tests/`).
- [ ] Antes de reportar concluído, rodar os testes relevantes localmente — não assumir que passam.
