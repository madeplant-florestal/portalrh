# Padrões de Código — RH Madeplant

> Consultar quando a tarefa envolver criação ou refatoração de código. Para regras inegociáveis,
> ver `CLAUDE.md`.

## Nomenclatura

- Domínio em **português**: `Colaborador`, `Cargo`, `Setor`, `Empresa`, `Candidatura`, `Vaga`,
  `SolicitacaoVaga`, `MovimentacaoPessoal`. Não traduza para inglês "por consistência com boas
  práticas" — a convenção do projeto é português para conceitos de domínio.
- Classes PHP: `PascalCase`. Métodos: `camelCase`. Colunas de banco: `snake_case` em português
  (`data_admissao`, `cargo_id`, `email_verified_at`).
- Rotas admin: kebab-case em português sob `/admin/...`.
- Uma classe por arquivo, nome do arquivo = nome da classe.

## Organização

- Autoload automático via `glob()` no bootstrap para
  `dtos/requests/validators/repositories/services/models/controllers` — qualquer classe nova
  nessas pastas carrega sem registro manual. `app/core/*` é carregado por `require_once` explícito
  em `bootstrap.php`; se criar classe de infraestrutura nova em `app/core/`, adicione o
  `require_once` lá.
- Views em `app/views/{admin|home}/{modulo}/{acao}.php`, layouts em `app/views/layouts/`.

## Comentários

- Sem comentários por padrão. Quando necessário, explique o **porquê** (regra de negócio não
  óbvia, workaround de collation, decisão de segurança), nunca o **o quê**. Veja o cabeçalho da
  migration de backfill de colaboradores como exemplo do nível de documentação esperado em SQL
  complexo.
- Identificadores e mensagens de erro voltadas ao usuário: sempre em português.

## Tratamento de erros / exceções

- Exceções de validação de negócio: `InvalidArgumentException` com mensagem pronta para exibição
  ao usuário (padrão do módulo Setor/CargoSetor).
- Exceções de infraestrutura (upload, criptografia): `RuntimeException`.
- Controllers capturam `Throwable` ao redor de operações de escrita e re-renderizam o formulário
  com o erro, ou fazem `redirect()` com `?erro=`.
- Nunca deixe uma exceção vazar como stack trace ao usuário em produção — o handler global já
  cobre isso, não duplique `try/catch` genéricos desnecessários em volta de cada chamada.

## Logs

- Use `Logger` (`Logger::info/warning/error/critical`) para eventos que importam para
  operação/auditoria, não `error_log()` direto, exceto no próprio bootstrap.
- Nomeie chaves de contexto de forma que a redação automática de segredos funcione (ver
  `arquitetura.md`, seção Logs).

## SQL / PDO

- **Sempre** prepared statements com parâmetros posicionais (`?`) — padrão 100% do código atual.
  Não introduza named parameters nem query builder.
- **Nunca** concatene input do usuário em SQL, nem para nomes de tabela/coluna dinâmicos — valide
  contra whitelist primeiro (ver `CadastroOrganizacional`/`AdminCatalogosController` para o padrão
  de tabela dinâmica controlada).
- Transações: abra no `Service`, nunca no `Repository`; sempre `rollBack()` em
  `catch (Throwable)` antes de relançar.
- Para lotes de correção/migração de dados em produção, siga o padrão da migration
  `2026-06-25-colaboradores-data-backfill-atomic.sql`: transação única, validação de FKs órfãs
  antes de aplicar, auditoria old/new por registro, tabela de log de erro dedicada.

## Banco de dados — convenções de nomenclatura e estrutura

- Tabelas: plural em português, snake_case (`colaboradores`, `setores`, `cargo_setores`,
  `solicitacoes_vaga`, `movimentacoes_pessoal`).
- Chaves estrangeiras: `{tabela_singular}_id` (ex.: `empresa_id`, `cargo_id`, `setor_id`).
- Charset/collation padrão em migrations recentes: `utf8mb4` / `utf8mb4_unicode_ci` — bases
  legadas podem estar em `utf8mb4_bin` ou `utf8mb4_general_ci`; ao escrever SQL que compara texto
  entre tabelas de gerações diferentes, force a collation explicitamente (padrão
  `CONVERT(... USING utf8mb4) COLLATE utf8mb4_unicode_ci` na migration de backfill).
- Toda tabela nova: `id` auto-incremento como PK, `created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP`
  no mínimo; `updated_at` quando a entidade é mutável.
- Relacionamentos N:N: padrão `{tabelaA}_{tabelaB}` (ex.: `cargo_setores`), com repository dedicado
  (`CargoSetorRepository`) em vez de lógica de pivot espalhada pelos models das duas pontas.
- Migrations idempotentes sempre que possível (`CREATE TABLE IF NOT EXISTS`, checagem via
  `INFORMATION_SCHEMA` antes de `ALTER TABLE ADD COLUMN`) — o projeto depende disso pois não há
  tabela de controle de migrations aplicadas. **Atenção**: `ADD COLUMN IF NOT EXISTS`/
  `DROP COLUMN IF EXISTS` falham com erro de sintaxe 1064 no MySQL 8.4.3 usado em desenvolvimento
  (testado empiricamente em 2026-08) — para coluna que nunca existiu antes, use `ALTER TABLE`
  puro e documente a limitação no próprio arquivo da migration.
