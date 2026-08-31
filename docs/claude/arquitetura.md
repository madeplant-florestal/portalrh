# Arquitetura Detalhada — RH Madeplant

> Consultar quando a tarefa envolver: mudança estrutural, autenticação, autorização, rotas,
> controllers, models/services, banco de dados, logs ou uploads. Para regras que valem sempre,
> ver `CLAUDE.md`. Onde houver dúvida entre este documento e o código, o código vence.

## Visão geral e domínios

RH Madeplant é um sistema de RH sob medida em PHP puro (sem framework, sem Composer geral, sem
ORM) para o grupo Madeplant. Cobre três domínios:

1. **Recrutamento público** — vitrine de vagas (`/vagas`, `/vaga/{id}`) e candidatura de currículo
   (`/candidatar/{id}`), com pipeline Kanban interno de triagem e programa de indicação de
   candidatos com controle financeiro (pagamento de indicações).
2. **Dados mestres organizacionais e de folha** — `empresas`, `setores`, `cargos` (N:N via
   `cargo_setores`) e `colaboradores` (tabela hub do sistema).
3. **Fluxos de aprovação de RH com assinatura eletrônica** — `SolicitacaoVaga` (abertura de vaga,
   aprovação líder + RH) e `MovimentacaoPessoal` (promoções/transferências/desligamentos, com
   assinatura RH), além de avaliações de desempenho e gestão de benefícios.

Também expõe uma API de webhooks de recrutamento (`app/services/RecruitmentWebhook*`) e uma
central administrativa completa (`/admin/**`).

## Objetivos do sistema

- Centralizar o processo de recrutamento e seleção, do anúncio da vaga até a contratação, com
  rastreabilidade de cada etapa.
- Manter fonte única e confiável de dados cadastrais de colaboradores/cargos/setores/empresas, com
  integridade referencial.
- Formalizar digitalmente fluxos antes manuais (solicitação de vaga, movimentação de pessoal,
  avaliação de desempenho) com aprovação em etapas e assinatura eletrônica.
- Suporte financeiro ao programa de indicação de colaboradores (cálculo, conciliação, relatórios).
- Controle de acesso baseado em papéis (admin/rh/viewer) com auditoria de ações sensíveis.
- Operável por equipe pequena, deploy simples via cPanel/FTP, sem containers/filas/serviços
  externos.

## Organização de diretórios

```
index.php                  # Front controller único; registra ~90 rotas manualmente
install.php / logs.php     # Instalador web e visualizador de logs protegido por chave
router.php                 # Usado pelo servidor embutido do PHP em desenvolvimento
app/
  core/                    # Infraestrutura: Auth, Security, Database, Router, View...
  controllers/             # Um Admin*Controller por módulo administrativo + público
  models/                  # Padrão legado: classes estáticas com lógica de domínio
  repositories/            # Padrão novo: acesso a dados via PDO, injeção de conexão
  services/                # Padrão novo: orquestração de domínio + transações
  dtos/                    # Padrão novo: objetos de dados imutáveis pós-validação
  requests/                # Padrão novo: normalização de input bruto ($_POST)
  validators/              # Padrão novo: regras de validação de domínio
  views/                   # Templates PHP puro (sem engine), admin/ e home/
  config/                  # config.php (produção), local.php (opcional), build.php
database/
  schema.sql               # Schema "canônico" declarado (14 tabelas)
  recrutamento.sql          # Dump com dados reais de recrutamento (ver riscos-conhecidos.md)
  migrations/               # Migrations incrementais datadas, cada uma com rollback
assets/                     # Fontes JS/CSS versionadas (compiladas para public/assets)
public/                     # Document root real do deploy (index.php, assets, uploads)
storage/                    # logs, sessions, resumes, ratelimit, audit, imports, reconciliation
scripts/                    # CLI utilitários (deploy, import, reset de senha, preflight, sync)
tests/                      # tests/php (integração/unitário PHP) + *.spec.js (Playwright)
dist/                       # Saída de build gerada por scripts/deploy — não editar à mão
vendor/                     # Só Dompdf (Composer escopado, ver CLAUDE.md §3) — versionado no Git
```

## Stack oficial (detalhado)

| Camada | Tecnologia | Observações |
|---|---|---|
| Linguagem | PHP 8.1+ | Sem Composer geral, sem autoload PSR-4 — autoload manual via `glob()` no bootstrap |
| Banco de dados | MySQL/MariaDB | Acesso exclusivo via PDO (`ATTR_EMULATE_PREPARES=false`) |
| Frontend | HTML + Tailwind CSS 3.4.x | Compilado via PostCSS/`tailwindcss` CLI, sem framework de build (sem Vite/Webpack) |
| JavaScript | Vanilla JS | `assets/admin.js`, `assets/public.js`, `assets/phone-utils.js`, `assets/share-utils.js` — sem framework, sem bundler, sem TypeScript |
| Templates | PHP puro (`.php` em `app/views`) | Sem Blade/Twig/engine — `extract()`+`include`, escaping manual via `Security::e()` |
| E-mail | `mail()` nativo do PHP via classe `Mailer` | Sem PHPMailer/SMTP dedicado |
| Testes de unidade/integração PHP | Scripts standalone em `tests/php/*.php` | Sem PHPUnit — cada script faz `require bootstrap.php`, roda contra banco real, `exit(1)` em falha |
| Testes de unidade JS | Scripts Node standalone em `tests/unit/*.test.js` | Sem Jest/Mocha — assertions manuais |
| Testes visuais/E2E | Playwright (`@playwright/test` ^1.55) | `tests/*.spec.js`, screenshots com `maxDiffPixelRatio: 0.02` |
| Deploy | cPanel (`.cpanel.yml`) + scripts PowerShell (`deploy.ps1`, `scripts/deploy_quick.ps1`) | Também há instalador web (`install.php`) para setup inicial |

Regras de bloqueio e exceções pontuais aprovadas: ver `CLAUDE.md` §3.

## Fluxo da aplicação

1. Toda requisição entra por `index.php` (ou `public/index.php` em produção). `app/core/bootstrap.php`
   é `require`ado primeiro: define constantes de path, carrega `Config`, inicia `Logger`,
   `Security::startSecureSession()`, registra handlers globais de exceção/erro/shutdown, carrega
   `vendor/autoload.php` se existir (Dompdf), e faz autoload manual via `glob()` de
   `dtos/requests/validators/repositories/services/models/controllers`.
2. `index.php` faz um **gate de autenticação bruto antes do router**: se o path começa com
   `/admin` e não é rota pública de auth (login/logout/recuperação), e o usuário não está logado,
   redireciona para `/login` — antes de qualquer rota ser resolvida.
3. Um `Router` simples é instanciado; todas as rotas são registradas via `$router->get(...)` /
   `$router->post(...)` em texto plano dentro do próprio `index.php`. Sem arquivo de rotas
   separado, sem agrupamento por controller.
4. `$router->dispatch()` casa a rota por igualdade exata primeiro, depois por regex `{param}`. Sem
   correspondência: 404 texto puro.
5. O handler instancia o Controller e chama o método. **Não há middleware.** Cada método é
   responsável por chamar `Auth::requireRole([...])` no início e, se POST,
   `Security::csrfCheck($_POST['csrf'] ?? '')` — repetido manualmente em cada ação.
6. O Controller monta dados e chama `$this->view->render($template, $params, $layout)`.
   `View::render` usa `extract()` + `ob_start()`/`include` — sem engine de template, sem escaping
   automático (cada view chama `Security::e()` explicitamente).

## Roteamento

- Definido inteiramente em `index.php` (raiz), não em `app/`.
- Rotas admin: `/admin/{recurso-kebab-case}/{ação-opcional}`, em português (ex.:
  `/admin/movimentacoes-pessoal/{id}/assinar-rh`, `/admin/setores/{setorId}/cargos/vincular`).
- Rotas de API: `/api/{recurso}/{ação}`, retornam JSON direto do controller (sem serialização
  dedicada).
- Padrão CRUD por recurso: `GET /recurso` (index), `GET/POST /recurso/novo` (create/store),
  `GET/POST /recurso/editar/{id}` (edit/update), `POST /recurso/excluir/{id}` (delete). Siga esse
  padrão para qualquer novo catálogo administrativo.

## Controllers

- Todos estendem `Controller` (`app/core/Controller.php`), que só instancia `View $view` — sem DI,
  sem service container, sem construtor customizado além do trivial.
- `AdminCatalogosController` (abstrato) está **fisicamente em `app/core/`**, não em
  `app/controllers/` — inconsistência conhecida e histórica; **não mova o arquivo** como parte de
  tarefa não relacionada.
- Controllers de catálogo simples (Empresas, Cargos) estendem `AdminCatalogosController` e
  delegam para `CadastroOrganizacional` (model genérico por nome de tabela). Setores está em
  transição para Service/Repository (`AdminSetoresController` + `SetorService`) — **não force a
  migração de outros catálogos** só porque um já foi migrado.
- Cada ação repete: `Auth::requireRole([...])` → parse/sanitize de `$_GET`/`$_POST` → chamada a
  Model/Service → `$this->view->render(...)` ou `redirect(...)`. Mantenha esse formato.

## Models (geração legada)

- Classes **estáticas** (sem instância, sem interface, sem injeção): `Colaborador`, `User`,
  `SolicitacaoVaga`, `MovimentacaoPessoal`, `Candidatura`, `Vaga`, `AvaliacaoDesempenho`,
  `Beneficio`, `PipelineStage`, `PasswordReset`, `AuditLog`, `CadastroOrganizacional`.
- Contêm a lógica de negócio **diretamente** (validação, regras, SQL via PDO) — sem repositório
  separado.
- Vários se auto-migram: métodos `ensureXxxSchema()` fazem `ALTER TABLE` idempotente guardado por
  `SELECT ... FROM INFORMATION_SCHEMA.COLUMNS` antes de qualquer query de negócio (ex.:
  `Colaborador::ensureRhSchema()`). **O schema real em produção pode ter colunas que só existem por
  causa desse auto-migrate**, além do que está em `database/schema.sql` e nas migrations. Ao
  investigar uma coluna, procure também `ensure*Schema()` no model relevante.
- Paginação administrativa: `paginateAdmin(array $filters, int $page, int $perPage)` retornando
  `['items' => [], 'total' => int, 'page' => int, 'per_page' => int, 'pages' => int]`. Reutilize
  esse contrato em qualquer listagem nova.

## Repositories / Services / DTOs / Requests / Validators (geração nova)

Usado em `Setor`, `CargoSetor`, `Empresa` (parcialmente), webhooks de recrutamento e a integração
com o METADADOS (`ColaboradorMetadadosRepository`/`MetadadosSyncService`/
`ColaboradorMetadadosReconciliationService`, ver `roadmap-tecnico.md`). Fluxo de uma escrita (ex.:
criar Setor):

```
Controller
  → Service->create($_POST bruto)
      → new Request($input)          // normaliza/sanitiza tipos
      → Validator->validate($request) // valida regra de negócio, lança InvalidArgumentException
          → retorna DTO imutável (ex.: SetorData)
      → Repository->create($dto)      // única camada que toca PDO
      → tudo dentro de $pdo->beginTransaction()/commit()/rollBack()
```

- `Repository` recebe `?PDO $pdo = null` no construtor, default `Database::conn()` — permite
  injetar conexão de teste, mas hoje **nenhum teste faz mock de PDO**; testes de integração rodam
  contra banco real.
- `Service` é o único ponto que abre/fecha transação. Repository nunca chama `beginTransaction()`.
- Erros de validação de negócio: sempre `InvalidArgumentException` com mensagem em português
  pronta para exibição — o Controller captura e renderiza de volta no formulário.
- Ao adicionar módulo novo tipo catálogo (CRUD simples, filtro, paginação, export), **prefira este
  padrão** ao legado — mas confirme com o usuário se já existe código legado análogo a seguir em
  vez disso.

## Banco de dados

- Acesso 100% via `Database::conn()`, singleton `PDO` com `ATTR_EMULATE_PREPARES => false` e
  `ATTR_ERRMODE => EXCEPTION`. 100% prepared statements — nenhuma concatenação de input do usuário
  em SQL encontrada em auditoria.
- **Não existe fonte única de verdade de schema.** Coexistem: `database/schema.sql` (14 tabelas,
  "de instalação"); `database/recrutamento.sql` + `recrutamento.sql` (raiz) — dump real de
  produção com PII, priorizado pelo instalador; 18+ migrations incrementais; `SchemaManager::ensure()`
  e vários `ensure*Schema()` nos models, que alteram tabelas em runtime na primeira chamada.
  Resultado: ~35 tabelas ao todo, sem tabela de controle de migrations aplicadas. **Antes de
  assumir que uma coluna não existe, verifique nos três lugares.**
- Tabela hub: `colaboradores` (FK obrigatória para `cargos`, opcional para `empresas`/`setores`;
  ganhou `metadados_id` opcional na integração com o METADADOS, ver `roadmap-tecnico.md`).
- Campos sensíveis (justificativas/contexto salarial em `solicitacoes_vaga` e
  `movimentacoes_pessoal`) são colunas `*_encrypted` via `Cipher` (AES-256-CBC), descriptografadas
  na leitura.
- **Instalador web** (`install.php` + `app/core/Installer.php`) prioriza os dumps de
  `recrutamento.sql` sobre `database/schema.sql` quando ambos existem — contém dados reais de
  produção. Cuidado ao rodar contra ambiente que não deveria receber esses dados.
- Existem **dois arquivos idênticos** de dump de recrutamento (`database/recrutamento.sql` e
  `recrutamento.sql` na raiz) — editar um não propaga para o outro.
- `dist/` contém saída de build já gerada (provavelmente por `scripts/deploy_quick.ps1`) — não é
  código-fonte, não edite dentro dele; edite a fonte e regere o build.

## Autenticação

- `Auth::attemptLogin(email, senha)` — busca `User` por e-mail, exige `email_verified_at`
  preenchido, verifica senha via `password_verify`, estabelece sessão.
- `Auth::establishSession()` normaliza `role` (trim + lowercase). `$_SESSION['user_is_supervisor']`
  vem da coluna `usuarios.is_supervisor` do banco — **role `admin` sozinha não promove mais a
  supervisor** (corrigido na Sprint de Segurança 2026-07; antes, qualquer `role === 'admin'`
  virava supervisor em sessão, independente do banco — ver
  `tests/php/integration_auth_supervisor_session.php`). Exceção que continua intacta: se `role`
  vier vazia e o e-mail bater com `security.supervisor_email`, promove a admin **e** supervisor
  nesse caso (repara contas antigas sem role — ver `AUTH_PERMISSOES_FIX.md`).
  `session_regenerate_id(true)` a cada login.
- Papéis existentes: só `admin`, `rh`, `viewer` — validados contra whitelist fixa normalizada para
  minúsculas.
- Sessão arquivo-based em `storage/sessions/`, cookie `httponly`, `SameSite=Lax`, `secure`
  detectado por HTTPS/proxy. Timeout de inatividade 1200s (`Security::enforceInactivityTimeout`),
  verificado a cada request no bootstrap.

## Autorização

- `Auth::requireRole(array $roles)` é chamado **manualmente no início de cada método** que precisa
  de restrição — sem decorator/atributo/middleware central.
- `requireRole()` retorna imediatamente se `$_SESSION['user_is_supervisor']` for truthy — só
  acontece para quem tem `usuarios.is_supervisor = 1` no banco (ou o fallback de e-mail legado),
  não mais para todo `role === 'admin'`. Hoje nenhuma chamada de `requireRole()` exclui `'admin'`
  da lista permitida, então esse bypass não muda comportamento observável nas rotas atuais — mas
  ao desenhar restrição nova que precise excluir admins comuns (só o supervisor real), lembre que
  esse bypass continua existindo para quem tem a flag de supervisor.
- Rotas públicas de auth (`/login`, `/admin/login`, `/forgot-password`, `/reset-password/{token}`
  e variantes `/admin/*`) são as únicas isentas do gate de sessão em `index.php`.

## Auditoria

- `AuditLog::log($actorUserId, $targetUserId, $action, $details, $ip)` grava em
  `auditoria_usuarios` (auto-criada por `SchemaManager::ensure()`).
- Hoje só é chamado explicitamente em pontos sensíveis de `User` (troca de senha administrativa,
  tentativa bloqueada de alterar role de supervisor). **Não é usado de forma abrangente** — não
  assuma que toda ação administrativa gera auditoria sem verificar.
- A migration `2026-06-25-colaboradores-data-backfill-atomic.sql` estabelece um padrão mais rico
  (tabela dedicada `colaboradores_data_fix_auditoria` + `colaboradores_data_fix_error_log`,
  populada dentro de stored procedure transacional) para correções de dados em massa — use como
  referência para qualquer correção de dados em lote futura.

## Logs

- `Logger` (`app/core/Logger.php`) grava JSON Lines em `storage/logs/app-AAAA-MM-DD.jsonl`, um
  arquivo por dia, nível mínimo configurável (`logging.level`, default `INFO`).
- Redação automática de valores sensíveis por nome de chave (`password`, `senha`, `token`, `csrf`,
  `cookie`, `authorization`, etc.) e truncamento de strings longas (>600 chars) em
  `Logger::redact()`. Ao logar contexto novo, nomeie as chaves de forma que a redação funcione
  (ex.: `senha_atual`, não `campo_1`).
- Erros PHP fatais/handled e exceções não tratadas são interceptados globalmente no bootstrap
  (`set_exception_handler`, `set_error_handler`, `register_shutdown_function`), sempre logados,
  com resposta genérica em produção (`display_errors=0`) e detalhada em `env=dev`.
- Alertas por e-mail (`mail()` nativo) disparam em `ERROR`/`CRITICAL` se `logging.alert_email`
  estiver configurado.
- `logs.php` (raiz) expõe os últimos 300 registros do dia via `?key=`, comparado com
  `hash_equals()` contra `logging.viewer_key`. **Nunca** exponha essa chave em links, commits ou
  documentação.

## Uploads

- `Upload::savePdf()` — currículos, em `storage/resumes/` (fora do document root público). Valida
  MIME real via `finfo`, extensão, tamanho máximo (`security.max_upload_bytes`). Nome derivado de
  nome/vaga higienizado ou aleatório (`bin2hex(random_bytes(16))`) se dados insuficientes.
- `Upload::saveImage()` — logos, em `public/uploads/logos/` (dentro do document root). Mesmas
  validações, nome sempre aleatório.
- Ambas lançam `RuntimeException` em português em qualquer falha de validação — o controller
  decide como exibir.

## Ferramentas do projeto (operacional)

- **Não há Composer nem `vendor/` de propósito geral**: todo autoload é manual, exceto a exceção
  escopada de Dompdf (ver `CLAUDE.md` §3). `composer require` de qualquer outra dependência exige
  aprovação explícita — não é tarefa incidental.
- **`tests/php` não tem runner agregador** — cada script roda individualmente via CLI PHP.
