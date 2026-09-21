# DESIGN SYSTEM + GUIA DE IMPLEMENTAÇÃO VISUAL — PORTAL RH MADEPLANT

Documento de handoff para implementação. Este arquivo especifica a UI/UX já definida e aprovada — não é um convite a redesenhar. Stack de destino: **PHP + Tailwind CSS + JavaScript quando necessário**. Não usar React/Vue/Angular. Não migrar para SPA.

## 0. Regra inegociável para o implementador

Esta é uma migração **visual**. É proibido alterar, por iniciativa própria:

- regras de negócio, cálculos, validações, textos de negócio;
- autorização, permissões, papéis;
- rotas, controllers, services, repositories;
- banco de dados, migrations, queries;
- workflows, integrações, webhooks;
- nomes/semântica de status funcionais existentes.

Se uma tela parecer exigir mudança funcional para caber no design, **pare e reporte** — não decida sozinho.

---

## 1. Contexto do produto

Portal RH Madeplant, em produção, com módulos reais (Recrutamento, Solicitações de Vaga, Colaboradores, Usuários, People Analytics, Turnover, Pesquisa de Integração, Pesquisa de Reação, Entrevista de Desligamento e seu dashboard, PDI, Cadastros administrativos, entre outros). A nova UI é aplicada **sobre** o sistema existente, preservando 100% do comportamento.

---

## 2. Identidade visual

Cor institucional principal: **Pantone 5747 C / #3B4822** (verde Madeplant). A interface deve transmitir solidez, profissionalismo, modernidade, clareza, organização, proximidade — tecnologia sem exagero futurista.

Evitar: aparência de template SaaS genérico; gradientes decorativos; sombras pesadas; arredondamento excessivo; cards gigantes; visual infantil; cores saturadas; excesso de informação simultânea; sidebar visualmente pesada; indentação confusa.

A cor institucional deve ter força **por ser usada com parcimônia** — a maior parte da interface permanece em superfícies claras e neutras. Nenhum módulo recebe cor própria; distinção é por ícone/conteúdo, nunca por matiz.

---

## 3. Princípios da experiência

Mobile-first, responsiva, compacta, gerencial, consistente, acessível, rápida de ler, clara na hierarquia, eficiente para uso diário.

Prioridade: **1) informação → 2) ação → 3) contexto → 4) estética.** Estética apoia o trabalho, não compete com ele. Regra de decisão: *"Este elemento precisa realmente de cor/ornamento?"* — se não, mantenha neutro.

---

## 4. Conceito de navegação

Substitui a sidebar vertical crescente por uma **arquitetura orientada a módulos**:

```
Portal RH → Central de Módulos → Módulo → Funcionalidade
```

- **Sem sidebar fixa.** A área de conteúdo usa praticamente 100% da largura útil.
- **Central do Portal RH** é a tela inicial: apresenta os módulos aos quais o usuário tem acesso como cards, agrupados em uma única grade (não em subseções fixas — evita fragmentar a leitura).
- Dentro de um módulo, a navegação entre suas funcionalidades é uma **barra de tabs horizontal** (ModuleTabs), nunca outra sidebar interna.
- **Breadcrumb** discreto sempre presente em telas internas, iniciando em "Portal RH" (link de retorno à Central): `Portal RH › Recrutamento e Seleção › Pipeline Kanban`.
- **Header global** compacto e fixo no topo (64px): logotipo Madeplant + "Portal RH" à esquerda; usuário + avatar de iniciais à direita. Não acumula menus — é só identidade e usuário.

**Ativo/aberto:** tab ativa = cor primária + borda inferior 2.5px; item de breadcrumb atual = texto escuro não clicável, anteriores clicáveis em verde institucional. Não há conceito de "grupo aberto/fechado" — a Central substitui a necessidade de expandir/colapsar grupos na sidebar.

**Responsivo:** desktop usa a largura total com gutter de 40px; tablet reduz gutter para 24px e a grade de cards reduz colunas naturalmente; mobile (gutter 16px) simplifica o header (oculta nome do usuário, mantém avatar) e as tabs internas passam a scroll horizontal — nunca sidebar fixa em nenhuma resolução.

Permissões: a Central e as tabs exibem **apenas** o que o backend autoriza — o Design System não cria regra de autorização, só reflete visualmente o que já existe. A grade deve ficar equilibrada com 1, 2, 8 ou 12+ cards (ver §5).

---

## 5. Central / Home do Portal

**Cabeçalho da Central:** dentro do Header global, mais um bloco de saudação — "Central do Portal RH" (H1) + linha de contexto ("Escolha um módulo para continuar. Os cards exibidos variam de acordo com o seu perfil de acesso.").

**ModuleGrid:** `grid-template-columns: repeat(auto-fill, minmax(230px,1fr)); gap: 18px`. O `auto-fill` resolve de 1 a 12+ cards sem quebrar alinhamento — não criar breakpoints manuais por contagem de cards.

**ModuleCard** (anatomia):
- Superfície branca, borda 1px `--color-border`, radius 14px, padding 22px, sombra "resting".
- Ícone 24px dentro de bloco 46×46px (radius 11px, fundo `primary-100`), cor do ícone `primary-700` (#3B4822).
- Título 15px/700 em `text-primary`; descrição curta 12.5px em `text-secondary` (máx. 2 linhas).
- Toda a superfície do card é clicável.

**Estados:** hover → borda `primary-700`, sombra "elevated", translateY(-2px), transição 150ms; focus (teclado) → outline 2px `--color-focus`, offset 2px; indisponível (raro) → opacidade 0.5, sem hover — o padrão normal é simplesmente **omitir** o card sem permissão, não desabilitá-lo visualmente.

**Diferenças por perfil:** apenas o número/conjunto de cards muda (ex.: um Gestor pode ver só "Solicitar Vaga" e "Acompanhar Recrutamento"). O layout, tamanho de card e grid são os mesmos independentemente de quantos módulos aparecem — a grade elástica garante que 2 cards fiquem tão equilibrados quanto 9.

---

## 6. Design Tokens

### Cores

| Token | Valor | Uso |
|---|---|---|
| `primary-50` | `#F2F4EC` | fundos muito sutis |
| `primary-100` | `#E4E9D6` | bloco de ícone do card, avatar, badge "novo" |
| `primary-300` | `#A9B885` | bordas ativas suaves |
| `primary-400` | `#819158` | série secundária em gráfico |
| `primary-600` | `#566B41` | hover intermediário |
| **`primary-700`** | **`#3B4822`** | **cor institucional principal** — botões, links, navegação ativa, foco, ícones de destaque |
| `primary-800` | `#2E3919` | hover de elementos primários |
| `primary-900` | `#232B13` | pressed |
| `background` | `#F7F6F1` | fundo de página |
| `surface` | `#FFFFFF` | cards, header, painéis |
| `surface-secondary` | `#EFECE1` | colunas Kanban, zebra, chips |
| `border` | `#E2DFD0` | bordas de cards, inputs, divisores |
| `text-primary` | `#2B2E22` | títulos e texto principal |
| `text-secondary` | `#5B5F4E` | corpo secundário |
| `text-muted` | `#8B8F7A` | legendas, metadados, breadcrumb |
| `success` | `#2F7D5C` | aprovado, concluído, contratado |
| `warning` | `#8A6A3F` | pendente, aguardando, revisar |
| `danger` | `#B23B3B` | erro, exclusão, campo inválido |
| `info` | `#46618C` | agendado, informativo (único tom fora da paleta institucional, por necessidade semântica) |
| `focus` | `#3B4822` | anel de foco |
| `disabled` (texto/borda) | `text-muted` a 50% de opacidade | estado desabilitado |

Cores institucionais de apoio (uso pontual, não como fundo de página): `#F1E7DF` (bege/off-white — hero, login, empty states), `#DAD9BD` (neutro esverdeado — chips, divisores), `#4D3726` e `#785B42` (marrons — referências florestais pontuais, séries de dataviz, nunca em massas grandes).

Regra: cor semântica **sempre** acompanhada de texto ou ícone — nunca só cor comunicando estado.

### Tipografia

- **Família de sistema** (padrão para 95% da interface): `"Segoe UI", Helvetica, Arial, sans-serif`. Prioriza legibilidade em alta densidade, desempenho, e consistência entre navegadores corporativos.
- **NewBlack** (fonte institucional de marca): reservada a wordmark/logotipo, "Central do Portal RH" (H1 de identidade), telas de boas-vindas/login. **Nunca** em tabelas, formulários ou grandes volumes de dados — a fonte tem personalidade forte demais para isso. Carregar via `@font-face` só onde usada; se o arquivo `.woff2` oficial ainda não estiver disponível, usar a família de sistema como fallback (decisão já registrada, não bloqueante).

| Estilo | Tamanho / peso / line-height | Fonte |
|---|---|---|
| H1 institucional | 28px / 800 / 1.2 | NewBlack |
| H2 operacional | 19px / 800 / 1.3 | Sistema |
| H3 | 15px / 700 / 1.4 | Sistema |
| Corpo | 14px / 400 / 1.55 | Sistema |
| Secundário / label / badge | 11–12.5px / 400–700 / 1–1.4 | Sistema |
| Número/KPI | 26–28px / 800 / 1.1 | Sistema |
| Botão | 13px / 600 / 1 | Sistema |

### Espaçamento

Escala base 4px: **4 · 8 · 12 · 16 · 20 · 24 · 32 · 40 · 56**. Gutter de página: 40px desktop, 24px tablet, 16px mobile.

### Bordas e radius

Borda padrão: `1px solid #E2DFD0`. Radius: `sm` 6px (badges/chips), `md` 10px (inputs, botões, colunas Kanban), `lg` 14px (cards de módulo, painéis).

### Sombras (só 2 níveis)

- `resting`: `0 1px 2px rgba(43,46,34,.05)` — cards em repouso.
- `elevated`: `0 6px 18px rgba(59,72,34,.12)` — hover, dropdown, modal.

Sem glassmorphism, sem gradiente decorativo.

### Ícones

Família única de traço geométrico simples/preenchimento sólido, sem misturar outline e solid no mesmo contexto. Tamanhos: 24px (ModuleCard), 20px (ações/IconButton), 16px (inline em texto/badge). Recomenda-se biblioteca única compatível com Tailwind (Heroicons ou Lucide — SVG puro, sem dependência de framework JS). Sem emojis em nenhum ponto da interface.

---

## 7. Componentes

### Botões
- **Primário**: fundo `primary-700`, texto branco, altura 40px, radius `md`, padding 10–16px. Hover `primary-800`, active `primary-900`.
- **Secundário**: borda `border`, texto `primary-700`, fundo transparente.
- **Destrutivo**: mesma anatomia do primário, fundo `danger`.
- **Ghost/textual**: sem borda/fundo, texto `primary-700`, usado em ações secundárias (ex.: "Cancelar").
- **Ícone (IconButton)**: 36×36px, radius `md`, ícone 20px centralizado.
- **Loading**: substitui o label por spinner discreto, mantém a largura do botão (evita "pulo" de layout).
- **Disabled**: opacidade 0.5, cursor `not-allowed`, sem hover.

Quando usar cada: primário = uma ação principal por tela; secundário = ações alternativas; destrutivo = exclusão/cancelamento crítico; ghost = ações de baixa hierarquia (voltar, limpar).

### Campos (Input, Select, Textarea, Checkbox, Radio)
- Altura 40px (textarea min. 88px), borda `border`, radius `md`, padding 10–12px.
- Label 12.5px/600 acima do campo. Obrigatório: asterisco em `danger`.
- Help text 12px `text-muted` abaixo do campo.
- Erro: borda `danger` + mensagem substitui o help text.
- Foco: borda `focus` + anel externo 2px.
- Checkbox 18×18px radius 4px; Radio 18×18px circular; marcado = `primary-700`; área de toque mínima 44×44px via padding do label (não do input isolado).
- Campo de pesquisa: mesmo padrão de Input, ícone de lupa 16px à esquerda.
- Campo de data: mesmo padrão de Input, ícone de calendário à direita, abre datepicker nativo/leve.
- Somente leitura: fundo `surface-secondary`, sem borda de foco.

### Cards
- **Acesso** (ModuleCard): ver §5.
- **Informativo/Conteúdo** (Card genérico): mesma anatomia do ModuleCard, sem ícone obrigatório, usado para agrupar conteúdo dentro de uma página.
- **KPI** (MetricCard): label 12.5px muted no topo, valor 26–28px/800 abaixo, variação opcional (seta + % em `success`/`danger`) ao lado do valor.
- **Ação**: card com CTA embutido (ex.: "Nova solicitação de vaga" dentro de uma lista vazia).

### Badges
- Padding 2–3px 7–8px, radius `sm`, 11px/600–700.
- Cor = token semântico (`success`/`warning`/`danger`/`info`/`primary`), sempre com texto curto — nunca só uma bolinha colorida.
- Uso: status, prioridade, situação, alerta — mesma cor = mesmo significado em todos os módulos (ver §12).

### Tabelas
- Cabeçalho: fundo `surface-secondary`, texto 12px/700, padding 10px 14px.
- Linhas: padding 12px 14px, borda inferior `border`; hover `surface-secondary`.
- Zebra: opcional, só em tabelas muito densas (>8 colunas), a 50% de `surface-secondary`.
- Ações à direita como IconButtons.
- Filtros via FilterBar acima da tabela (ver abaixo).
- Paginação abaixo, alinhada à direita: botões 32×32px, página atual em `primary-700`.
- Tabelas largas se beneficiam da ausência de sidebar — mais colunas visíveis antes do overflow. Overflow horizontal com sombra de borda indicando conteúdo oculto, nunca scroll horizontal da página inteira.
- Mobile: colunas secundárias colapsam em "detalhes" expansíveis por linha (não empilhar tudo verticalmente sem hierarquia).
- Empty state: bloco centralizado substituindo o corpo da tabela (ver Estados).

### Filtros (FilterBar)
- Linha de Inputs/Selects compactos (altura 36px) + botão "Aplicar" (primário pequeno) + link "Limpar" (ghost).
- Filtros ativos aparecem como chips removíveis abaixo da barra.
- Mobile: FilterBar colapsa em um botão "Filtros" que abre painel/modal com os mesmos campos.

### Modais / confirmações
- Overlay `primary-900` a 45% de opacidade.
- Painel `surface`, radius `lg`, sombra `elevated`, largura máx. 560px (ou 800px para formulários maiores).
- Fecha por clique no overlay, X no canto, ou Esc.
- Confirmação destrutiva usa botão `danger` como ação primária do modal, nunca `primary`.

### Alertas
- Fundo tonal claro do token semântico + faixa de 4px à esquerda + ícone + texto (nunca só a faixa colorida como diferenciador).
- Radius `md`, padding 14px 16px.
- Variantes: sucesso, erro, aviso, informação — sempre nos tokens de §6.

### Navegação
- Menu = Central (ModuleGrid), não menu suspenso.
- Grupos = agrupamento visual dentro da Central quando necessário (ver §5 — preferir grade única salvo fragmentação clara).
- Breadcrumb, Tabs: ver §4.
- Paginação: ver Tabelas.

### Estados
- **Carregando**: skeleton (blocos `surface-secondary` pulsando) no formato do conteúdo esperado — nunca um spinner isolado sem contexto em telas de dados.
- **Vazio**: ícone 40px muted, título 14px/700, descrição 12.5px muted, ação opcional (ex.: "Nenhuma vaga aberta" + botão "Nova solicitação").
- **Erro**: mesma anatomia do vazio, ícone de alerta, ação "Tentar novamente".
- **Sem permissão**: mesma anatomia, texto explicando a restrição, sem botão de ação.
- **Nenhum resultado** (filtro): mesma anatomia do vazio, com ação "Limpar filtros".

---

## 8. Padrão de página administrativa

Anatomia padrão, de cima para baixo:

1. Breadcrumb (contexto/navegação).
2. PageHeader: título (H1, 24–28px/800) + descrição curta opcional + ação principal alinhada à direita (desktop) / abaixo do título (mobile).
3. ModuleTabs (se a página pertence a um módulo com múltiplas funcionalidades).
4. FilterBar (se a página lista dados).
5. Indicadores/KPIs (se relevante ao contexto — MetricGrid).
6. Conteúdo principal (tabela, cards, formulário, dashboard, detalhe ou fluxo em etapas — ver quando usar cada abaixo).
7. Ações secundárias (exportar, imprimir) — alinhadas junto ao FilterBar ou ao final da página, nunca competindo com a ação principal.

**Quando usar cada formato de conteúdo:**
- **Tabela**: listas administrativas com muitos registros e necessidade de ordenar/filtrar (Colaboradores, Usuários, Cadastros).
- **Cards**: coleções pequenas ou quando cada item precisa de destaque visual (Central, vagas em aberto).
- **Formulário**: criação/edição de uma entidade.
- **Dashboard**: visão gerencial com KPIs e gráficos (Turnover, People Analytics).
- **Detalhe**: visualização de uma entidade específica com histórico/contexto (ver §10).
- **Fluxo em etapas**: processos sequenciais longos (ex.: abertura de solicitação de vaga com múltiplas seções).

---

## 9. Formulários

- Uma coluna para formulários curtos (≤6 campos); duas colunas (`grid-template-columns: 1fr 1fr`, gap 16–20px) para formulários longos — campos de largura total (observações, endereço) usam `grid-column: 1 / -1`.
- **Agrupar por seções** com um H3 de seção acima de cada grupo — nunca uma parede única de campos. Processos extensos usam múltiplas seções colapsáveis ou um fluxo em etapas (ver §8) em vez de uma página gigante.
- Label 12.5px/600 acima do campo; obrigatório com asterisco `danger`; help text 12px muted; erro substitui o help text.
- Campos somente leitura: fundo `surface-secondary`.
- Ações Salvar (primário) / Cancelar (ghost) fixas ao final, alinhadas à direita; em mobile, largura total, Salvar acima de Cancelar.
- **Todos os campos e regras de validação existentes devem ser preservados** — a nova UI reorganiza visualmente, nunca remove campo funcional por motivo estético.

---

## 10. Páginas de detalhe

Para entidades/processos (colaborador, usuário, PDI, vaga, solicitação, entrevista, candidato):

1. **Cabeçalho de identidade**: nome/identificador em destaque (H1) + badge de status ao lado + metadados curtos (cargo, data, responsável).
2. **Contexto**: breadcrumb + eventualmente um resumo de uma linha sobre onde essa entidade está no processo.
3. **Ações permitidas**: botões relevantes ao estado atual, alinhados à direita do cabeçalho.
4. **Conteúdo**: organizado em seções/abas quando há muita informação (dados gerais, documentos, histórico) — reaproveitar ModuleTabs internamente se necessário.
5. **Histórico**: lista cronológica compacta (timeline simples: data + evento + responsável), quando existente.

---

## 11. Dashboards

- **FilterBar de período** no topo (ex.: seletor de mês/trimestre/ano).
- **MetricGrid**: `grid-template-columns: repeat(auto-fit, minmax(200px,1fr)); gap:16px` — mesmo princípio elástico do ModuleGrid.
- **MetricCard**: label + valor grande + variação (seta + % em `success`/`danger`) quando aplicável.
- **DashboardChartContainer**: superfície `surface`, borda, radius `md`, padding 20px, título 14px/700 no topo, legenda 11px abaixo do gráfico.
- **Paleta de visualização de dados** (prioridade: verdes → neutros → marrons institucionais → semânticas só quando o significado exigir):

| Papel na série | Cor |
|---|---|
| Série principal | `#3B4822` (primary-700) |
| Série comparativa | `#819158` |
| Série secundária/neutra | `#DAD9BD` |
| Destaque/contraste | `#785B42` |
| Meta (linha de referência) | `#8B8F7A`, tracejada |
| Positivo | `#2F7D5C` (success) |
| Negativo | `#B23B3B` (danger) |

- Séries adjacentes devem se diferenciar também por padrão de traço/textura, não só por matiz (acessibilidade a daltonismo).
- **Distinguir explicitamente** "zero real" (valor 0 com dado coletado) de "sem base"/ausência de dados — usar um EmptyState textual no lugar do gráfico quando não houver base, nunca um gráfico zerado sem explicação.
- Evitar gráficos decorativos, gigantes, ou sem contexto (sempre com título, período e, quando aplicável, tamanho da amostra).
- Mobile: KPIs em coluna única ou 2 por linha; gráficos com scroll horizontal interno ao container (nunca a página).

---

## 12. Status e cores semânticas — convenção única

Mesmo significado = mesma cor em todos os módulos. Nomes funcionais existentes não são alterados — apenas o estilo visual do badge.

| Categoria de status | Token | Exemplos de nome funcional (preservar o texto exato do sistema) |
|---|---|---|
| Neutro/inicial | `text-muted` sobre `surface-secondary` | Rascunho, Novo |
| Em progresso | `info` | Em andamento, Agendado, Em análise |
| Pendente/atenção | `warning` | Pendente, Aguardando, Revisar, Atenção |
| Positivo/concluído | `success` | Concluído, Aprovado, Contratado, Respondido |
| Negativo/encerrado | `danger` | Cancelado, Reprovado, Expirado, Falha |

Badge = cor do token + texto do status. Nunca representar status só por uma cor sem rótulo.

---

## 13. Responsividade

**Breakpoints:** desktop grande ≥1440px · notebook 1280–1439px · tablet 768–1279px · mobile <768px.

- **Desktop**: conteúdo sem largura máxima fixa, usa a viewport menos o gutter (40px); ModuleGrid/MetricGrid em várias colunas via `auto-fill`/`auto-fit`; tabelas mostram todas as colunas relevantes; FilterBar em linha única.
- **Tablet**: gutter 24px; grid de cards reduz colunas naturalmente (nenhuma regra manual por breakpoint — o CSS elástico resolve); formulários passam a uma coluna a partir de ~900px; ModuleTabs com scroll horizontal se necessário.
- **Mobile**: gutter 16px; header oculta nome do usuário (mantém avatar); ModuleGrid 1–2 colunas; ModuleTabs e FilterBar em scroll horizontal ou colapsados em painel; tabelas colapsam colunas secundárias em detalhe expansível por linha (nunca scroll horizontal da página como solução padrão); formulários em uma coluna; ações de página em largura total.
- Quando uma tabela realmently não couber nem com colunas colapsadas, a estratégia é **scroll horizontal contido no componente Table** (com sombra indicando conteúdo oculto) — nunca scroll horizontal do documento inteiro.

---

## 14. Acessibilidade

- Contraste mínimo 4.5:1 (3:1 para texto ≥19px/700) — validado para `text-primary`/`primary-700` sobre `surface`/`background`.
- Foco sempre visível via outline (2px `focus`, offset 2px) — nunca suprimido, nunca só mudança de cor.
- Navegação completa por teclado (Tab/Shift+Tab/Enter/Esc) em cards, tabs, modais e menus.
- Alvos de toque/clique mínimos 44×44px.
- Labels associadas a todo input (`<label for>` ou `aria-label` em IconButtons).
- Estado nunca comunicado só por cor — sempre + texto ou ícone.
- `aria-current` na tab ativa e no item ativo do breadcrumb.

---

## 15. Aplicação aos módulos existentes

O novo sistema visual (tokens, componentes, padrão de página) se aplica **igualmente** a todos os módulos abaixo, sem alterar sua lógica:

- Recrutamento e Seleção (Dashboard, Solicitações de Vaga, Candidaturas, Pipeline Kanban, Webhooks) — usa padrão de Dashboard (§11) + Kanban (§7 Tabelas/Cards conforme aba) + ModuleTabs.
- Colaboradores, Usuários — padrão de Tabela (§7) + Página de Detalhe (§10) para cada registro.
- People Analytics, Dashboard de Turnover, Dashboard da Entrevista de Desligamento — padrão de Dashboard (§11).
- Pesquisa de Integração, Pesquisa de Reação, Entrevista de Desligamento — padrão de Formulário (§9) para captura + padrão de Tabela para listagem de respostas + Dashboard para os respectivos relatórios.
- PDI — padrão de Página de Detalhe (§10) com seções/histórico, mais Formulário (§9) para edição.
- Cadastros administrativos (Empresas, Setores, Cargos, Benefícios, Avaliações) — padrão de Tabela + Formulário simples (1 coluna, poucos campos).

Nenhuma funcionalidade nova é introduzida nesta etapa; a lista acima é apenas o mapeamento de qual padrão visual usar em cada tela já existente.

---

## 16. Estratégia de migração (ordem obrigatória)

1. **Fundação/tokens** — cores, tipografia, espaçamento no Tailwind, sem tocar telas existentes.
2. **Navegação/AppShell** — Header novo + remoção lógica da sidebar antiga por trás de flag; sidebar atual continua ativa por padrão até a etapa 9.
3. **Central do Portal** — nova rota inicial, consumindo permissões reais (somente leitura).
4. **Componentes base** — Button, Input, Badge, Card, Alert, Modal na nova linguagem visual, disponíveis para uso opcional nas próximas etapas.
5. **Formulários** — aplicar padrão de §9 aos formulários mais usados.
6. **Tabelas** — aplicar padrão de §7/§13 às listagens administrativas.
7. **Páginas de detalhe** — aplicar §10 às entidades principais.
8. **Dashboards** — aplicar §11 aos dashboards reais (Turnover, People Analytics, Entrevista de Desligamento).
9. **Módulos específicos** — migrar módulo a módulo (sugestão de piloto: Recrutamento e Seleção, já com wireframe de referência), validando com usuários reais antes de seguir; remover a sidebar antiga apenas depois de todos os módulos migrados.
10. **Polimento responsivo** — revisão final de tablet/mobile em todas as telas migradas.

Cada fase deve ser implementada e validada isoladamente antes de avançar para a próxima. Nenhuma fase altera regra de negócio, banco, rotas ou permissões.

---

## 17. Mapeamento Design → Implementação (PHP + Tailwind)

- **Centralizar em partials PHP reutilizáveis**: Header/AppShell, Breadcrumb, PageHeader, ModuleTabs, ModuleCard/ModuleGrid, Button (variantes via parâmetro), Badge (variantes via parâmetro), Alert, EmptyState/LoadingState/ErrorState, FilterBar, Pagination, MetricCard, DashboardChartContainer wrapper, KanbanColumn/KanbanCard.
- **Não duplicar**: nenhuma dessas anatomias deve ser reescrita inline em views individuais — sempre via partial/include com parâmetros (título, ícone, variante, estado).
- **Permanece específico da página**: o conteúdo de negócio dentro de cada partial (dados da query, colunas específicas da tabela, campos específicos do formulário, regras de exibição condicional por permissão).
- **Tailwind**: registrar os tokens de §6 em `tailwind.config` (`theme.extend.colors`, `spacing`, `borderRadius`, `boxShadow`, `fontFamily`) em vez de usar valores soltos (`bg-[#3B4822]`) espalhados pelas views. Padrões repetitivos de classes (ex.: o conjunto de classes de um ModuleCard) devem virar uma classe utilitária componível via `@apply` num arquivo de componentes Tailwind, **não** um CSS paralelo desconectado do build. Não reestruturar o pipeline de build atual — só estender a configuração existente.

---

## 18. Referência às telas produzidas neste trabalho

Todas ativas e válidas como referência visual — nenhuma foi descartada.

### Tela 1 — Central do Portal RH (perfil RH)
**Objetivo:** validar a arquitetura de Central com grade completa de módulos.
**Composição:** Header global + H1 "Central do Portal RH" + descrição de contexto + ModuleGrid com 9 ModuleCards (Indicadores de RH, Recrutamento e Seleção, Colaboradores, Integração, Benefícios, Avaliações, Mensagens, Cadastros, Usuários e Acessos).
**Componentes usados:** Header, ModuleGrid, ModuleCard, ModuleIcon.
**Decisão importante:** grade única sem subseções fixas, para não fragmentar a leitura.

### Tela 2 — Recrutamento e Seleção (Dashboard do módulo)
**Objetivo:** demonstrar a navegação secundária horizontal e o ganho de largura sem sidebar.
**Composição:** Header + Breadcrumb ("Portal RH › Recrutamento e Seleção") + PageHeader (título + botão "+ Nova solicitação de vaga") + ModuleTabs (Dashboard, Solicitações de Vaga, Candidaturas, Pipeline Kanban, Webhooks) + MetricGrid (4 KPIs) + gráfico de barras "Vagas abertas por área" + lista "Candidaturas recentes".
**Componentes usados:** Breadcrumb, PageHeader, ModuleTabs, MetricCard, DashboardChartContainer.

### Tela 3 — Pipeline Kanban
**Objetivo:** mostrar a tela que mais se beneficia da largura total.
**Composição:** Header + Breadcrumb (3 níveis) + PageHeader + ModuleTabs (aba "Pipeline Kanban" ativa) + 5 KanbanColumns (Triagem, Entrevista, Teste Técnico, Proposta, Contratado) com KanbanCards e badges de status.
**Componentes usados:** Breadcrumb, PageHeader, ModuleTabs, KanbanColumn, KanbanCard, Badge.
**Decisão importante:** colunas em `flex:1` preenchem a largura antes de recorrer a scroll horizontal.

### Tela 4 — Visão do Gestor (permissões reduzidas)
**Objetivo:** validar que a Central permanece elegante com poucos módulos.
**Composição:** igual à Tela 1, mas ModuleGrid com apenas 2 ModuleCards (Solicitar Vaga, Acompanhar Recrutamento), grade limitada a `max-width` menor.
**Decisão importante:** a arquitetura não depende de um número mínimo de cards para parecer completa.

### Documento de identidade — Design System 2.0.0 (revisão de marca)
Aplicou a identidade oficial Madeplant (verde #3B4822, escala derivada, neutros quentes, tipografia NewBlack para identidade + sistema para dados, grafismos institucionais de uso restrito, paleta de dataviz) sobre as quatro telas acima, substituindo uma paleta azul provisória usada em uma iteração anterior e descartada. **A paleta azul não deve ser usada em nenhuma implementação** — a referência de cor válida é exclusivamente a de §6 deste documento.

---

## 19. Matriz obrigatório × opcional

| Regra visual | Obrigatória | Recomendável | Opcional | Não utilizar |
|---|---|---|---|---|
| Remover sidebar fixa e adotar Central + Módulo + Funcionalidade | ✅ | | | |
| Cor primária #3B4822 como referência institucional | ✅ | | | |
| Breadcrumb em toda tela interna | ✅ | | | |
| ModuleTabs em vez de sidebar interna do módulo | ✅ | | | |
| Preservar todos os campos/regras funcionais existentes | ✅ | | | |
| Contraste mínimo 4.5:1 e foco visível | ✅ | | | |
| ModuleGrid/MetricGrid com `auto-fill`/`auto-fit` | ✅ | | | |
| Skeleton no lugar de spinner isolado em telas de dados | | ✅ | | |
| Agrupar módulos da Central em subseções nomeadas | | | ✅ (só se a grade única ficar confusa) | |
| Grafismos institucionais em hero/login/empty state, opacidade baixa | | ✅ | | |
| NewBlack aplicada a tabelas/formulários/dados densos | | | | ❌ |
| Paleta azul da iteração anterior | | | | ❌ |
| Cor própria por módulo | | | | ❌ |
| Scroll horizontal da página inteira como solução de responsividade | | | | ❌ |
| Gradientes decorativos, glassmorphism, neon | | | | ❌ |
| Framework frontend novo (React/Vue/Angular) ou SPA | | | | ❌ |
| Alterar regra de negócio/permissão "para caber no design" | | | | ❌ (pare e reporte) |

---

## 20. Checklist de aceite visual (por tela migrada)

- [ ] Identidade: cor primária correta (#3B4822), sem paleta azul residual.
- [ ] Hierarquia: título, contexto e ação principal claros e na posição definida em §8.
- [ ] Espaçamento: gutter e escala de §6 respeitados, sem valores soltos.
- [ ] Tipografia: fonte de sistema em dados, NewBlack apenas onde permitido.
- [ ] Responsividade: validada em desktop, tablet e mobile — sem scroll horizontal da página.
- [ ] Estados: hover, focus, loading, vazio e erro implementados conforme §7.
- [ ] Formulário: todos os campos e validações originais preservados.
- [ ] Permissões preservadas: nenhuma regra de autorização foi criada ou alterada.
- [ ] Conteúdo preservado: nenhum dado/funcionalidade removido por motivo estético.
- [ ] Mobile: navegação, header e ações testados e utilizáveis com o dedo (alvo ≥44px).
- [ ] Acessibilidade: contraste, labels, navegação por teclado conferidos.
- [ ] Sem regressão visual: comparação com a tela antiga não mostra perda de informação ou função.

---

## 21. Se algo não estiver claro

O implementador deve **parar e reportar** — não decidir sozinho — quando: uma tela parecer exigir mudança funcional para caber no design; um módulo não estiver mapeado em §15; ou uma cor/necessidade visual não estiver coberta por §6. Não existem decisões pendentes bloqueantes conhecidas neste momento além da fonte NewBlack (arquivo `.woff2` oficial ainda não entregue pela marca — usar fallback de sistema até receber).

**Design System pronto para handoff ao Claudião.**
