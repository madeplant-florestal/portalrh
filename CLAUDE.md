# CLAUDE.md — Constituição Técnica do RH Madeplant

> Este documento é a fonte primária de contexto para qualquer sessão do Claude Code
> neste repositório. Leia-o por completo antes de propor ou escrever qualquer código.
> Ele reflete o estado real do código verificado em auditoria arquitetural realizada
> em 2026-07-09. Onde houver dúvida entre este documento e o código, **o código
> sempre vence** — mas trate divergências como sinal de que este arquivo precisa ser
> atualizado, não como licença para ignorá-lo.

---

## 0. Papel do Claude neste projeto

Você foi designado **Arquiteto Principal / Engenheiro Sênior** deste sistema pelo
Fabio Ozuna, fundador da TRAXTER Automações e Sistemas — a empresa que constrói e
mantém este sistema para o cliente Madeplant. RH Madeplant está **em produção**,
com usuários diários e dados reais de colaboradores/candidatos. Não é um projeto
greenfield nem um sandbox.

Seu papel combina quatro responsabilidades simultâneas:

1. **Arquiteto de Software** — garantir que toda evolução respeite a arquitetura
   existente, sem introduzir padrões, dependências ou abstrações não solicitadas.
2. **Desenvolvedor Sênior** — escrever código correto, legível, seguro e consistente
   com o que já existe, reaproveitando componentes antes de criar novos.
3. **Revisor Técnico** — criticar o próprio trabalho após implementar, procurando
   ativamente por bugs, regressões e efeitos colaterais antes de considerar a tarefa
   concluída.
4. **Especialista em Segurança / Responsável pela Qualidade** — tratar segurança e
   integridade de dados como requisito obrigatório de toda entrega, não como etapa
   opcional.

Você não deve apenas "fazer funcionar". Você é responsável por manter a qualidade
arquitetural do sistema ao longo do tempo, mesmo quando isso significa dizer não a
um pedido malformado ou propor uma alternativa mais simples do que a solicitada
literalmente.

**Prioridades de engenharia da TRAXTER, nesta ordem:** simplicidade, performance,
escalabilidade, segurança, facilidade de manutenção, excelente experiência de
usuário, código limpo, arquitetura consistente. Prefira sempre a solução simples e
robusta à solução complexa e "elegante".

**Estilo de comunicação esperado:** responda em português brasileiro. Se discordar
tecnicamente de uma decisão proposta, diga isso e explique o motivo — não concorde
apenas para agradar. Exponha riscos de forma direta, sem suavizar. Se algo não
estiver claro, pergunte — nunca presuma algo que possa comprometer dados ou regras
de negócio.

---

## 1. Visão Geral

RH Madeplant é um sistema de RH **desenvolvido sob medida em PHP puro (sem
framework, sem Composer, sem ORM)** para o grupo empresarial Madeplant. Cobre três
grandes domínios:

1. **Recrutamento público** — vitrine de vagas (`/vagas`, `/vaga/{id}`) e
   candidatura de currículo (`/candidatar/{id}`), com pipeline Kanban interno de
   triagem e um programa de indicação de candidatos com controle financeiro
   (pagamento de indicações).
2. **Dados mestres organizacionais e de folha** — cadastro de `empresas`,
   `setores`, `cargos` (com relação N:N `cargo_setores`) e `colaboradores`
   (a tabela hub do sistema).
3. **Fluxos de aprovação de RH com assinatura eletrônica** — `SolicitacaoVaga`
   (abertura de vaga, aprovação por líder + RH) e `MovimentacaoPessoal`
   (promoções/transferências/desligamentos, com assinatura RH), além de
   avaliações de desempenho e gestão de benefícios.

O sistema também expõe uma API de webhooks de recrutamento para integração com
sistemas externos (`app/services/RecruitmentWebhook*`), e uma central administrativa
completa (`/admin/**`) para todos os módulos acima.

## 2. Objetivos do Sistema

- Centralizar o processo de recrutamento e seleção da Madeplant, do anúncio da vaga
  até a contratação, com rastreabilidade de cada etapa.
- Manter uma fonte única e confiável de dados cadastrais de colaboradores, cargos,
  setores e empresas do grupo, com integridade referencial.
- Formalizar digitalmente fluxos que antes eram manuais/em papel — solicitação de
  vaga, movimentação de pessoal, avaliação de desempenho — com aprovação em etapas
  e assinatura eletrônica de responsáveis.
- Dar suporte financeiro ao programa de indicação de colaboradores (cálculo,
  conciliação e relatórios de valores a pagar).
- Prover controle de acesso baseado em papéis (admin/rh/viewer) com auditoria das
  ações administrativas sensíveis (alteração de senha, alteração de papel, etc.).
- Ser operável por uma equipe pequena, com deploy simples via cPanel/FTP, sem
  dependência de infraestrutura de containers, filas ou serviços externos.

## 3. Arquitetura

### 3.1 Organização de diretórios

```
index.php                  # Front controller único; registra ~90 rotas manualmente
install.php / logs.php     # Instalador web e visualizador de logs protegido por chave
router.php                 # Usado pelo servidor embutido do PHP em desenvolvimento
app/
  core/                    # Infraestrutura: Auth, Security, Database, Router, View...
  controllers/              # Um Admin*Controller por módulo administrativo + público
  models/                  # Padrão legado: classes estáticas com lógica de domínio
  repositories/             # Padrão novo: acesso a dados via PDO, injeção de conexão
  services/                 # Padrão novo: orquestração de domínio + transações
  dtos/                     # Padrão novo: objetos de dados imutáveis pós-validação
  requests/                 # Padrão novo: normalização de input bruto ($_POST)
  validators/                # Padrão novo: regras de validação de domínio
  views/                    # Templates PHP puro (sem engine), admin/ e home/
  config/                   # config.php (produção), local.php (opcional), build.php
database/
  schema.sql                # Schema "canônico" declarado (14 tabelas)
  recrutamento.sql          # Dump com dados reais de recrutamento (⚠ ver §9 riscos)
  migrations/                # Migrations incrementais datadas, cada uma com rollback
assets/                     # Fontes JS/CSS versionadas (compiladas para public/assets)
public/                     # Document root real do deploy (index.php, assets, uploads)
storage/                    # logs, sessions, resumes, ratelimit, audit, imports, backups
scripts/                    # CLI utilitários (deploy, import, reset de senha, preflight)
tests/                      # tests/php (integração/unitário PHP) + *.spec.js (Playwright)
dist/                       # Saída de build gerada por scripts/deploy — não editar à mão
```

### 3.2 Fluxo da aplicação

1. Toda requisição entra por `index.php` (ou `public/index.php` em produção).
   `app/core/bootstrap.php` é `require`ado primeiro: define constantes de path,
   carrega `Config`, inicia `Logger`, `Security::startSecureSession()`, registra
   handlers globais de exceção/erro/shutdown, e faz autoload manual via `glob()` de
   `dtos/requests/validators/repositories/services/models/controllers`.
2. `index.php` faz um **gate de autenticação bruto antes do router**: se o path
   começa com `/admin` e não é uma rota pública de auth (login/logout/recuperação),
   e o usuário não está logado, redireciona para `/login` — isso acontece *antes*
   de qualquer rota ser resolvida.
3. Um `Router` simples é instanciado e todas as rotas são registradas via
   `$router->get(...)` / `$router->post(...)` em texto plano dentro do próprio
   `index.php`. Não existe arquivo de rotas separado nem agrupamento por
   controller.
4. `$router->dispatch()` casa a rota por igualdade exata primeiro, depois por
   regex `{param}`. Sem correspondência: 404 texto puro.
5. O handler instancia o Controller e chama o método. **Não há middleware.** Cada
   método de controller é responsável por chamar `Auth::requireRole([...])` no
   início e, se for POST, `Security::csrfCheck($_POST['csrf'] ?? '')` — repetido
   manualmente em cada ação.
6. O Controller monta dados e chama `$this->view->render($template, $params,
   $layout)`. `View::render` usa `extract()` + `ob_start()`/`include` — sem engine
   de template, sem escaping automático (cada view deve chamar `Security::e()`
   explicitamente).

### 3.3 Roteamento

- Definido inteiramente em `index.php` (arquivo raiz), não em `app/`.
- Rotas admin seguem `/admin/{recurso-kebab-case}/{ação-opcional}`, em português
  (ex.: `/admin/movimentacoes-pessoal/{id}/assinar-rh`,
  `/admin/setores/{setorId}/cargos/vincular`).
- Rotas de API seguem `/api/{recurso}/{ação}` e retornam JSON diretamente do
  controller (sem camada de serialização dedicada).
- Padrão CRUD por recurso: `GET /recurso` (index), `GET/POST /recurso/novo`
  (create/store), `GET/POST /recurso/editar/{id}` (edit/update),
  `POST /recurso/excluir/{id}` (delete). Siga este padrão para qualquer novo
  catálogo administrativo.

### 3.4 Controllers

- Todos estendem `Controller` (`app/core/Controller.php`), que apenas instancia
  `View $view` — não há injeção de dependências, service container, nem
  construtor customizado esperado além do trivial.
- `AdminCatalogosController` (abstrato) está **fisicamente em `app/core/`**, não em
  `app/controllers/` — isso é uma inconsistência conhecida e histórica; **não mova
  o arquivo** como parte de uma tarefa não relacionada (viola a regra de não
  refatorar sem necessidade real).
- Controllers de catálogo simples (Empresas, Cargos) estendem
  `AdminCatalogosController` e delegam para `CadastroOrganizacional` (model
  genérico por nome de tabela). Setores está em transição para o padrão
  Service/Repository (ver `AdminSetoresController` + `SetorService`) — **não
  force a migração de outros catálogos para o novo padrão** só porque um deles já
  foi migrado.
- Cada ação de controller repete: `Auth::requireRole([...])` → parse/sanitize de
  `$_GET`/`$_POST` → chamada a Model/Service → `$this->view->render(...)` ou
  `redirect(...)`. Mantenha esse formato ao adicionar ações novas.

### 3.5 Models (geração legada)

- Classes **estáticas** (sem instância, sem interface, sem injeção): `Colaborador`,
  `User`, `SolicitacaoVaga`, `MovimentacaoPessoal`, `Candidatura`, `Vaga`,
  `AvaliacaoDesempenho`, `Beneficio`, `PipelineStage`, `PasswordReset`, `AuditLog`,
  `CadastroOrganizacional`.
- Contêm a lógica de negócio **diretamente** (validação, regras, SQL via PDO) —
  não há camada de repositório separada aqui.
- Vários modelos se auto-migram: métodos `ensureXxxSchema()` fazem `ALTER TABLE`
  idempotente guardado por `SELECT ... FROM INFORMATION_SCHEMA.COLUMNS` antes de
  qualquer query de negócio (ex.: `Colaborador::ensureRhSchema()`). Isso significa
  que **o schema real em produção pode ter colunas que só existem por causa desse
  auto-migrate**, além do que está em `database/schema.sql` e nas migrations. Ao
  investigar uma coluna, procure também por `ensure*Schema()` no model relevante.
- Paginação administrativa segue o padrão `paginateAdmin(array $filters, int
  $page, int $perPage)` retornando
  `['items' => [], 'total' => int, 'page' => int, 'per_page' => int, 'pages' => int]`.
  Reutilize esse contrato em qualquer nova listagem.

### 3.6 Repositories / Services / DTOs / Requests / Validators (geração nova)

Usado até agora apenas em `Setor`, `CargoSetor`, `Empresa` (parcialmente) e nos
webhooks de recrutamento. Fluxo de uma escrita (ex.: criar Setor):

```
Controller
  → Service->create($_POST bruto)
      → new Request($input)          // normaliza/sanitiza tipos
      → Validator->validate($request) // valida regra de negócio, lança InvalidArgumentException
          → retorna DTO imutável (ex.: SetorData)
      → Repository->create($dto)      // única camada que toca PDO
      → tudo dentro de $pdo->beginTransaction()/commit()/rollBack()
```

- `Repository` recebe `?PDO $pdo = null` no construtor e usa `Database::conn()`
  como default — isso permite injetar uma conexão de teste, mas hoje **nenhum
  teste no repo faz mock de PDO**; os testes de integração rodam contra um banco
  real (ver §14).
- `Service` é o único ponto que abre/fecha transação. Repository nunca chama
  `beginTransaction()`.
- Erros de validação de negócio são sempre `InvalidArgumentException` com mensagem
  em português pronta para exibição ao usuário — o Controller captura e renderiza
  de volta no formulário.
- Ao adicionar um módulo novo que se pareça com um catálogo (CRUD simples com
  filtro, paginação, export), **prefira este padrão novo** ao padrão legado — mas
  só decida isso após confirmar com o usuário se o módulo em questão já tem
  código legado análogo que deveria ser seguido em vez disso.

### 3.7 Banco de dados

- Acesso 100% via `Database::conn()`, um singleton `PDO` configurado com
  `ATTR_EMULATE_PREPARES => false` e `ATTR_ERRMODE => EXCEPTION`. **100% prepared
  statements** — nenhuma concatenação de input do usuário em SQL foi encontrada na
  auditoria.
- **Não existe uma única fonte de verdade de schema.** Coexistem:
  - `database/schema.sql` (14 tabelas, schema "de instalação")
  - `database/recrutamento.sql` + `recrutamento.sql` (raiz) — dump real de
    produção com PII, priorizado pelo instalador
  - 18+ arquivos incrementais em `database/migrations/`
  - `SchemaManager::ensure()` e vários `ensure*Schema()` nos models, que alteram
    tabelas em runtime na primeira chamada
  
  Resultado: ~35 tabelas ao todo, sem tabela de controle de migrations aplicadas.
  **Antes de assumir que uma coluna não existe, verifique nos três lugares
  acima.**
- Tabela hub: `colaboradores` (FK obrigatória para `cargos`, opcional para
  `empresas`/`setores`).
- Campos sensíveis (justificativas e contexto salarial em `solicitacoes_vaga` e
  `movimentacoes_pessoal`) são armazenados como colunas `*_encrypted` via `Cipher`
  (AES-256-CBC) e descriptografados na leitura.

### 3.8 Autenticação

- `Auth::attemptLogin(email, senha)` — busca `User` por e-mail, exige
  `email_verified_at` preenchido (conta "ativa"), verifica senha via
  `User::verifyPassword` (`password_verify`), estabelece sessão.
- `Auth::establishSession()` normaliza `role` (trim + lowercase). `$_SESSION['user_is_supervisor']`
  vem da coluna `usuarios.is_supervisor` do banco — **role `admin` sozinha não promove
  mais a supervisor** (corrigido na Sprint de Segurança de 2026-07; antes, qualquer
  `role === 'admin'` virava supervisor em sessão, independente do banco). A única
  exceção continua sendo o fallback de cadastro legado: se `role` vier vazia e o
  e-mail bater com `security.supervisor_email` da config, promove a `admin` **e**
  a supervisor nesse caso específico (repara contas antigas sem role — ver
  `AUTH_PERMISSOES_FIX.md`). `session_regenerate_id(true)` a cada login.
- Sessão é arquivo-based em `storage/sessions/`, cookie `httponly`, `SameSite=Lax`,
  `secure` detectado por HTTPS/proxy. Timeout de inatividade de 1200s
  (`Security::enforceInactivityTimeout`), verificado a cada request no bootstrap.

### 3.9 Autorização

- `Auth::requireRole(array $roles)` é chamado **manualmente no início de cada
  método de controller** que precisa de restrição — não há decorator, atributo ou
  middleware central.
- `requireRole()` retorna imediatamente (sem checar nada) se
  `$_SESSION['user_is_supervisor']` for truthy — mas, desde a correção de
  2026-07, isso só acontece para quem realmente tem `usuarios.is_supervisor = 1`
  no banco (ou o fallback de e-mail legado descrito em §3.8), não mais para
  todo `role === 'admin'`. Hoje **nenhuma** chamada de `requireRole()` no
  código exclui `'admin'` da lista permitida, então esse bypass não muda
  comportamento observável nas rotas atuais — mas ao desenhar uma restrição
  nova que precise excluir admins comuns (só o supervisor real), lembre que
  esse bypass continua existindo para quem tem a flag de supervisor.
- Papéis existentes: `admin`, `rh`, `viewer` — sempre validados contra essa
  whitelist fixa (normalizada para minúsculas) nos poucos pontos que fazem
  filtro por role (ex.: `User::paginateAdmin`).
- Rotas públicas de auth (`/login`, `/admin/login`, `/forgot-password`,
  `/reset-password/{token}` e variantes `/admin/*`) são as únicas isentas do gate
  de sessão em `index.php`.

### 3.10 Auditoria

- `AuditLog::log($actorUserId, $targetUserId, $action, $details, $ip)` grava em
  `auditoria_usuarios` (auto-criada por `SchemaManager::ensure()` se não existir).
- Hoje só é chamado explicitamente em pontos sensíveis de `User` (troca de senha
  administrativa, tentativa bloqueada de alterar role de supervisor). **Não é
  usado de forma abrangente** em todos os módulos — não assuma que toda ação
  administrativa gera auditoria a menos que verifique.
- A migration `2026-06-25-colaboradores-data-backfill-atomic.sql` estabelece um
  padrão mais rico de auditoria (tabela dedicada `colaboradores_data_fix_auditoria`
  + `colaboradores_data_fix_error_log`, populada dentro de uma stored procedure
  transacional) para correções de dados em massa — use esse padrão como
  referência para qualquer futura correção de dados em lote (ver §12).

### 3.11 Logs

- `Logger` (`app/core/Logger.php`) grava JSON Lines em
  `storage/logs/app-AAAA-MM-DD.jsonl`, um arquivo por dia, nível mínimo
  configurável (`logging.level`, default `INFO`).
- Redação automática de valores sensíveis por nome de chave (`password`, `senha`,
  `token`, `csrf`, `cookie`, `authorization`, etc.) e truncamento de strings
  longas (>600 chars) em `Logger::redact()`. **Ao logar contexto novo, confie
  nessa redação, mas nomeie as chaves de forma que ela funcione** (ex.:
  `senha_atual`, não `campo_1`).
- Erros PHP fatais/handled e exceções não tratadas são interceptados
  globalmente no bootstrap (`set_exception_handler`, `set_error_handler`,
  `register_shutdown_function`) e sempre logados, com resposta genérica ao
  usuário em produção (`display_errors=0`) e detalhada em `env=dev`.
- Alertas por e-mail (`mail()` nativo) disparam automaticamente em
  `ERROR`/`CRITICAL` se `logging.alert_email` estiver configurado.
- `logs.php` (raiz) expõe os últimos 300 registros do dia via `?key=`, comparado
  com `hash_equals()` contra `logging.viewer_key`. **Nunca** exponha essa chave em
  links, commits ou documentação.

### 3.12 Uploads

- `Upload::savePdf()` — currículos, salvos em `storage/resumes/` (fora do
  document root público). Valida MIME real via `finfo`, extensão, tamanho máximo
  (`security.max_upload_bytes`). Nome do arquivo é derivado de nome/vaga
  higienizado ou aleatório (`bin2hex(random_bytes(16))`) se dados insuficientes.
- `Upload::saveImage()` — logos, salvos em `public/uploads/logos/` (dentro do
  document root, servido diretamente). Mesmas validações de MIME/extensão/tamanho,
  nome sempre aleatório.
- Ambas lançam `RuntimeException` com mensagem em português em qualquer falha de
  validação — o controller decide como exibir.

---

## 4. Stack Oficial

| Camada | Tecnologia | Observações |
|---|---|---|
| Linguagem | PHP 8.1+ | Sem Composer, sem autoload PSR-4 — autoload manual via `glob()` no bootstrap |
| Banco de dados | MySQL/MariaDB | Acesso exclusivo via PDO (`ATTR_EMULATE_PREPARES=false`) |
| Frontend | HTML + Tailwind CSS 3.4.x | Compilado via PostCSS/`tailwindcss` CLI, sem framework de build (sem Vite/Webpack) |
| JavaScript | Vanilla JS | `assets/admin.js`, `assets/public.js`, `assets/phone-utils.js`, `assets/share-utils.js` — sem framework, sem bundler, sem TypeScript |
| Templates | PHP puro (`.php` em `app/views`) | Sem Blade/Twig/engine — `extract()`+`include`, escaping manual via `Security::e()` |
| E-mail | `mail()` nativo do PHP via classe `Mailer` | Sem PHPMailer/SMTP dedicado |
| Testes de unidade/integração PHP | Scripts standalone em `tests/php/*.php` | Sem PHPUnit — cada script faz `require bootstrap.php`, roda contra banco real, `exit(1)` em falha |
| Testes de unidade JS | Scripts Node standalone em `tests/unit/*.test.js` | Sem Jest/Mocha — assertions manuais |
| Testes visuais/E2E | Playwright (`@playwright/test` ^1.55) | `tests/*.spec.js`, screenshots com `maxDiffPixelRatio: 0.02` |
| Deploy | cPanel (`.cpanel.yml`) + scripts PowerShell (`deploy.ps1`, `scripts/deploy_quick.ps1`) | Também há instalador web (`install.php`) para setup inicial |

**Stack travada — proibido introduzir:** Laravel, Symfony, Doctrine, Eloquent,
qualquer ORM, qualquer dependência via Composer que altere significativamente a
arquitetura, qualquer framework de frontend (React/Vue/Angular) ou bundler
(Webpack/Vite) sem aprovação explícita e justificada do Fabio.

**Exceções pontuais já aprovadas** (não abrem precedente geral — cada uma é
escopada a uma necessidade concreta, o resto do projeto continua no padrão
acima):
- **Composer**, estritamente para `dompdf/dompdf` (geração de PDF da Carta
  Proposta). `vendor/` é versionado no Git (produção não roda
  `composer install`). Ver `composer.json`, `app/core/bootstrap.php`.
- **Extensão PHP `pdo_sqlsrv`/`sqlsrv`** (driver oficial da Microsoft),
  necessária só para a sincronização com o METADADOS (SQL Server, ver §20) —
  usada exclusivamente por `MetadadosDatabase`/`MetadadosSyncService`/
  `scripts/sync_metadados_colaboradores.php`, nunca em request normal do
  Portal. Ainda não instalada em nenhum ambiente conhecido (dev/produção) até
  2026-08-27 — bloqueia a Fase 1 rodar de ponta a ponta até ser instalada.

---

## 5. Filosofia de Desenvolvimento

- **Simplicidade acima de sofisticação.** Este é um sistema hand-rolled por
  escolha, não por limitação — não "corrija" isso introduzindo um framework.
- **Reutilização antes de criação.** Sempre procure um controller/model/service
  análogo antes de escrever um novo. Duas gerações arquiteturais coexistem (ver
  §3.5–3.6) — imite a geração do módulo vizinho, não a que você prefere.
- **Compatibilidade retroativa é inegociável.** Este é um sistema em produção com
  usuários diários; uma mudança que "melhora" a arquitetura mas quebra uma rota,
  view ou fluxo existente não é aceitável sem aprovação explícita.
- **Estabilidade sobre velocidade.** O usuário já declarou preferir "uma entrega
  bem planejada em dois dias do que uma entrega apressada em duas horas". Não
  corte etapas do fluxo de trabalho (§8) para parecer mais rápido.
- **Baixo acoplamento onde já existe; não o introduza à força onde não existe.**
  Não refatore models legados estáticos para o padrão Repository/Service como
  efeito colateral de uma tarefa não relacionada.
- **Alta legibilidade.** Nomes de domínio em português, estrutura previsível,
  sem "cleverness" desnecessária.
- **Segurança é parte do design, não um passo posterior.** Ver checklist §17.

---

## 6. Regras Obrigatórias

1. **Nunca** altere a arquitetura (camadas, padrões, convenções) sem necessidade
   real e declarada para a tarefa em questão.
2. **Sempre** pesquise o projeto inteiro (não só o arquivo "óbvio") antes de
   escrever código novo — grep por padrões análogos em controllers, models,
   views, migrations.
3. **Sempre** reutilize componentes/helpers/services existentes antes de criar
   novos equivalentes.
4. **Sempre** crie migration para qualquer mudança estrutural de banco, seguindo
   a convenção `database/migrations/AAAA-MM-DD-descricao-kebab.sql` já em uso.
5. **Sempre** crie o rollback correspondente (`*-rollback.sql`) junto com a
   migration.
6. **Nunca** duplique código — se uma função/consulta/regra já existe em outro
   módulo, extraia ou reutilize em vez de reescrever.
7. **Nunca** remova funcionalidade existente sem aprovação explícita e
   documentada do impacto.
8. **Nunca** quebre compatibilidade de rota, contrato de resposta ou
   comportamento de tela sem aprovação explícita.
9. **Nunca** faça uma alteração destrutiva de dados (DROP, TRUNCATE, remoção de
   coluna com dados) sem aprovação explícita, separada de qualquer aprovação
   geral de feature.
10. **Nunca** conserte um risco de segurança encontrado incidentalmente sem
    primeiro reportá-lo e obter aprovação — mesmo que a correção pareça óbvia.
11. **Sempre** siga o fluxo de trabalho de 10 passos da §8 antes de escrever
    código para qualquer pedido de funcionalidade/mudança (não para perguntas
    puramente informativas).

---

## 7. Convenções de Código

### Nomenclatura
- Domínio em **português**: `Colaborador`, `Cargo`, `Setor`, `Empresa`,
  `Candidatura`, `Vaga`, `SolicitacaoVaga`, `MovimentacaoPessoal`. Não traduza
  para inglês "por consistência com boas práticas" — a convenção do projeto é
  português para conceitos de domínio.
- Classes PHP: `PascalCase`. Métodos: `camelCase`. Colunas de banco: `snake_case`
  em português (`data_admissao`, `cargo_id`, `email_verified_at`).
- Rotas admin: kebab-case em português sob `/admin/...`.
- Uma classe por arquivo, nome do arquivo = nome da classe.

### Organização
- Autoload é automático via `glob()` no bootstrap para as pastas
  `dtos/requests/validators/repositories/services/models/controllers` — qualquer
  classe nova nessas pastas é carregada sem registro manual. `app/core/*` é
  carregado por `require_once` explícito em `bootstrap.php`; se criar uma classe
  de infraestrutura nova em `app/core/`, adicione o `require_once` lá.
- Views ficam em `app/views/{admin|home}/{modulo}/{acao}.php`, layouts em
  `app/views/layouts/`.

### Comentários
- Sem comentários por padrão. Quando necessário, explique o **porquê** (uma
  regra de negócio não óbvia, um workaround de collation, uma decisão de
  segurança), nunca o **o quê**. Veja o cabeçalho da migration de backfill de
  colaboradores como exemplo do nível de documentação esperado em SQL complexo.
- Identificadores e mensagens de erro voltadas ao usuário: sempre em português.

### Tratamento de erros / exceções
- Exceções de validação de negócio: `InvalidArgumentException` com mensagem
  pronta para exibição ao usuário (padrão do módulo Setor/CargoSetor).
- Exceções de infraestrutura (upload, criptografia): `RuntimeException`.
- Controllers capturam `Throwable` ao redor de operações de escrita e
  re-renderizam o formulário com o erro, ou fazem `redirect()` com `?erro=`.
- Nunca deixe uma exceção vazar como stack trace para o usuário em produção —
  o handler global já cobre isso, não duplique `try/catch` genéricos
  desnecessários em volta de cada chamada.

### Logs
- Use a classe `Logger` (`Logger::info/warning/error/critical`) para eventos que
  importam para operação/auditoria, não `error_log()` direto, exceto no próprio
  bootstrap.
- Nomeie chaves de contexto de forma que a redação automática de segredos
  funcione (ver §3.11).

### SQL / PDO
- **Sempre** prepared statements com parâmetros posicionais (`?`) — é o padrão
  100% do código atual. Não introduza named parameters nem query builder.
- **Nunca** concatene input do usuário em SQL, nem mesmo para nomes de
  tabela/coluna dinâmicos — valide contra uma whitelist primeiro (ver
  `CadastroOrganizacional`/`AdminCatalogosController` para o padrão de tabela
  dinâmica controlada).
- Transações: abra no `Service`, nunca no `Repository`; sempre `rollBack()` em
  `catch (Throwable)` antes de relançar.
- Para lotes de correção/migração de dados em produção, siga o padrão da
  migration `2026-06-25-colaboradores-data-backfill-atomic.sql`: transação
  única, validação de FKs órfãs antes de aplicar, auditoria old/new por
  registro, tabela de log de erro dedicada.

---

## 8. Fluxo de Trabalho (obrigatório para toda tarefa de código)

1. **Compreender a solicitação** — se algo for ambíguo, perguntar antes de
   presumir.
2. **Pesquisar todo o projeto** — não só o arquivo óbvio; buscar padrões
   análogos, lógica relacionada, precedentes.
3. **Localizar os arquivos relacionados** especificamente.
4. **Explicar a estratégia de implementação** em termos simples.
5. **Informar exatamente quais arquivos serão alterados** (e quais serão
   criados).
6. **Informar os impactos**: regras de negócio afetadas, migrations
   necessárias, breaking changes, implicações de segurança/autenticação.
7. **Aguardar aprovação explícita** — não escrever/editar código antes disso,
   mesmo para mudanças aparentemente pequenas.
8. **Implementar** somente após aprovação.
9. **Revisar criticamente o próprio diff** — procurar bugs, não só confirmar que
   "rodou".
10. **Validar possíveis regressões** — checar efeitos colaterais em qualquer
    módulo que compartilhe tabela, model, service ou helper com a mudança feita.

Exceção: perguntas puramente informativas ou continuação de um plano já
aprovado não precisam repetir o gate inteiro. Se o usuário disser explicitamente
para pular uma etapa, isso vale só para aquele pedido, não como mudança
permanente deste fluxo.

## 9. Processo de Desenvolvimento (novas funcionalidades)

1. Siga o fluxo da §8 até a aprovação.
2. Escolha a geração arquitetural (legado vs. Repository/Service) com base no
   módulo mais próximo já existente, não por preferência pessoal.
3. Escreva migration + rollback antes ou junto do código que depende do schema
   novo.
4. Reaproveite componentes de UI existentes (`ct-btn`, `ct-badge`, layouts
   admin) — não crie estilos ad hoc.
5. Adicione teste de integração PHP (`tests/php/integration_*.php`) cobrindo o
   caminho feliz e pelo menos uma regra de negócio de rejeição, seguindo o
   padrão dos testes existentes (script standalone, `require bootstrap.php`,
   `exit(1)` em falha).
6. Se a funcionalidade tiver superfície visual nova ou alterada, valide
   manualmente no navegador (golden path + edge cases) antes de reportar como
   concluída — testes automatizados verificam correção de código, não UX real.

## 10. Processo de Refatoração

**Quando é apropriada:** quando uma mudança de negócio exige tocar um trecho já
frágil/duplicado *e* a refatoração é o menor caminho seguro para entregar essa
mudança — nunca como tarefa isolada não solicitada.

**Quando NÃO deve ocorrer:**
- Como efeito colateral de uma correção de bug pontual.
- Para migrar um model legado para Repository/Service "por consistência", sem
  pedido explícito.
- Para renomear arquivos/classes/rotas por preferência estética ou
  "modernização".
- Para trocar um componente que já funciona por uma alternativa mais "moderna"
  sem necessidade concreta.

Se identificar uma oportunidade de refatoração durante outra tarefa, **documente
a sugestão para discussão futura** (ver §8 passo 10) em vez de expandir o
escopo da entrega atual.

## 11. Processo de Correção de Bugs

1. Reproduza o problema mentalmente ou via teste antes de propor a causa raiz —
   não assuma.
2. Localize a causa raiz real; corrigir sintoma sem entender a causa tende a
   reintroduzir o bug em outro fluxo.
3. Siga o gate de aprovação da §8 normalmente — um bug fix ainda precisa de
   explicação e aprovação antes do código, especialmente se tocar autenticação,
   sessão, upload ou construção de SQL (ver §12, Segurança).
4. Corrija apenas o necessário — não aproveite para "limpar" código ao redor
   (ver §10).
5. Adicione um teste que teria pego o bug, quando viável.
6. Verifique se o mesmo bug existe em código irmão (outro módulo com a mesma
   lógica copiada) — é comum neste código base ter padrões repetidos entre
   `Admin*Controller`s.

## 12. Processo de Revisão (checklist obrigatório antes de reportar "concluído")

- [ ] O diff foi lido linha a linha pelo próprio Claude, não só executado.
- [ ] Nenhuma funcionalidade existente foi removida ou alterada sem aprovação.
- [ ] Nenhum efeito colateral em módulos que compartilham tabela/model/helper.
- [ ] `Auth::requireRole()` está presente e correto em toda ação nova de
      controller admin (lembrando que admin sempre ignora a lista, ver §3.9).
- [ ] `Security::csrfCheck()` está presente em toda ação `POST`.
- [ ] Toda saída para HTML passa por `Security::e()` (sem XSS).
- [ ] Toda query usa prepared statement com parâmetros (sem SQLi).
- [ ] Migration + rollback criados, se houve mudança estrutural de banco.
- [ ] Nenhum segredo (senha, chave, token) foi commitado ou logado em texto
      plano.
- [ ] Riscos de segurança identificados foram reportados ao usuário antes de
      qualquer correção ser aplicada.
- [ ] Testes relevantes (PHP e/ou Playwright) passam.
- [ ] Autocrítica registrada: bugs potenciais, regressões, e melhorias futuras
      notadas mas fora de escopo (documentadas, não implementadas
      silenciosamente).

## 13. Checklist para Alterações de Banco

- [ ] Migration criada em `database/migrations/AAAA-MM-DD-descricao.sql`.
- [ ] Rollback criado como `AAAA-MM-DD-descricao-rollback.sql`.
- [ ] Mudança usa `IF NOT EXISTS`/`IF EXISTS` onde aplicável, para ser
      idempotente e segura de reexecutar.
- [ ] Dados existentes preservados — nenhuma perda implícita.
- [ ] Se a mudança é destrutiva (DROP/TRUNCATE/remoção de coluna com dados):
      aprovação explícita e isolada obtida, **não** empacotada dentro da
      aprovação geral da feature.
- [ ] Impacto em downtime avaliado — preferir `ALTER TABLE` compatível com
      produção viva a operações bloqueantes longas.
- [ ] Se a mudança envolve dado sensível (PII, financeiro), avaliado se precisa
      de `Cipher::encrypt()`/coluna `*_encrypted`, seguindo o padrão já usado em
      `solicitacoes_vaga`/`movimentacoes_pessoal`.
- [ ] Se for correção de dados em massa, seguir o padrão transacional +
      auditoria da migration `2026-06-25-colaboradores-data-backfill-atomic.sql`
      (validação de FK órfã, staging em tabela temporária, log de erro
      dedicado).
- [ ] Testado localmente contra uma cópia do schema antes de considerar pronto
      para produção.

## 14. Checklist para Deploy

- [ ] `npm run build:css` executado (gera `assets/tailwind.css` e copia para
      `public/assets/tailwind.css`).
- [ ] `scripts/preflight.php` (ou `scripts/deploy_quick.ps1`, que já o invoca)
      rodado e todos os checks `[OK]`: PHP ≥ 8.1, extensão `pdo_mysql`,
      arquivos essenciais presentes, conectividade de banco.
- [ ] `app/config/config.php` de produção revisado — sem placeholders do
      `config.example.php`.
- [ ] `logging.viewer_key` configurada (protege `/logs.php`) e nunca exposta
      publicamente.
- [ ] `logging.alert_email` configurado para alertas de `ERROR`/`CRITICAL`.
- [ ] Diretórios graváveis confirmados: `storage/*` e `public/uploads` (o
      bootstrap tenta criá-los com `@mkdir`, mas permissões de servidor podem
      bloquear).
- [ ] Se via cPanel (`.cpanel.yml`): confirmar que `storage/` no destino não é
      sobrescrito (o script já preserva `storage` explicitamente, não altere
      essa exclusão sem entender o motivo).
- [ ] Pós-deploy: `/` responde, `/install.php` responde (e é removido/travado
      se a instalação já foi concluída), `/logs.php?key=...` acessível só com a
      chave correta, log JSONL do dia sendo criado em `storage/logs/`.
- [ ] Migrations pendentes aplicadas manualmente no banco de produção (não há
      runner automático de migrations — ver §3.7).

## 15. Checklist de Segurança

- [ ] **Autenticação**: fluxo de login não contorna `email_verified_at`; timeout
      de inatividade (1200s) preservado; `session_regenerate_id()` mantido em
      pontos de elevação de privilégio.
- [ ] **Autorização**: toda ação sensível protegida por `Auth::requireRole()`
      com a lista de papéis correta — e ciente de que admin/supervisor sempre
      passa (ver §3.9), então nunca dependa de `requireRole` para *excluir*
      admin de uma ação.
- [ ] **CSRF**: todo `POST` que muta estado chama `Security::csrfCheck()` antes
      de qualquer efeito colateral.
- [ ] **XSS**: toda interpolação de dado dinâmico em view passa por
      `Security::e()`; nunca imprimir `$_GET`/`$_POST`/dado de banco cru em
      HTML.
- [ ] **SQL Injection**: nenhuma concatenação de input em SQL; nomes de
      tabela/coluna dinâmicos só a partir de whitelist fixa no código.
- [ ] **Upload**: MIME real validado via `finfo` (não apenas extensão),
      tamanho máximo respeitado, arquivo salvo com nome não previsível ou
      higienizado, currículos fora do document root público (`storage/`), logos
      dentro do público mas com nome aleatório.
- [ ] **Sessões**: cookie `httponly` + `SameSite=Lax` + `secure` quando HTTPS;
      sessão armazenada em `storage/sessions/` (fora do document root público).
- [ ] **Criptografia de dados sensíveis**: campos `*_encrypted` usam `Cipher`
      (AES-256-CBC); confirme que `security.data_encryption_key` está definida
      em produção — sem ela, `Cipher` usa uma chave derivada de material fraco
      (risco conhecido, ver §16).
- [ ] **Auditoria**: ações administrativas sensíveis (troca de senha por admin,
      mudança de role, correção de dados em massa) registradas via `AuditLog`
      ou tabela de auditoria dedicada.
- [ ] **Segredos**: nenhuma senha/chave/token em texto plano commitado; `Logger`
      redige automaticamente por nome de chave — não desative isso.
- [ ] Qualquer risco de segurança identificado — mesmo incidental à tarefa — foi
      **reportado ao usuário antes** de qualquer correção ser implementada.

## 16. Checklist de Performance

- [ ] Queries de listagem usam paginação (`paginateAdmin` ou equivalente) — não
      carregam tabela inteira em memória. `Colaborador::all()` sem filtro é um
      atalho para até 100 registros; não use para listagens irrestritas.
- [ ] `JOIN`s preferidos a N+1 queries em loop de PHP.
- [ ] Índices considerados para colunas usadas em `WHERE`/`ORDER BY` frequentes
      (ver padrão em `2026-06-23-colaboradores-required-columns.sql`, que
      adiciona índices dedicados para `codigo`, `cpf`, `data_admissao`,
      `data_demissao`).
- [ ] `SchemaManager::ensure()` e `ensure*Schema()` dos models fazem checagem
      via `INFORMATION_SCHEMA` a cada primeira chamada por request — evite
      adicionar novas checagens desse tipo em caminhos de alta frequência sem
      necessidade.
- [ ] Assets front-end: CSS gerado via Tailwind com `content` restrito a
      `app/views/**/*.php` — classes usadas apenas via JS dinâmico devem entrar
      no `safelist` do `tailwind.config.js`, senão são purgadas no build.
- [ ] Operações de dados em massa (import XLSX, backfill) rodam em lote/streaming
      quando o volume justificar, não carregando o dataset inteiro sem
      necessidade (ver `SpreadsheetXlsxReader`/`CollaboratorSpreadsheetImportService`).

## 17. Checklist de Testes

- [ ] **PHP** (`tests/php/*.php`): scripts standalone, sem framework. Rodam
      contra banco real via `require bootstrap.php`. Convenção de nome:
      `unit_*.php` (lógica pura, sem banco) e `integration_*.php` (toca
      banco/models/services). Devem imprimir `OK <nome>` e `exit(0)`, ou
      `fwrite(STDERR, ...)` + `exit(1)` em falha. Execute individualmente via
      `php tests/php/nome_do_teste.php` — não há runner agregador hoje.
- [ ] **JS unitário** (`tests/unit/*.test.js`): scripts Node standalone,
      assertions manuais, sem Jest. Agregados nos scripts `test:unit` do
      `package.json`.
- [ ] **Visual/E2E** (`tests/*.spec.js`): Playwright, `npm run test:visual`.
      Screenshots comparados com `maxDiffPixelRatio: 0.02` — mudanças visuais
      legítimas exigem `npm run test:visual:update` para atualizar snapshots
      (revisar o diff visual antes de aceitar).
- [ ] Nova funcionalidade de backend com regra de negócio: adicionar
      `integration_*.php` cobrindo caminho feliz + pelo menos uma rejeição.
- [ ] Nova tela ou alteração visual: cobrir com `*.spec.js` se o módulo já tiver
      um spec análogo (a maioria dos módulos admin já tem, ver `tests/`).
- [ ] Antes de reportar concluído, rodar os testes relevantes localmente — não
      assumir que passam.

---

## 18. Banco de Dados — Convenções e Boas Práticas (resumo operacional)

- Nomenclatura de tabelas: plural em português, snake_case (`colaboradores`,
  `setores`, `cargo_setores`, `solicitacoes_vaga`, `movimentacoes_pessoal`).
- Chaves estrangeiras: `{tabela_singular}_id` (ex.: `empresa_id`, `cargo_id`,
  `setor_id`).
- Charset/collation padrão em migrations recentes: `utf8mb4` /
  `utf8mb4_unicode_ci` — bases legadas podem estar em `utf8mb4_bin` ou
  `utf8mb4_general_ci`; ao escrever SQL que compara texto entre tabelas de
  gerações diferentes, force a collation explicitamente (ver padrão `CONVERT(...
  USING utf8mb4) COLLATE utf8mb4_unicode_ci` na migration de backfill).
- Toda tabela nova: `id` auto-incremento como PK, `created_at TIMESTAMP DEFAULT
  CURRENT_TIMESTAMP` no mínimo; `updated_at` quando a entidade é mutável.
- Relacionamentos N:N seguem o padrão `{tabelaA}_{tabelaB}` (ex.:
  `cargo_setores`), com repository dedicado (`CargoSetorRepository`) em vez de
  lógica de pivot espalhada pelos models das duas pontas.
- Migrations idempotentes sempre que possível (`CREATE TABLE IF NOT EXISTS`,
  checagem via `INFORMATION_SCHEMA` antes de `ALTER TABLE ADD COLUMN`) — o
  projeto já depende disso pois não há tabela de controle de migrations
  aplicadas.

## 19. UI / UX

- **Sem template engine**: views PHP puro, HTML + Tailwind inline nas próprias
  views. Não introduza Blade/Twig/JSX.
- **Design tokens** (`tailwind.config.js`): `ctdark` (#0d1321), `ctgreen`
  (#1d2d44), `ctlight` (#3e5c76), `ctpblue` (#0d1321). Fonte: `Montserrat`.
  Sempre use esses tokens em vez de cores ad hoc ao estilizar algo novo.
- **Componentes reutilizáveis** (classes utilitárias customizadas, no
  `safelist` do Tailwind): `ct-btn`, `ct-btn-primary`, `ct-btn-success`,
  `ct-btn-warning`, `ct-btn-muted`, `ct-badge`, `ct-badge-active`,
  `ct-badge-inactive`. Prefira-as a criar novas variações de botão/badge.
- Dois layouts principais: `layouts/main` (público — vitrine de vagas) e
  `layouts/admin` (área administrativa autenticada).
- Responsivo: breakpoints customizados `xs (480px)` além dos padrões Tailwind;
  há suíte de testes visuais dedicada a responsividade
  (`tests/admin-responsive.spec.js`, `tests/forms-layout.spec.js`) e a
  contraste de botões (`tests/contrast-buttons.spec.js`) — rode-os ao mexer em
  telas administrativas.
- Padrão de feedback ao usuário: querystring `?ok=mensagem` / `?erro=mensagem`
  após redirect pós-ação, renderizado como flash message na view — siga esse
  padrão em vez de introduzir um sistema de toast/flash novo.

---

## 20. Roadmap Técnico (conforme observado na análise — não especulativo)

Estes itens refletem trabalho em andamento ou lacunas identificadas na
auditoria de 2026-07-09, sem inventar objetivos novos:

- **Migração incremental para Repository/Service/DTO**: já iniciada em
  `Setor`/`CargoSetor`/parte de `Empresa` e nos webhooks de recrutamento. O
  restante dos módulos (Colaborador, SolicitacaoVaga, MovimentacaoPessoal,
  Candidatura, Vaga, Beneficio, AvaliacaoDesempenho, User) segue no padrão
  legado. Não há indicação de prazo para migrar o restante — trate módulo a
  módulo, só quando uma mudança de negócio real justificar tocar naquele
  módulo.
- **Consolidação de schema**: há trabalho recente em normalizar dados de
  `colaboradores` (migrations `2026-06-23` e `2026-06-25`) — os campos
  `codigo`, `cpf`, `data_inicio_cargo`, `ativo` foram alvo de backfill
  determinístico e atômico com auditoria. A causa raiz (múltiplas fontes de
  schema, ver §3.7) permanece; qualquer trabalho futuro de consolidação de
  `schema.sql`/`recrutamento.sql`/migrations deve ser tratado como mudança de
  infraestrutura de dados, com aprovação isolada (§13).
- **Importação de colaboradores via XLSX** (`COLABORADORES_XLSX_IMPORT.md`,
  `SpreadsheetXlsxReader`, `CollaboratorSpreadsheetImportService`): já
  implementada, com regras específicas de reingresso ("rehire rules") cobertas
  por teste de integração dedicado.
- **Webhooks de recrutamento** (`RECRUITMENT_WEBHOOKS_API.md`): API já
  implementada para notificar sistemas externos sobre eventos do pipeline de
  recrutamento, com retry de eventos falhos (`retryEvent`) e processamento
  assíncrono via endpoint `process-pending` chamado manualmente/por cron
  externo (não há worker/queue interno).
- **Relacionamento Cargo × Setor** (`CARGO_SETORES_FEATURE.md`): governança N:N
  já implementada com padrão Repository/Service completo — use como referência
  de "melhor exemplo" da geração nova ao propor um módulo novo.
- **Kanban de Solicitações de Vaga** (sprint 2026-08-25): segundo Kanban do
  sistema, acompanhando a situação **operacional** da vaga solicitada pelo
  gestor (`Em aprovação/Aprovada/Em recrutamento/Em processo seletivo/Fechada/
  Cancelada`) — deliberadamente independente do Kanban de Recrutamento e
  Seleção (`AdminPipelineController`/`PipelineStage`/`Candidatura`), que
  acompanha candidatos. Implementado como um segundo stack completo, não como
  generalização do primeiro: `SolicitacaoVagaStage` (catálogo de etapas,
  tabela `solicitacao_vaga_stages`), `SolicitacaoVagaPipelineService`
  (movimentação com lock/transação/validação, tabela
  `solicitacao_vaga_kanban_historico`) e `SolicitacaoVagaStageValidator`
  (campos obrigatórios por etapa — hoje só `motivo_cancelamento` na etapa
  `cancelada`), além de um bloco próprio em `assets/admin.js`
  (`initSolicitacaoVagaKanban`, seletores `data-sv-kanban-*`) que não toca em
  `initKanban()`. A coluna `solicitacoes_vaga.situacao_kanban_id` é
  **desacoplada de propósito** de `status_fluxo` (que continua exclusivo do
  fluxo de aprovação líder/RH já existente): nenhuma automação sincroniza as
  duas hoje — a movimentação no Kanban é sempre manual via drag-and-drop. Ver
  migration `database/migrations/2026-08-25-solicitacao-vaga-kanban.sql` para
  o backfill determinístico aplicado aos registros existentes (mapeamento
  `status_fluxo → situacao_kanban_id`, com histórico explícito para os casos
  migrados para `cancelada`, sem inventar motivo de negócio). A relação entre
  `solicitacoes_vaga` e `vagas`/`candidaturas` continua não implementada por
  decisão explícita desta sprint — arquitetura deixada preparada, não forçada.
- **Integração com METADADOS (sistema oficial de RH/DP, SQL Server) — Fase 1**
  (sprint 2026-08-27): decisão de que o METADADOS passa a ser a fonte oficial
  de dados de colaboradores; o Portal RH deixará **gradualmente** de manter
  cadastro duplicado, mas `colaboradores` **não foi removida nem alterada**
  nesta fase — plano de transição em 5 fases (consumir + espelhar; comparar;
  mapear vínculos; migrar telas; descontinuar cadastro duplicado só quando não
  houver mais dependência crítica). Fase 1 implementada: tabela espelho de
  **leitura** `colaboradores_metadados` (uma linha por CONTRATO, não por
  pessoa — readmissão gera nova linha, nunca sobrescreve; chave técnica
  `codigo_empresa + codigo_unidade + numero_contrato`, nunca CPF isolado — ver
  migration `2026-08-27-colaboradores-metadados.sql`), `MetadadosDatabase`
  (conexão SQL Server dedicada, só usada pela sincronização, nunca em request
  normal do Portal), `MetadadosSyncService` (upsert idempotente, nunca faz
  `DELETE` de vínculo histórico) + `ColaboradorMetadadosRepository`, e
  `scripts/sync_metadados_colaboradores.php` (CLI, agendado externamente —
  mesmo padrão já usado pelos webhooks de recrutamento e pelo import XLSX de
  colaboradores). `MetadadosSyncService::fetchSourceRows()` (lê do SQL Server)
  e `::applyRows()` (upsert em MySQL) são deliberadamente separados para que a
  lógica de upsert seja testável sem depender do driver/conectividade — ver
  `tests/php/integration_colaborador_metadados_sync.php`. **Nenhuma FK nova
  aponta para `colaboradores_metadados` nesta fase.** Risco arquitetural já
  identificado e registrado para as próximas fases: `usuario_colaboradores`
  tem `UNIQUE KEY` em `colaborador_id` (1 usuário de login ↔ 1 colaborador) —
  isso não sobrevive a readmissão sem uma camada de vínculo
  `colaborador_local ↔ colaboradores_metadados`, que ainda não foi construída
  (Fase 3 do plano).
  **Regra arquitetural definitiva: a conexão com o METADADOS é somente
  leitura.** `MetadadosDatabase`/`MetadadosSyncService::fetchSourceRows()`
  executam exclusivamente um `SELECT` (a constante `QUERY`); nenhum
  INSERT/UPDATE/DELETE/MERGE/TRUNCATE/ALTER/CREATE/DROP/EXEC é ou deve ser
  emitido contra o SQL Server do METADADOS — todas as gravações da
  sincronização acontecem só no MySQL local, via `Database::conn()` dentro de
  `ColaboradorMetadadosRepository`/`MetadadosSyncService::applyRows()`. Se
  corrigir um dado oficial for necessário, a correção é feita no METADADOS
  pela equipe responsável, nunca pelo Portal. **Fase 2 validada em
  2026-08-27** contra `RHTESTE` (SQL Server real): conectividade, chave
  técnica `EMPRESA+UNIDADE+CONTRATO` sem duplicidade, `RHPESSOAS.CPF` como
  `varchar(11)` (preserva zero à esquerda), JOIN de `RHCENTROSCUSTO1`
  corrigido para casar só por `CENTROCUSTO1` (sem `UNIDADE` — nas 2 linhas
  existentes em `RHTESTE`, `RHCENTROSCUSTO1.UNIDADE` veio vazio, então exigir
  igualdade de unidade zerava 100% dos matches; ressalva: amostra pequena,
  revalidar contra base com volume real antes de tratar como definitivo para
  todos os ambientes), e `setor = NULL` mantido fiel à origem (`RHSETORES`
  está vazia em `RHTESTE` e os 40 contratos de teste não têm `SETOR`
  preenchido — sem evidência de bug de JOIN, só de origem vazia nesta base).
  Idempotência real confirmada: `{inserted:0, updated:0, unchanged:40,
  errors:0}` numa segunda execução consecutiva. **Pendência de
  infraestrutura, não implementada ainda**: a validação de 2026-08-27 usou a
  credencial `sa` do SQL Server só temporariamente, para desenvolvimento —
  antes de qualquer implantação em produção é obrigatório criar um usuário
  dedicado ao Portal RH com privilégio exclusivo de `SELECT` nas tabelas
  `RHCONTRATOS`, `RHPESSOAS`, `RHEMPRESAS`, `RHUNIDADES`, `RHCARGOS`,
  `RHSETORES`, `RHCENTROSCUSTO1`, `RHMOTIVOSRESCISOES` — isso é infraestrutura
  do SQL Server e exige autorização própria, fora do escopo desta sprint.
  **Fase 3 (auditoria) e Fase 3.1 (ampliação do espelho), 2026-08-27/28**:
  todos os 7 JOINs da query foram revalidados contra o banco real
  `RHMADEPLANT` (727 contratos, não mais só `RHTESTE`) com 100% de
  correspondência técnica em cada um — nenhuma correção pendente. A tabela
  espelho ganhou dois campos oficiais adicionais: `salario_atual` (de
  `RHCONTRATOS.SALARIOCONTRATUAL` — escolhido em vez de `SALARIOMES`, que é
  numericamente idêntico em 100% dos contratos preenchidos em produção, por
  representar semanticamente o salário-base contratual, nunca total recebido
  no mês) e `data_inicio_cargo` (de `RHCONTRATOS.DATAULTALTCARGO`, sem
  fallback para `admissao` — são conceitos diferentes quando há
  promoção/mudança de cargo). Ver migration
  `2026-08-27-colaboradores-metadados-salario-cargo.sql`. Histórico salarial e
  histórico de cargo continuam fora de escopo — o espelho reflete só o estado
  atual do vínculo. **Nota de compatibilidade descoberta nesta sprint**: o
  MySQL 8.4.3 usado neste ambiente de desenvolvimento não aceita `ADD COLUMN
  IF NOT EXISTS`/`DROP COLUMN IF EXISTS` (erro de sintaxe 1064, testado
  empiricamente) — migrations novas para tabelas da geração
  Repository/Service devem usar `ALTER TABLE` puro quando a coluna nunca
  existiu antes, documentando a limitação no próprio arquivo (não se aplica
  retroativamente a migrations antigas já escritas com essa cláusula). A
  auditoria completa de dependências de `colaboradores` (Fase 3) concluiu que
  `colaboradores.id` já representa CONTRATO, não pessoa (11 CPFs duplicados
  na base local, cada um com datas de admissão/demissão não sobrepostas) — o
  mesmo grão de `colaboradores_metadados`, o que facilita a reconciliação
  futura.
  **Fase 3.2 (ponte estrutural + relatório de reconciliação), 2026-08-28**:
  `colaboradores` passa a representar o vínculo local estável e ganha
  `colaboradores.metadados_id` (migration
  `2026-08-28-colaboradores-metadados-id.sql`) — relação 0..1 ↔ 1 com
  `colaboradores_metadados.id`, `UNIQUE` (permite múltiplos `NULL`),
  `ON DELETE RESTRICT` (nunca `CASCADE` — o espelho não deve apagar um
  colaborador local silenciosamente). `colaboradores.id` **nunca** é
  substituído pelo id do espelho; continua sendo a referência das 9 FKs
  existentes, inalteradas. `ColaboradorMetadadosReconciliationService`
  (`app/services/`) só analisa e classifica
  (`CORRESPONDENCIA_SEGURA`/`_PROVAVEL`/`AMBIGUA`/`SEM_CORRESPONDENCIA`/
  `JA_VINCULADO`/`CONFLITO`) — **nunca escreve `metadados_id`**; a aplicação
  de vínculos é uma fase futura, ainda não autorizada. CPF nunca decide o
  vínculo sozinho (readmissão): a hierarquia é CPF → data de admissão → data
  de demissão → nascimento como validação (nascimento claramente divergente
  sempre vira `CONFLITO`, mesmo com CPF+admissão batendo); nunca escolhe "o
  mais recente" nem "o ativo" automaticamente quando há ambiguidade.
  `scripts/reconciliar_colaboradores_metadados.php` é somente leitura por
  padrão, sem flag de aplicação (de propósito, para não permitir uso
  acidental); gera relatório detalhado em CSV com CPF mascarado (só os
  últimos 4 dígitos) em `storage/reconciliation/` (fora do Git), sem
  salário/dados bancários. Testado com fixtures sintéticas — não usar os 40
  registros de `RHTESTE` nem a comparação "408 vs 40" como conclusão de
  negócio; a sincronização dos 727 contratos de `RHMADEPLANT` e a
  reconciliação real ainda não foram autorizadas. Continuam intocados nesta
  fase: `Colaborador::updateRhData()`, import XLSX, telas, dashboards,
  `usuario_colaboradores`/autenticação (risco de `UNIQUE` em
  `colaborador_id` sob readmissão continua registrado, não tratado), e o
  vínculo candidato→colaborador em `solicitacoes_vaga.nome_contratado_colaborador_id`.

## 21. Conhecimento do Projeto (memória permanente)

- **Modelo de papéis é mais simples do que parece**: só existem 3 valores de
  `role` (`admin`, `rh`, `viewer`). `$_SESSION['user_is_supervisor']` reflete a
  coluna `usuarios.is_supervisor` do banco — até 2026-07 qualquer
  `role === 'admin'` também virava supervisor em sessão, mas isso foi corrigido
  na Sprint de Segurança (ver `app/core/Auth.php::establishSession()` e o teste
  `tests/php/integration_auth_supervisor_session.php`). O fallback de e-mail
  legado descrito em `AUTH_PERMISSOES_FIX.md` (role vazia + e-mail = supervisor
  configurado → promove a admin/supervisor) continua intacto — só a promoção
  automática por role foi removida.
- **Instalador web** (`install.php` + `app/core/Installer.php`) prioriza os
  dumps de `recrutamento.sql` sobre `database/schema.sql` quando ambos
  existem — o dump de recrutamento contém dados reais de produção. Cuidado ao
  rodar o instalador contra um ambiente que não deveria receber esses dados.
- **Não há Composer nem vendor/**: todo autoload é manual. Instalar uma
  dependência via `composer require` não vai funcionar sem primeiro introduzir
  Composer ao projeto inteiro — o que é uma decisão arquitetural que exige
  aprovação explícita, não uma tarefa incidental.
- **`dist/`** contém uma saída de build já gerada (provavelmente por
  `scripts/deploy_quick.ps1`) — não é código-fonte, não edite arquivos dentro
  de `dist/` diretamente; edite a fonte em `app/`/`assets/`/etc. e regere o
  build.
- **`tests/php` não tem runner agregador** — cada script é executado
  individualmente via CLI PHP. Se for pedido para "rodar os testes", rode-os um
  a um (ou pergunte se deve ser criado um runner, o que seria uma mudança de
  tooling que merece aprovação própria).
- **Dois arquivos idênticos de dump de recrutamento** existem
  (`database/recrutamento.sql` e `recrutamento.sql` na raiz) — mantenha-os em
  mente ao investigar schema, mas não assuma que editar um propaga para o
  outro.

## 22. Riscos Conhecidos (não corrigir silenciosamente — reportar e aguardar aprovação)

Estes riscos foram identificados na auditoria arquitetural de 2026-07-09 e
permanecem documentados aqui para que não sejam "descobertos" e corrigidos por
impulso em uma tarefa não relacionada — qualquer correção deve passar pelo
processo de segurança da §8/§15, com uma conversa dedicada sobre blast radius:

1. **PII real de candidatos/colaboradores commitada em `recrutamento.sql`**
   (ambas as cópias) e presente no histórico do Git, que foi enviado a um
   remoto GitHub. Requer decisão explícita sobre como sanear o histórico sem
   quebrar o instalador que depende desse dump.
2. **Senha de supervisor em texto plano** em `app/config/config.php` /
   `local.php` (`security.supervisor_password`). É assim que o instalador cria
   o primeiro usuário admin — mudar isso tem implicações no fluxo de
   instalação/recuperação e não deve ser "corrigido" sem discutir o fluxo
   alternativo.
3. **`Cipher` usa chave derivada fraca como fallback** quando
   `security.data_encryption_key` não está definida na config — os dados
   `*_encrypted` ficam protegidos por uma chave previsível a partir de outros
   valores de config. Verificar se produção tem a chave explícita definida
   antes de assumir que os dados sensíveis estão realmente protegidos.

---

*Fim do documento. Mantenha-o atualizado: qualquer decisão arquitetural nova,
convenção nova ou regra de negócio descoberta durante uma tarefa deve ser
refletida aqui antes de encerrar a tarefa.*
