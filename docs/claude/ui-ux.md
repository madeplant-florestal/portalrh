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
