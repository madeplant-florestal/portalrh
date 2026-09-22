# UI / UX — RH Madeplant

> Consultar sempre que a tarefa envolver alteração de interface.

- **Sem template engine**: views PHP puro, HTML + Tailwind inline nas próprias views. Não
  introduza Blade/Twig/JSX.
- **Design tokens** (`tailwind.config.js`): `ctdark` (#0d1321), `ctgreen` (#1d2d44), `ctlight`
  (#3e5c76), `ctpblue` (#0d1321). Fonte: `Montserrat`. Sempre use esses tokens em vez de cores ad
  hoc ao estilizar algo novo.
- **Componentes reutilizáveis** (classes utilitárias customizadas, no `safelist` do Tailwind):
  `ct-btn`, `ct-btn-primary`, `ct-btn-success`, `ct-btn-warning`, `ct-btn-muted`, `ct-badge`,
  `ct-badge-active`, `ct-badge-inactive`. Prefira-as a criar novas variações de botão/badge.
- Dois layouts principais: `layouts/main` (público — vitrine de vagas) e `layouts/admin` (área
  administrativa autenticada).
- Responsivo: breakpoints customizados `xs (480px)` além dos padrões Tailwind; há suíte de testes
  visuais dedicada a responsividade (`tests/admin-responsive.spec.js`, `tests/forms-layout.spec.js`)
  e a contraste de botões (`tests/contrast-buttons.spec.js`) — rode-os ao mexer em telas
  administrativas.
- Padrão de feedback ao usuário: querystring `?ok=mensagem` / `?erro=mensagem` após redirect
  pós-ação, renderizado como flash message na view — siga esse padrão em vez de introduzir um
  sistema de toast/flash novo.

## Nova UI — Design System Portal RH (Fase 1: fundação, sprint 2026-09-25)

Fonte oficial da nova UI/UX: [`DESIGN-SYSTEM-PORTAL-RH.md`](DESIGN-SYSTEM-PORTAL-RH.md) (migração em 10 fases, ordem
obrigatória; §16). A Fase 1 só registrou tokens em `tailwind.config.js` + variáveis CSS em `assets/tailwind-input.css`
(`:root`, derivadas da config via `theme()` — a config é a única fonte). **Nenhuma tela existente usa os tokens ainda**;
os tokens legados (`ct*`, `Montserrat`, `rounded-*` nativos) continuam valendo para o que já existe.

- **Cores** (nomes = tokens do doc §6): `primary-{50,100,300,400,600,700,800,900}` (`primary-700` = #3B4822),
  `background`, `surface`, `surface-secondary`, `border`, `text-primary/secondary/muted`, `success`, `warning`, `danger`,
  `info`, `focus` e o apoio `support-beige|sage|brown-dark|brown`. Classes: `bg-primary-700`, `border-border`,
  `text-text-primary`, `ring-focus`… (o utilitário `border` puro NÃO mudou de cor). Sem paleta azul nova.
- **Tipografia**: `font-ds` (Segoe UI, Helvetica, Arial) e `font-brand` (NewBlack → fallback de sistema; **sem
  `@font-face`**: o `.woff2` oficial ainda não foi entregue); `font-sans` continua Montserrat. Escala `text-ds-h1|h2|h3|
  body|label|caption|badge|kpi|kpi-sm|button` (tamanho + line-height + peso).
- **Radius/sombras**: `rounded-ds-sm|md|lg` (6/10/14px) e `shadow-resting|elevated` — com prefixo/nome próprio para não
  redefinir `rounded-*`/`shadow-*` nativos. **Espaçamento**: a escala 4·8·12·16·20·24·32·40·56 já é a nativa
  (`1,2,3,4,5,6,8,10,14`); `px-gutter` usa `--gutter-page` (16px mobile · 24px ≥768 · 40px ≥1280).
- Variáveis `:root`: `--color-*`, `--radius-sm|md|lg`, `--shadow-resting|elevated`, `--gutter-page`.
- O Tailwind só emite as classes efetivamente usadas nas views (`content: ./app/views/**/*.php`): um token novo só aparece
  no CSS compilado quando uma view passa a usá-lo. Rode `npm run build:css` (gera `assets/tailwind.css` e copia para
  `public/assets/tailwind.css`, ambos versionados).

## Nova UI — Fase 2: AppShell V2 e navegação base (sprint 2026-09-26)

**Princípio da migração visual: compatibilidade primeiro, migração visual depois.** O Design System é o alvo, mas a
estrutura estável atual é preservada: nada de reescrita ampla, nenhuma classe global existente é redefinida, tokens/
componentes antigos e novos convivem, a migração é tela a tela e nenhuma regra de negócio muda para caber no design.

- **O AppShell V2 é OPT-IN.** `layouts/admin` (com a sidebar) segue como o shell de TODAS as telas atuais; a **sidebar não
  foi removida nem alterada** e a **Central do Portal ainda não existe** (Fase 3). Nenhum controller/view/rota usa o shell
  novo ainda (o teste `unit_ui_shell.php` garante). Uma página migrada escolhe o layout explicitamente:
  `$this->view->render('...', $dados, 'layouts/app-shell')`. Não há feature flag global.
- **Arquivos**: `app/views/layouts/app-shell.php` (Header V2 + `<main>` com `px-gutter`, sem largura máxima; carrega os
  mesmos metas e `admin.js`/`phone-utils.js` do layout atual; aceita `tituloPagina`) e `app/views/partials/ui-shell.php`
  (funções que retornam string, mesmo padrão de `admin/partials/chart-helpers.php`).
- **Componentes** (chamados pela própria view, com dados explícitos; escapam tudo com `Security::e()`; `href` já vem
  pronto com `$base`; não consultam permissão, sessão de perfil nem banco — quem decide o que existe é a view/controller):
  - `ui_header_v2($hrefInicio, $hrefLogo, $hrefSair, $nome)`: 64px, logotipo existente (`logo-escura.png`) + "Portal RH" +
    avatar de iniciais + nome (oculto no mobile) + "Sair" (`/admin/logout`). **Sem menu de módulos.**
  - `ui_breadcrumb($itens)`: trilha explícita; o último item é a página atual (sem link, `aria-current="page"`).
  - `ui_page_header($opcoes)`: `titulo`, `eyebrow`, `descricao`, `badge{texto,tom}`, `acao{label,href}`.
  - `ui_module_tabs($abas, $rotulo)`: aba ativa `primary-700` + borda de 2,5px + `aria-current`; rolagem horizontal contida.
- **Mensagens flash**: continuam sendo renderizadas pela própria view (não há partial global hoje); o shell só entrega
  `$content`. O componente Alert V2 pertence à Fase 4 — os alerts antigos não foram tocados.
- **Ainda não existem** (fases seguintes): Central/ModuleCard, MetricCard, FilterBar, Table V2, campos de formulário, Modal,
  Kanban V2. Sem `@font-face` da NewBlack (Header usa o fallback de sistema).
- **Conflito conhecido para a migração de telas**: o layout antigo tem `<style>` inline (`.form-choice-*`, `.form-inline-grid`)
  que o shell V2 não carrega; uma view migrada que use essas classes precisa ganhá-las na fase de formulários.
- Validação sem rota de preview: harness temporário fora do repositório (HTML estático + Playwright).

## Nova UI — Central do Portal RH como home (Fase 3A + Bloco A, sprint 2026-09-27)

**Estratégia de versionamento da Nova UI:** todo o trabalho vive na branch `feat/nova-ui-portal-rh`. **Não há commits/pushes
parciais na `main`** (evita um Portal "meio migrado" em produção): checkpoints locais só na branch e integração consolidada
(squash) na `main` quando o conjunto estiver coerente e aprovado. Testa-se continuamente; publica-se de uma vez.

**Arquitetura de entrada: Login → Central → Módulos.**
- `GET /admin` = **Central do Portal RH** (`AdminCentralController::index` → view `admin/central` → `layouts/app-shell`,
  AppShell V2). Sem permissão própria: qualquer sessão autenticada (`Auth::requireRole(['admin','rh','viewer'])`, como o Manual)
  + gate global de `/admin*`. Só NAVEGA — o card não concede acesso e cada rota de destino segue protegida no backend.
- `GET /admin/dashboard` = o dashboard que ocupava `/admin` (People Analytics), **sem reescrita**: mesmo `AdminController::index`,
  mesmas queries, filtros (formulário GET sem `action`) e JS, e o mesmo gate (`Auth::requireRole` + `dashboard.visualizar`).
- `GET /admin/central` = 302 → `/admin` (rota de desenvolvimento, nunca publicada; uma única URL canônica).
- **Pós-login**: `Authorization::primeiraRotaAcessivel()` (único chamador: `AuthController`) agora devolve sempre `/admin` para
  sessão válida (admin/rh/viewer/supervisor); a razão de existir (não cair em 403 no dashboard) deixou de valer, porque a Central
  é aberta e os cards já respeitam as permissões. Sem loop: `/admin` renderiza, não redireciona.
- **Card "Indicadores de RH"**: entrada = `/admin/dashboard` se o usuário tem `dashboard.visualizar`; senão `/admin/indicadores-rh`
  (aberto) — nunca leva a uma rota proibida.
- **Sidebar (só nas páginas antigas)**: o link "Dashboard" passou a `/admin/dashboard` (mesma condição de permissão) e ganhou, no
  topo, o link "Central do Portal" (`/admin`) para o caminho de volta. Nada mais na sidebar mudou.

**Módulos** (`PortalNavegacaoService::definicao()`, áreas funcionais — não os ~25 itens da sidebar; o card aponta para o PRIMEIRO
destino visível): Indicadores de RH, Recrutamento e Seleção, Solicitações de Vaga, Colaboradores, PDI, Integração, Turnover e
Desligamento, Mensagens, Cadastros, Usuários e Acessos. Fora da Central (utilitários): Manual de Uso, link de vagas públicas e
Sair (já está no Header V2). Card sem acesso **não é renderizado**.

**Sem divergência da sidebar (duplicação temporária e consciente):** a sidebar mistura condições inline com a marcação e não foi
refatorada; as regras do serviço (`aberto`, `perm:`, `staff_ou:`, `pedidos_vaga`, `admin_supervisor`) replicam as dela com as
mesmas fontes (`Authorization::temPermissao` — bypass central de Admin —, role, supervisor). `integration_portal_central.php`
renderiza a sidebar para 18 perfis e falha se a Central divergir dela (ou se surgir link novo na sidebar fora da Central/lista
de exclusões: `/admin`, Manual, Sair). Ao aposentar a sidebar, a definição vira a única fonte.

**Componentes** (`partials/ui-shell.php`): `ui_module_card` (um `<a>` único; anatomia/hover/foco do §5; `motion-reduce`),
`ui_module_grid` (`repeat(auto-fill, minmax(230px,1fr))`, gap 18px, sem JS nem breakpoints por contagem), `ui_module_icon`
(SVGs locais, decorativos) e a opção `marca` do `ui_page_header` (H1 em NewBlack com fallback). Estado vazio compacto na view.

**Transição intencional (durante o desenvolvimento na branch):** Central V2 → clique no card → tela antiga com a sidebar antiga.
Os módulos serão migrados por blocos (A estrutura · B Recrutamento · C Pessoas · D PDI · E Pesquisas/Desligamento · F Indicadores
· G Cadastros); a sidebar só é aposentada depois.

## Nova UI — Bloco B: Recrutamento e Seleção migrado (branch `feat/nova-ui-portal-rh`)

**Telas migradas para o AppShell V2 (sem sidebar)** — só apresentação; controllers/services/repositories/queries/JS/regras intactos
(cada controller do módulo apenas trocou `layouts/admin` → `layouts/app-shell`):
Dashboard de Recrutamento (`/admin/dashboard-recrutamento`), Solicitações de Vaga (lista, Kanban, nova, detalhe), Vagas (lista, nova/editar),
Candidaturas (lista, detalhe), Pipeline Kanban, Indicações e Webhooks. **Continuam no layout antigo (com sidebar):** todo o resto do Portal
(Usuários, Colaboradores, PDI, dashboards de pessoas/turnover/entrevista, pesquisas, entrevistas de desligamento, mensagens, movimentação,
cadastros, manual).

**Navegação do módulo:** Portal RH (`/admin`) › Recrutamento e Seleção › <aba> [› detalhe], com as ModuleTabs
**Dashboard | Solicitações de Vaga | Vagas | Candidaturas | Pipeline Kanban | Indicações | Webhooks** (Kanban de Solicitações é a mesma aba
"Solicitações de Vaga", acessada pelo botão que já existia). Definição em `PortalNavegacaoService::definicaoAbas()` — a mesma fonte de
visibilidade da Central (sem terceira matriz). **Regra de ouro: aba visível ⇒ o backend realmente abre o destino** (teste
`integration_portal_modulo_recrutamento.php` lê o gate de cada destino e falha se alguma aba levar a 403).

**Autorização — divergência sidebar × controller CORRIGIDA fechando o Bloco B.** Causa: Pipeline, Indicações e Webhooks usavam
`Auth::requireRole(['admin','rh'])` na entrada, mas a sidebar (e a Central) já os mostravam também a `viewer` com a permissão individual
(`pipeline.visualizar`, `indicacoes.visualizar`, `recruitment_webhooks.visualizar`, todas já existentes no catálogo) → 403. Correção: novo gate
`Authorization::requireRoleOuPermissao($roles, $codigo)` (+ `temAcessoPorRoleOuPermissao`, lógica pura) usado SÓ nas 3 entradas de leitura:
libera role admin/rh (e supervisor, como `requireRole`) **OU** a permissão individual (Admin também pelo bypass central). Modelo **aditivo**, o mesmo da
seed 2026-09-16: quem já entrava pela role continua entrando (RH não precisa da permissão) e a permissão passa a liberar quem não tem a role.
Permission-only puro tiraria o acesso de todos os RH hoje sem grants (0 concessões dessas 3 permissões) e exigiria seed de grants — decisão de negócio pendente.
**Ações sensíveis mantêm os gates de antes:** mover card (`admin/rh`), exportar/pagar/editar pagamento (`admin/rh`), testar/reprocessar/reenviar (`admin/rh`),
salvar configuração e regenerar segredo (`admin`). Para quem entra só pela permissão as telas ficam em **modo leitura** (Pipeline sem arrastar —
`data-kanban-readonly`, guard em `admin.js` —, Indicações sem exportar/pagar, Webhooks sem formulários de ação). As abas voltaram a espelhar a sidebar
(`staff_ou:<permissão>`); a regra `staff` provisória foi removida.

**CSP e JS:** a CSP (`public/.htaccess`) é `script-src 'self'` e NÃO foi relaxada. Os `<script>` inline de Candidaturas (lista e detalhe) e Indicações, e os
`onclick=`/`onsubmit=` do Webhooks/detalhe da candidatura, foram movidos para `assets/candidaturas.js` e `assets/indicacoes.js` (cópias em `public/assets/`)
ou para `data-confirm-message`/`data-toggle-target`. As views registram o arquivo com `ui_script_pagina('x.js')` e o layout `app-shell` emite `<script defer>` com `?v=`
só nas páginas que o usam. **Ainda com script inline (CSP bloqueia) e fora do Bloco B:** `colaboradores/rh-form`, `usuarios/show` (×3), `indicadores-rh`, e `onsubmit=` em
`colaboradores/acesso`, `pesquisa_integracao_qr`, `pesquisa_reacao_integracao` — tratar ao migrar cada bloco. `phone-utils.js` existia só em `assets/` (nunca em
`public/assets/`, daí o 404 em toda tela e a máscara de telefone do site público inativa): agora publicado e coberto por `unit_assets_publicos.php`.
`public/assets/public.js` está defasado em relação a `assets/public.js` (falta `initPublicMenu`) — pendência do site público, não tratada.

**Componentes/infra criados (só o que as telas usaram):**
- `partials/modulo-topo.php` → `ui_modulo_topo($base, $modulo, $abaAtiva, $pagina, $trilha)`: Breadcrumb → PageHeader → ModuleTabs (reutilizável pelos próximos blocos).
- `ui_btn($variante)` em `ui-shell.php` (primario/secundario/destrutivo/ghost) e `ui_titulo_pagina()` (o PageHeader registra o `<title>`; o layout o lê).
- CSS de compatibilidade da marcação legada **escopado em `:where([data-app-shell-v2])`** (`assets/tailwind-input.css`): painéis (`responsive-panel/-card`), campos
  (40px, borda, foco), tabelas (cabeçalho `surface-secondary`), `border-color` padrão e a reprodução de `.form-choice-*` e `.form-inline-grid` (o `<style>`
  inline do layout antigo NÃO é carregado no shell). Telas antigas ficam pixel-idênticas (verificado: 24 combinações tela × largura).
- Nas views migradas, classes de cor legadas (`ct*`, slate/gray/red/green/amber...) foram mapeadas mecanicamente para os tokens do Design System; cores vindas de
  dados (cor da etapa do pipeline) foram mantidas; o fallback azul-marinho das etapas verdes virou o verde institucional `#3B4822`.

**Compatibilidade / pendências conhecidas:** o botão "Voltar para vagas"/"Voltar" das telas migradas foi substituído pelo breadcrumb e pela aba "Vagas" (navegação, não ação).
Os dois problemas pré-existentes encontrados na validação (404 de `phone-utils.js` e scripts inline bloqueados pela CSP) foram resolvidos no fechamento técnico do Bloco B (ver acima).

## Nova UI — Bloco C: Pessoas (Colaboradores + Usuários e Acessos) — branch `feat/nova-ui-portal-rh`

**Migrado para o AppShell V2 (sem sidebar), só apresentação:** Colaboradores (`/admin/colaboradores`: lista; `rh/editar/{id}`: Dados RH; `{id}/acesso`: Acesso e liderança) e
Usuários e Acessos (`/admin/usuarios`: lista; `novo`; `{id}`: detalhe). Controllers `AdminColaboradoresController` e `AdminUsuariosController` só trocaram o layout;
gates de leitura e escrita, services, repositories, queries, METADADOS e regras intactos. Não existe página de "detalhe do colaborador" separada: a edição
de Dados RH e a tela de Acesso e liderança são as telas de detalhe (breadcrumb `Portal RH › Colaboradores › <nome> › Dados RH | Acesso e liderança`).

**Navegação:** módulo `pessoas` em `PortalNavegacaoService::definicaoAbas()` com as abas **Colaboradores | Usuários e Acessos** (sem nível "Pessoas" no breadcrumb:
`Portal RH › Colaboradores`, `Portal RH › Usuários e Acessos › <usuário>`). A Central mantém os dois cards. Nova regra `staff` (admin/supervisor/rh) para Colaboradores:
- **Divergência sidebar × controller (NÃO corrigida, por regra funcional documentada):** `AdminColaboradoresController::index` comenta explicitamente que a listagem expõe o salário
  individual e que `viewer` NÃO deve vê-la; a sidebar antiga (e o card da Central até o Bloco B) mostrava Colaboradores também a `viewer` com `colaboradores.visualizar` → 403. Ao contrário de
  Pipeline/Indicações/Webhooks (Bloco B), aqui NÃO ampliei o acesso: a Central e as abas passam a espelhar o backend (`staff`) e o card de quem só tem a permissão cai em
  Movimentações. A permissão `colaboradores.visualizar` hoje não libera a listagem — decisão de negócio pendente (liberar exigiria decidir sobre a exposição de salário).
- A aba da página atual aparece mesmo sem acesso, mas **sem link** (RH no detalhe de um usuário: a lista é admin) — `acessivel` em `PortalNavegacaoService::abas()` + `ui_module_tabs`.

**Componentes:** reutilizados `ui_modulo_topo`, `ui_btn`, `ui_titulo_pagina`, `ui_script_pagina`; **novo `ui_badge($texto, $tom)`** (Badge V2: texto sempre visível + token semântico) substituindo `ct-badge*` nas telas do bloco. O detalhe do usuário ganhou a seção
"Identidade e status"; o cadastro foi agrupado em "Identidade e acesso" e "Hierarquia" (mesmos campos e POST).

**CSP — scripts inline externalizados (política `script-src 'self'` intacta):** `colaboradores/rh-form` (toggle da integração + `onclick="this.select()"`) → `assets/colaboradores.js` (`data-select-on-click`);
`usuarios/show` (3 scripts: desvincular METADADOS, busca de contrato, modal/API de senha) → `assets/usuarios.js` (URLs por `data-busca-url`/`data-password-url`; desvincular virou `data-confirm-message`);
`colaboradores/acesso` (2 `onclick=confirm`) → `data-confirm-message`; `colaboradores/index` (`onchange` do "por página") → `data-autosubmit`. Cópias idênticas em `public/assets/`.

**Não migradas (seguem no layout antigo, com sidebar):** Movimentação de Pessoal (módulo próprio, gate `admin/rh/viewer`, fluxo grande — não é acessada por Colaboradores/Usuários; bloco posterior), PDI, dashboards,
pesquisas, entrevistas, cadastros (Empresas/Setores/Cargos/Benefícios/Avaliações), mensagens. **Ainda com script inline/handler (CSP bloqueia), fora do escopo:** `indicadores-rh`, `pesquisa_integracao_qr`, `pesquisa_reacao_integracao`.

**Pendências mantidas fora do escopo:** `public/assets/public.js` defasado (falta `initPublicMenu`); exposição de dados em Indicações (viewer com `indicacoes.visualizar` e APIs `/api/financeiro/*`); testes `integration_dashboard_colaboradores_service.php`
(referencia `AdminController::dashboardDataset()`, removido no commit de People Analytics) e `integration_setores_empresa.php` (PDOException de FK) já falham **desde antes** deste trabalho mas saem com código 0 (o handler global imprime "Erro 500" e não falha o processo).
**Como rodar a suíte com honestidade:** não basta o código de saída — procure "Erro 500"/"FALHOU"/"Uncaught" na saída.

## Nova UI — Bloco D: PDI e Desenvolvimento — branch `feat/nova-ui-portal-rh`

**Migração puramente visual**: `AdminPdisController` (index, novo/seleção, formulário criar/editar, show) passou de `layouts/admin` para `layouts/app-shell`. **Nada funcional mudou**: tabelas
(`pdis`, `pdi_competencias`, `pdi_acoes`, `pdi_acompanhamentos`, `pdi_eventos`), permissões (`pdi.visualizar/gerenciar/acompanhar`, sem permissão nova), escopo (Admin bypass; RH/Admin veem todos;
demais só o PDI em que são `gestor_usuario_id`; Supervisor sem escopo especial), status (rascunho, não iniciado, em andamento, concluído, cancelado), regras (máx. 3 ações, ≥1 para iniciar, conclusão exige
avaliação final + data real, edição travada após concluir, reabertura auditada por RH/Admin), Gestor Imediato apenas como sugestão no formulário, snapshots `metadados_id`, eventos/acompanhamentos append-only e
o marcador "registrado em nome do colaborador" foram preservados. O gate continua `requirePermissao` (sem divergência menu × controller a corrigir).

**Navegação:** breadcrumb `Portal RH › PDI › {colaborador}` + PageHeader + ações contextuais. **Sem ModuleTabs** (PDI é um item único da Central; abas artificiais foram descartadas). O card da Central
(`PortalNavegacaoService`, regra `perm:pdi.visualizar`) não mudou.

**Componentes V2:** `ui_breadcrumb`, `ui_page_header` (com badge do status no título), `ui_btn`, `ui_badge` (tons por status: rascunho=neutro, não iniciado=primary, em andamento=info, concluído=success,
cancelado=danger; ações e "atrasado" idem). **Detalhe** = um único painel (`divide-y divide-border`) com as 9 seções originais (âncoras `#identificacao` … `#historico` preservadas + índice de âncoras),
avisos de estado (concluído em verde, cancelado em vermelho, contrato desligado/divergência em âmbar) e **histórico como linha do tempo** (`border-l` + marcador) — ordem e texto dos eventos inalterados.
Formulários e `<details>` de ações sensíveis (manter/concluir/cancelar/reabrir/evidências/espaço do colaborador) intactos; botão destrutivo via `ui_btn('destrutivo')`.

**CSP:** as views do PDI não tinham `<script>` nem handlers inline (nada a externalizar); `pdis/` entrou na varredura de `unit_assets_publicos.php`. **Tokens:** só tokens do Design System (sem hex solto,
sem `responsive-panel`); nenhum CSS de compatibilidade novo.

**Responsividade validada (1440/1024/768/390/320):** sem scroll horizontal em lista, seleção, criação, edição e detalhe (PDI grande — 3 ações, 5 competências, 12 acompanhamentos, 30+ eventos, nome
longo — e pequeno — rascunho sem itens —, além de concluído e cancelado); 0 erros de console (CSP/404). **Telas legadas** (Dashboard da Entrevista, Dashboard, Empresas, Mensagens, Movimentação)
comparadas com o CSS do HEAD: screenshots full-page **byte-idênticos** em 1440 e 390.

**Testes:** `tests/php/integration_portal_modulo_pdi.php` (AppShell V2 sem sidebar/ModuleTabs, breadcrumb/título, PDI grande/pequeno/cancelado, ações por permissão, escopo Admin/RH/gestor, bloqueio de RH e
Supervisor sem permissão, sem inline script, sem hex); `unit_ui_shell.php` (PDI na lista de migrados) e `unit_assets_publicos.php` (varredura de `pdis/`).

**Metodologia estrita da suíte PHP (regra permanente):** um teste só passa se sair com código 0 **e** sua saída não tiver `Erro 500`, `FALHOU`, `Uncaught` nem `Fatal error` (o handler global imprime "Erro 500"
e sai com 0). Um gate `requirePermissao` responde 403 com `exit` — não o exercite por renderização dentro de teste (pula o `finally`/limpeza); prove o bloqueio por `Authorization::usuarioTemPermissao` +
serviço. **Estado atual: 94/96**, com **duas falhas pré-existentes conhecidas** (verificadas no HEAD, não corrigidas): `integration_dashboard_colaboradores_service.php` (chama `AdminController::dashboardDataset()`,
removido) e `integration_setores_empresa.php` (PDOException de FK). Um teste novo aprovado aumenta o denominador; uma terceira falha é regressão.

## Nova UI — Bloco E: Pesquisas, Turnover e Desligamento — branch `feat/nova-ui-portal-rh`

**Migração visual das telas ADMINISTRATIVAS para o AppShell V2** (só o layout e a apresentação; controllers só trocaram `layouts/admin` → `layouts/app-shell`; gates de role e permissão, services, cálculos,
tokens, endpoints, POSTs e CSRF intactos): Dashboard de Turnover, Dashboard da Entrevista de Desligamento, Entrevistas de Desligamento (lista/geração/regeneração/cancelamento + resultado individual),
Central de Pesquisas de Integração (Reação + resultados da Integração via QR), resultados de uma campanha de Reação, resultados da Integração por data e a administração do QR Code.

**Navegação (sem megabarra):** dois módulos pequenos e reais em `PortalNavegacaoService::definicaoAbas()`, os mesmos agrupamentos dos cards da Central (que não mudaram):
- `integracao` — *Pesquisas e resultados* | *QR Code da Integração*. Regra nova `perm_qualquer:<a>,<b>` (a Central de Pesquisas abre com `pesquisa_reacao_integracao.visualizar` **ou** `integracao_colaborador.visualizar`,
  exatamente como o controller); o QR exige `integracao_colaborador.visualizar`. Telas de resultados ficam sob a aba *Pesquisas e resultados* (trilha extra).
- `desligamento` — *Turnover* | *Dashboard da Entrevista* | *Entrevistas de Desligamento* (cada uma com a sua permissão `perm:`). O resultado individual fica sob *Entrevistas*.
Uma aba só aparece se o destino abre (testado lendo os gates dos controllers); o card da Central continua apontando para o primeiro destino realmente acessível. Nenhuma permissão nova; Admin pelo bypass central.

**Componentes V2 reutilizados:** `ui_modulo_topo` (breadcrumb + PageHeader + ModuleTabs), `ui_btn`, `ui_script_pagina`; cards/KPIs/filtros mantêm a marcação existente mapeada para tokens
(`rounded-ds-*`, `border-border`, `bg-surface`, `text-text-*`, `bg-primary-*`, `success/warning/danger`), sem componente novo. Os helpers de gráfico (`partials/chart-helpers.php`) **não foram alterados**;
só as cores de série passadas a eles (atributos SVG) continuam como hex — não existe classe equivalente. Semântica preservada: competência do Dashboard da Entrevista = data de desligamento; cobertura
elegíveis → geradas → respondidas (Falecimento 020 fora); gráfico de liderança compacto (~300px, `max-w-[780px]`, escala 1–5, `null` ≠ zero, estado "Sem base" compacto); Turnover = desligamentos ÷ média(headcount
início, fim) × 100 com o mapa de motivos do próprio service (`MAPA_MOTIVOS`: Voluntário 003/006, Involuntário 002/007, Justa Causa 001, Término 005/008, Acordo 016, resto em Outros — não reutiliza o de People Analytics);
filtros: Turnover Ano/Empresa/Cargo, Entrevista período/unidade/cargo (sem Área/Gestor/tipo).

**CSP — encontrado e resolvido nas telas do bloco:** 2 handlers inline `onsubmit="return confirm(...)"` (encerrar integração do QR; desativar campanha de Reação) → `data-confirm-message` (tratado por `admin.js`);
os scripts do QR (`qrcode.js`, `integracao-qr.js`) já eram externos, passaram a ser registrados com `ui_script_pagina()` (ordem preservada). O `<style>` de impressão do QR continua inline (a CSP já permite `style-src 'unsafe-inline'`;
não foi alterada). Nenhum `unsafe-inline` de script e nenhum nonce. Console: 0 erro CSP e 0 404 nas 13 telas, em 1440/1024/768/390/320.

**Achado de responsividade (pré-existente, corrigido só neste bloco):** o CSS global esconde `.mobile-table-desktop` abaixo de 769px e exige uma `responsive-card-list` alternativa; as telas de Entrevistas, Central de Pesquisas
e QR nunca tiveram essa lista — no layout antigo a tabela some no mobile. Nas views do Bloco E a classe foi removida (a tabela aparece com rolagem interna em `responsive-table-wrap`; desktop idêntico) e um teste impede a regressão.
**Ainda afetadas (fora deste bloco, não alteradas):** `pdis/index`, `pdis/selecionar-contrato`, `recruitment_webhooks/index` (Blocos B/D), `avaliacoes/index` e `movimentacoes_pessoal/index`.

**Telas PÚBLICAS mantidas como estão** (não usam nem devem usar o AppShell): Entrevista de Desligamento em `layouts/publico-seguro` (no-referrer, no-store, noindex, CSP própria, **nenhum request externo**);
Pesquisa de Reação, Pesquisa de Integração (token) e Integração via QR em `layouts/main` (com Google Fonts — comportamento anterior, intocado). Sem link/navegação administrativa, sem script/handler inline.
Token/QR/URL/expiração/invalidação/hash SHA-256/elegibilidade não foram tocados.

**Suíte PHP — explicação da contagem:** o baseline "91/93" usava `unit_*.php` + `integration_*.php` (93 arquivos). `tests/php/*.php` também contém 2 scripts `fixture_*` (não são testes; saem com 0), o que faz
"94/96" (Bloco D) = 92/94 + 2 fixtures. Critério estrito (sem `Erro 500`/`FALHOU`/`Uncaught`/`Fatal error`, exit 0): runner oficial = `unit_*` + `integration_*`. As duas falhas pré-existentes seguem as mesmas
(`integration_dashboard_colaboradores_service.php`, `integration_setores_empresa.php`). Teste novo: `integration_portal_modulo_pesquisas_desligamento.php`.

## Nova UI — Bloco F: Indicadores e Dashboards — branch `feat/nova-ui-portal-rh`

**Mapa real (o que os nomes antigos significam):** existem **duas** telas administrativas, com services independentes — não três.
- **People Analytics** = o antigo "Dashboard principal", hoje `/admin/dashboard` (`AdminController::index`, service `PeopleAnalyticsService`, gate `Auth::requireRole([admin,rh,viewer])` + `dashboard.visualizar`). Semântica por **CONTRATO** (sem deduplicar por pessoa); Turnover próprio
  (`desligamentos ÷ média(headcount início, fim) × 100`) com mapa de motivos **próprio** (`CODIGOS_VOLUNTARIO` 003/006; `CODIGOS_INVOLUNTARIO` 001/002/007) — distinto do Dashboard de Turnover do Bloco E (Involuntário 002/007; 001 = Justa Causa).
- **Indicadores de RH** = `/admin/indicadores-rh` (`AdminRhIndicadoresController`, `RhIndicadoresService`, dicionário em `docs/claude/indicadores-rh.md`), **sem permissão individual** (só o gate de role admin/rh/viewer — comportamento anterior, mantido e testado; reportado, não alterado).
  Compartilha com o People Analytics apenas os helpers de gráfico e a fonte "Última atualização"; o botão "Atualizar dados" aciona `AdminMetadadosSyncController` (POST `/admin/indicadores-rh/sincronizar`, GET `.../status`, gate admin/rh).
- Dashboard de Recrutamento, de Turnover e da Entrevista de Desligamento são de outros módulos (Blocos B/E). `/admin` continua sendo a Central; `/admin/dashboard` não voltou a ser home.

**Migrado para o AppShell V2** (só apresentação; `AdminController` e `AdminRhIndicadoresController` apenas trocaram o layout): `admin/dashboard` e `admin/indicadores-rh`. Filtros, query strings, autosubmit (`data-autosubmit`), cálculos, cards, gráficos e links preservados;
nenhum KPI/filtro novo.

**Navegação:** módulo `indicadores` em `PortalNavegacaoService::definicaoAbas()` — **People Analytics** (`perm:dashboard.visualizar`) | **Indicadores de RH** (`aberto`), sem nível de módulo no breadcrumb (`Portal RH › People Analytics` / `Portal RH › Indicadores de RH`).
As duas são telas irmãs reais, com permissões distintas; a aba só aparece se o destino abre (testado lendo os gates). O card da Central continua idêntico: primeiro destino acessível (`dashboard.visualizar` → People Analytics; senão → Indicadores de RH).
O botão "Voltar ao Dashboard" de Indicadores de RH (apontava para `/admin`, que agora é a Central) foi substituído pelas abas.

**Componentes V2:** reutilizados `ui_modulo_topo`, `ui_btn`, `ui_script_pagina`; **nenhum componente novo** (`ui_metric_card`/`ui_filter_bar`/`ui_empty_state` não foram criados: os KPIs são poucos e cada tela tem densidade própria; a marcação existente foi mapeada para tokens).
Gráficos: helpers `partials/chart-helpers.php` intocados. Cores decorativas (barras, linha do tempo) → tokens (`primary-*`); a rosca de People Analytics mantém hex porque os segmentos e a legenda (Voluntário/Involuntário/Outros) precisam casar. `0` real continua `0` e "Dados insuficientes"/"Sem base" continuam textuais (services/queries não mudaram).

**CSP — Indicadores de RH:** o único `<script>` inline (botão de sincronização) foi movido, sem mudança de lógica, para `assets/indicadores-rh.js` (cópia em `public/assets/`; registrado por `ui_script_pagina('indicadores-rh.js')`). Classes do botão por `data-classe-base`/`data-classe-ok` (declaradas na view para o Tailwind varrer);
cores de feedback = tokens (info/danger/primary-700). O `<style>` com `.ind-*` foi substituído por utilitários. Nenhum `unsafe-inline` de script. Validado no navegador: POST 202 → polling a cada 4s (até 3 min) → "Sincronizado" → recarrega; falha do orquestrador; erro HTTP.

**Correção mobile das telas JÁ migradas (aprovada):** `pdis/index`, `pdis/selecionar-contrato` e `recruitment_webhooks/index` perderam `.mobile-table-desktop` (o CSS global escondia a tabela abaixo de 769px, sem lista de cards). A tabela agora aparece e rola dentro de `responsive-table-wrap`, sem scroll no documento (validado em 390/320).
Teste `integration_portal_modulo_indicadores.php` falha se uma tela migrada tiver `.mobile-table-desktop` sem `responsive-card-list`. **Continuam legadas e intocadas:** `avaliacoes/index` e `movimentacoes_pessoal/index` (serão tratadas quando migrarem).

**Ainda legado (idêntico ao HEAD, pixel a pixel em 1440 e 390):** Mensagens, Empresas, Setores, Cargos, Benefícios, Avaliações, Movimentação de Pessoal.

**Suíte PHP oficial = `unit_*.php` + `integration_*.php`** (o glob `tests/php/*.php` inclui 2 `fixture_*` que não são testes). Baseline antes do Bloco F: **93/95**, com exatamente `integration_dashboard_colaboradores_service.php` e `integration_setores_empresa.php` falhando (pré-existentes).
Teste novo: `integration_portal_modulo_indicadores.php`.

## Nova UI — Bloco G (final): Cadastros, Mensagens, Movimentação de Pessoal e Manual — branch `feat/nova-ui-portal-rh`

**Inventário que originou o bloco** (busca por `'layouts/admin'`): AdminAvaliacoes, AdminBeneficios, AdminCargos (via `AdminCatalogosController`), AdminSetores, `core/AdminCatalogosController` (Empresas e Cargos), AdminMensagens, AdminMovimentacoesPessoal e **AdminManual**
(tela esquecida nos blocos anteriores, só alcançável pela sidebar). Não existe tela própria de **Unidades** (só a sincronização interna), então nenhuma aba/tela foi criada para ela.

**Migrado para o AppShell V2** (controllers só trocaram o layout; gates, services, models, queries e POSTs intactos; **campos `name=`, `action=` e `data-*` de 13 views comparados com o HEAD — idênticos**):
- **Cadastros** (`catalogos/{index,form}` = Empresas e Cargos; `setores/{index,form}`; `cargos/form`; `beneficios/{index,form}`; `avaliacoes/{index,form}`) com módulo de abas `cadastros`: Empresas | Setores | Cargos | Benefícios | Avaliações (todas `aberto`: a listagem de cada uma só exige `requireRole([admin,rh,viewer])`, igual ao card da Central). Formulários com breadcrumb `Portal RH › Cadastros › <aba> › Novo/Editar`.
- **Mensagens** (lista + formulário) e **Movimentação de Pessoal** (lista + formulário/detalhe): tela única com ações contextuais → só Breadcrumb + PageHeader (sem ModuleTabs). Mensagens mantém `mensagens.visualizar/criar/editar`; Movimentação mantém os gates de role e a regra de autoria/assinatura. O JS de `admin.js`
  (placeholders/pré-visualização das Mensagens; formulário de Movimentação) continua por `data-*` (`data-mensagem-*`, `data-movimentacao-pessoal-form`, `data-movimentacao-payload`); os `<script type="application/json">` são blocos de dados, não código (compatíveis com a CSP).
- **Manual de Uso**: breadcrumb + re-tematização do `<style>` próprio com os hex do Design System. Como só existia na sidebar, ganhou uma **entrada discreta na Central** (link de texto, não card; só aparece quando há módulos).

**CSP:** nenhum `<script>` executável nem handler inline nas telas do bloco (confirmações já eram `data-confirm-message`). Console: 0 erro CSP / 0 Uncaught nas 24 telas em 1440/1024/768/390/320. Os 404 em Benefícios são **logos enviados por usuários** referenciados no banco local e ausentes no disco local (dado de ambiente).

**Correções mobile:** `avaliacoes/index` e `movimentacoes_pessoal/index` perderam `.mobile-table-desktop` (tabela sempre visível, rolagem interna em `responsive-table-wrap`). Nas telas que têm lista de cards (`catalogos/index`, `setores/index`, `beneficios/index`, `mensagens/index`) a tabela virou `hidden md:table`, complementar ao `md:hidden` dos cards em qualquer largura.
**Achado (pendente, aguardando aprovação):** o CSS global só mostra `.mobile-table-desktop` a partir de 769px e o Tailwind esconde `md:hidden` a partir de 768px — em **exatos 768px** (iPad retrato) `candidaturas/index`, `colaboradores/index`, `usuarios/index`, `solicitacoes_vaga/index` e `vagas/index` (Blocos B/C) ficam sem tabela e sem cards. Correção: a mesma `hidden md:table`. O teste do Bloco G registra essas cinco como pendência conhecida e falha se surgir uma sexta.

**Inventário final de `layouts/admin` / sidebar antiga:** nenhum controller renderiza mais `layouts/admin` (teste em `unit_ui_shell.php`). Restam, sem uso em runtime das telas administrativas: `app/views/layouts/admin.php` + `layouts/sidebar.php` (o layout e a sidebar), o código de sidebar em `assets/admin.js`/CSS (`.sidebar`, `.app-header`, `.mobile-*`, `.content`, `ct-*`),
comentários em `app-shell.php`/`ui-shell.php`, e testes que ainda leem a sidebar como referência (`integration_portal_central.php`, `integration_people_analytics.php`, `unit_ui_shell.php`, `unit_config_asset_version.php`). **Fora do AppShell por natureza:** login (`admin/login`) e recuperação de senha (`layouts/main`, com Google Fonts) — telas de autenticação, decisão da revisão global.
Nada foi removido neste bloco.

**Suíte PHP oficial** (`unit_*` + `integration_*`): baseline 94/96 → **95/97** com o teste novo `integration_portal_modulo_cadastros_mensagens_movimentacao.php` (75 verificações); as mesmas duas falhas pré-existentes (`integration_dashboard_colaboradores_service.php`, `integration_setores_empresa.php` — esta última não tocada, conforme combinado).

## Nova UI — Revisão global (antes do commit consolidado) — branch `feat/nova-ui-portal-rh`

**Correção do vão de 768px (aprovada):** `candidaturas`, `colaboradores`, `usuarios`, `solicitacoes_vaga` e `vagas` (`index`) passaram a `hidden md:table` (complementar ao `md:hidden` dos cards). A varredura global achou um **sexto** caso do mesmo vício — `indicacoes/index` (`.mobile-block-desktop` + cards `md:hidden`) —,
corrigido igual (`hidden md:block`). Nenhuma view usa mais `.mobile-table-desktop` nem `.mobile-block-desktop` (as duas classes só existem no CSS legado); testes em `integration_portal_modulo_cadastros_mensagens_movimentacao.php`.
Matriz responsiva: 27 telas × 7 larguras (1440/1024/769/768/767/390/320) = 189 combinações, **0 falhas** (sem conteúdo escondido, sem scroll horizontal do documento, abas alcançáveis, ações visíveis).

**Estado da migração:** todas as telas administrativas funcionais estão no AppShell V2; **nenhum controller renderiza `layouts/admin`** (teste em `unit_ui_shell.php`). Fora do AppShell por natureza: login, recuperação de senha (`layouts/main`) e as páginas públicas (`layouts/main` / `publico-seguro`).
Navegação: 7 perfis (admin, rh, viewer, supervisor, viewer com poucas permissões, gestor, rh completo) percorreram **todos os cards da Central e todas as abas** — 200 no AppShell V2, sem sidebar, 0 CSP, 0 Uncaught. `/admin` = Central (todos os links `href=/admin` restantes são o "Portal RH" do breadcrumb/logotipo);
`/admin/dashboard` = People Analytics. `Authorization::primeiraRotaAcessivel()` devolve `/admin` (Central) para admin/rh/viewer/supervisor — sem loop, sem 403 pós-login.

**CSP global (todas as views):** 0 `<script>` executável inline, 0 handlers inline. 3 blocos `<script type="application/json">` (dados: Mensagens, Movimentação, Solicitação de Vaga). 5 `<style>` inline (Indicações — modal; Manual; QR; `layouts/admin.php`; `layouts/main.php`), permitidos pela CSP atual (`style-src 'unsafe-inline'`, não alterada). 404 de upload local (logos de Benefícios) é dado ausente do ambiente, não asset.

**Assets:** `assets/` × `public/assets/` idênticos para admin, phone-utils, candidaturas, indicacoes, colaboradores, usuarios, indicadores-rh, integracao-qr, qrcode e share-utils; `tailwind.css` idêntico. **Divergência conhecida (não corrigida):** `public.js` — a cópia pública tem 66 linhas a menos (sem `initPublicMenu`/`initPhoneMask`); é carregado só por `layouts/main` (login/recuperação/vagas públicas).

**Componentes V2 ativos:** AppShell (`layouts/app-shell`), `ui_header_v2`, `ui_breadcrumb`, `ui_page_header`, `ui_module_tabs`, `ui_module_card`/`ui_module_grid`/`ui_module_icon` (Central), `ui_modulo_topo` (36 arquivos), `ui_btn` (30), `ui_badge`, `ui_script_pagina` (8), `ui_titulo_pagina`. Só login e recuperação de senha não usam nenhum helper V2.

**Candidatos à remoção (NÃO removidos):** `app/views/layouts/admin.php`; `app/views/layouts/sidebar.php`; lógica de sidebar em `assets/admin.js` (~110 linhas: `data-admin-sidebar`, collapse, grupos) e sua cópia em `public/assets/admin.js`; CSS legado em `assets/tailwind-input.css` (`.app-shell`, `.sidebar*`, `.app-header`, `.menu-toggle`, `.content`, `.mobile-*`, regras `@media` da sidebar);
comentários desatualizados em `app-shell.php`, `ui-shell.php` ("nenhuma tela atual usa…", "coexiste com layouts/admin"), `PortalNavegacaoService` e `AdminCentralController`; a rota de desenvolvimento `/admin/central` (só redireciona). Reaproveitadas e a manter: `.responsive-panel/-card/-table-wrap/-form-actions/-pagination/-card-list` (usadas por 11–20 views).

**Testes que dependem da sidebar (preservar a intenção):** `integration_portal_central.php` (compara Central × sidebar renderizada → reescrever contra as regras do `PortalNavegacaoService` + gates dos controllers); `integration_permissoes_cobertura_portal.php` (renderiza a sidebar por usuário → renderizar os `modulos()` da Central); `integration_people_analytics.php` e mais seis
(`dashboard_entrevista_desligamento`, `dashboard_turnover`, `entrevista_desligamento`, `pdi`, `pesquisa_integracao_qr`, `pesquisa_reacao_integracao`), cada um com 1 asserção sobre o **fonte** da sidebar → trocar por asserção sobre a regra `perm:` do item no `PortalNavegacaoService`; `unit_ui_shell.php` (compat "layout antigo e sidebar intactos" → inverter para "removidos") e
`unit_config_asset_version.php` (tirar `layouts/admin.php` da lista). Os cinco `integration_portal_modulo_*` só têm checagens negativas ("sem sidebar") e continuam válidos. Specs Playwright `admin-responsive`/`admin-sidebar-collapse` testam a sidebar (obsoletos) e outros usam `/admin` como dashboard antigo — fora da suíte oficial, precisam de revisão.

**`PortalNavegacaoService` hoje:** é a fonte de verdade das telas migradas (cards + abas), mas o cabeçalho ainda diz que "replica a sidebar" e que o teste da Central compara com ela. Com a sidebar aposentada: trocar a comparação por gates de controller (já feito nos testes de módulo), remover o texto de duplicação temporária e manter a regra `staff`/`perm_qualquer`.

**Pendências conhecidas (inalteradas):** 1) `integration_dashboard_colaboradores_service.php`; 2) `integration_setores_empresa.php`; 3) `public/assets/public.js` defasado; 4) exposição de dados em Indicações; 5) `colaboradores.visualizar` × salário; 6) `/admin/indicadores-rh` sem permissão individual; 7) cache/CDN (versão do CSS congelada); 8) Google Fonts nas públicas (Reação, Integração, QR, Experiência, vagas, login/recuperação; **a Entrevista de Desligamento não faz nenhum request externo**). NewBlack: sem arquivo oficial, o fallback de sistema (`"Segoe UI", Helvetica, Arial`) segue adequado.

**Recomendação para login/recuperação de senha:** manter como estão nesta publicação (`layouts/main`, Montserrat, sem risco de regressão de autenticação); migrar depois numa etapa curta e isolada, junto com a decisão sobre Google Fonts.

## Nova UI — Bloco H: Manual de Uso real + centralização de Avaliações — branch `feat/nova-ui-portal-rh`

**Manual de Uso (`/admin/manual`, `AdminManualController`, gate inalterado):** reescrito de zero. Antes era uma landing genérica com `<style>` próprio (paleta azul antiga, `#0d1321`/`#3e5c76`/`#1d2d44`) e seis blocos superficiais ("Nesta tela você pode gerenciar..."). Agora é
documentação operacional real (Wiki + FAQ), derivada do código (rotas/controllers/services/permissões/campos), no AppShell V2 com tokens do Design System. Estrutura: busca local → índice por módulo (15 seções, refletindo os módulos reais do Bloco A ao G) → um `<details id="âncora-estável">`
por funcionalidade (34 no total) → FAQ global (13 perguntas) → rodapé de versão. Cada item segue o padrão O que é / Para que serve / Quem pode utilizar / Como acessar / Como utilizar / Campos (tabela) / Ações / O que acontece depois / Status / Atenções / Perguntas frequentes, só com os
blocos que fazem sentido para aquela funcionalidade (helper `manual_item()`/`manual_bloco()` em `manual.php`, sem duplicar nada em CSS/JS). Documenta explicitamente diferenças que já causaram confusão real: Gestor Imediato × Aprovador de Solicitação de Vaga (campos distintos do usuário),
Colaboradores por contrato (não por pessoa), People Analytics × Indicadores de RH (telas irmãs, services distintos), Pipeline × Kanban de Solicitações (máquinas de estado separadas), motivo `020 — Falecimento` nunca gera Entrevista de Desligamento, limite de 3 ações/mínimo 1 para iniciar o PDI.
**Não documenta** "Avaliação de Período de Experiência" nem "Feedback e Desenvolvimento" como funcionalidades próprias — não existem no código (nenhuma rota/controller); a Entrevista de Desligamento é descrita sem a classificação Voluntário/Involuntário porque a pesquisa real não a usa.

**Busca:** `assets/manual.js` (cópia idêntica em `public/assets/`, registrado por `ui_script_pagina('manual.js')`), sem backend/indexador — filtra os `<details data-manual-item data-manual-texto="…">` pelo texto digitado, normalizando acento dos dois lados (`gestão`/`gestao` batem igual),
abre os que baterem e fecha o resto; campo vazio restaura tudo fechado. Âncoras (`id`) são estáveis para links diretos futuros (`/admin/manual#pdi-visao-geral`) sem precisar de botões de ajuda nas telas agora.

**Avaliações — inventário real (três conceitos, uma única tela de cadastro):**
1. **Avaliações de Desempenho** (`/admin/avaliacoes*`, `AdminAvaliacoesController` + `AvaliacaoDesempenho` + tabela `colaborador_avaliacoes`) — o único cadastro próprio: colaborador, título, período, nota, resumo. Sem permissão individual (só `requireRole`). Referenciada como FK obrigatória
   (`avaliacao_desempenho_id`) ao abrir uma Movimentação de Pessoal.
2. **"Avaliação após 90 dias"** — não é uma tela: é o campo `solicitacoes_vaga.avaliacao_90_dias`, preenchido pelo RH na seção 7 ("Controle interno RH") do detalhe de uma Solicitação de Vaga aprovada. Alimenta o bloco "Experiência" do People Analytics.
3. **Pesquisa de Experiência do Candidato** — não é uma tela de Avaliações: é a pesquisa pública (`/experiencia/{token}`) respondida pelo candidato sobre o processo seletivo, visível no detalhe de uma Candidatura só com `pesquisa_experiencia.visualizar`.
**"Feedback e Desenvolvimento"** não existe no código (0 rotas) — confirmado por busca literal; permanece fora, sem placeholder.

**Decisão de navegação (Cenário A, não B):** como existe só um cadastro real com formulário próprio, **Avaliações continua dentro do card Cadastros** (sem card próprio na Central, sem `ModuleTabs` internas — não há "Formulários | Respostas | Resultados" reais para nomear). O problema de
descoberta relatado foi resolvido sem tocar rota/service/permissão: `avaliacoes/index.php` ganhou um `<details>` "Outras avaliações do Portal" com dois links — para Solicitações de Vaga (sempre visível) e para Candidaturas (só com `pesquisa_experiencia.visualizar`, testado nos dois sentidos) —
mais um link de âncora para `/admin/manual#avaliacoes`, que por sua vez documenta e linka de volta os três conceitos.

**Teste novo:** `integration_portal_manual_avaliacoes.php` — Manual no AppShell V2 sem sidebar/inline, todas as 34 âncoras presentes sem duplicata, ausência de frases genéricas de placeholder, conteúdo correto (Gestor Imediato × Aprovador, contrato × pessoa, 020/Falecimento, limite de ações do
PDI), ausência de "Avaliação de Experiência"/"Feedback e Desenvolvimento" como funcionalidade; bloco de Avaliações condicionado corretamente à permissão real; nenhuma rota/permissão nova (6 rotas de Avaliações e 1 de Manual, como já existiam). `unit_assets_publicos.php` ampliado para escanear
`manual.js`/`manual.php`/`avaliacoes/`.

**Suíte PHP oficial (`unit_*` + `integration_*`):** baseline 95/97 → **96/98** com o teste novo, mesmas duas falhas pré-existentes.

---

## Publicação consolidada da Nova UI (limpeza final)

Fechamento da lista de pendências deixada pela Revisão Global (acima): `layouts/admin.php` e `layouts/sidebar.php`
**removidos** do repositório (não só "candidatos" — a base de comparação de commit é `d8aff2f`). `layouts/app-shell.php` é agora
o único shell administrativo; `PortalNavegacaoService` é a única fonte de navegação (cards da Central + abas de módulo),
sem mais nenhuma menção a "replicar" ou "comparar com" a sidebar no seu cabeçalho.

**Testes reescritos (preservando a intenção, não a implementação):**
- `integration_portal_central.php` — a comparação "Central × sidebar renderizada" virou uma comparação "Central × gate real do
  controller": para 18 perfis (Admin/RH/viewer/supervisor + 14 usuários de permissão individual), todo item visível na Central
  é checado contra o `Auth::requireRole`/`Authorization::requireRoleOuPermissao`/`Authorization::requirePermissao` real do
  controller que atende aquele destino (parser genérico de gate, não uma tabela hardcoded) — prova "nenhum card leva a 403"
  com mais rigor que o diff textual contra HTML de sidebar.
- `integration_permissoes_cobertura_portal.php` — `$renderizarSidebar()` (que fazia `View::renderPartial('layouts/sidebar')`)
  virou leitura direta de `PortalNavegacaoService::modulos()`/`abas()`; a prova do modelo aditivo (permissão individual libera
  destino sem tirar o que a role já dava) passou a usar `pipeline.visualizar` de ponta a ponta (item genuinamente aditivo no
  novo modelo — `Auth::requireRole` do backend não inclui `viewer`), e o caso de Colaboradores foi reformulado para provar
  explicitamente a exceção documentada (o card sempre existe porque Movimentações é aberta, mas nunca aponta para
  `/admin/colaboradores` para quem não é staff, mesmo com a permissão).
- Sete testes de módulo (`integration_people_analytics`, `_dashboard_entrevista_desligamento`, `_dashboard_turnover`,
  `_entrevista_desligamento`, `_pdi`, `_pesquisa_integracao_qr`, `_pesquisa_reacao_integracao`) trocaram a asserção "o link só
  existe dentro de um `if Authorization::temPermissao(...)` no código-fonte da sidebar" por "o item correspondente em
  `PortalNavegacaoService::definicao()` tem exatamente a regra `perm:<código>`" — mesma garantia, fonte nova.
- `unit_ui_shell.php` — a whitelist de "quais telas já migraram" (apropriada para migração progressiva) virou prova de estado
  final: `layouts/admin.php`/`layouts/sidebar.php` não existem mais no disco, nenhum arquivo do projeto referencia
  `'layouts/admin'`/`'layouts/sidebar'`/`data-admin-sidebar`/a classe `.menu-toggle` (com cuidado para não confundir com
  `data-public-menu-toggle`, o menu da vitrine pública — recurso diferente, mantido).
- `unit_config_asset_version.php` — trocou `layouts/admin.php` por `layouts/app-shell.php` na lista de views que usam
  `Config::assetVersion()`.

**Playwright:** `admin-sidebar-collapse.spec.js` (100% sidebar: cor de ícone, collapse, largura) **removido** — a
funcionalidade não existe mais. `admin-responsive.spec.js` reescrito para a Nova UI: Central sem sidebar/menu-toggle/overlay
em 5 larguras (320–1024px), navegação real clicando no card "Recrutamento e Seleção", People Analytics (`/admin/dashboard`) e
Pipeline Kanban, sempre sem overflow horizontal do documento.

**`admin.js`:** removida só `initAdminSidebar()` (~160 linhas: collapse desktop/mobile, `localStorage`, overlay, grupos
`<details>`) e sua chamada em `DOMContentLoaded`. Confirmações, autosubmit, máscaras, Mensagens, Movimentação, Solicitação de
Vaga, importação de Colaboradores e os dois Kanban continuam intactos — suíte `npm run test:unit` (5 arquivos) verde sem
nenhuma alteração. Sincronizado com `public/assets/admin.js` (idêntico).

**`tailwind-input.css`:** removidas as regras exclusivas do shell antigo — `.app-shell`/`.app`, `.sidebar*` (12 seletores),
`.content`/`.content input,select,textarea,button`, `.app-header`/`.app-header-brand*`, `.app-overlay`/`.app-sidebar-open`,
`.app-footer`, os blocos `@media` que só existiam para eles, e `.mobile-table-desktop`/`.mobile-block-desktop` (0 uso
remanescente em `app/views` — a "lacuna de 768px" já tinha sido corrigida em todas as views nos blocos anteriores). **Não
removidas** (uso real confirmado por grep, não por suposição): `.app-nav-toggle`/`.touch-target` (menu da vitrine pública,
`layouts/main.php`), `.ct-btn*`/`.ct-badge*` (`mensagens/index.php`, `home/confirm.php`), `.responsive-*`, `.kanban-board`/
`.sv-kanban-board`, `.text-fluid-*`, `.snap-x`/`.snap-start`. `npm run build:css` recompilado; `assets/tailwind.css` e
`public/assets/tailwind.css` idênticos (`diff -q`).

**Comentários atualizados:** `PortalNavegacaoService`, `app-shell.php`, `partials/ui-shell.php` e `AdminCentralController`
não descrevem mais o AppShell como "OPT-IN" nem a Central como algo que "replica"/"compara com" a sidebar — passam a
descrevê-lo como o shell único e `PortalNavegacaoService` como a fonte oficial de navegação.

**`/admin/central`:** mantida como redirect 302 para `/admin` (não removida — é usada só como URL de compatibilidade,
0 referência funcional restante além do próprio `index.php`, provado por `integration_portal_central.php`).

**CSP:** 0 `onclick=`/`onsubmit=`/`onchange=`/`oninput=`, 0 `<script>` executável em `app/views/admin` — só os 3 blocos
`type="application/json"` já existentes (Solicitação de Vaga, Mensagens, Movimentação).

**Suíte PHP oficial:** baseline mantida em **96/98**, mesmas duas falhas pré-existentes e sem relação
(`integration_dashboard_colaboradores_service.php`, `integration_setores_empresa.php`) — nenhuma terceira falha introduzida
pela limpeza.
