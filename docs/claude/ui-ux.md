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
