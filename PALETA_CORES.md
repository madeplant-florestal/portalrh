# Paleta de cores (UI)

Esta aplicação padroniza os elementos de interface usando exclusivamente os três tons abaixo como cores de marca:

- Escuro: `#0d1321`
- Médio: `#1d2d44`
- Claro: `#3e5c76`

## Tokens e classes

Os tokens/camadas foram unificados em `assets/tailwind-input.css` e `tailwind.config.js`:

- `ctdark` → `#0d1321`
- `ctgreen` → `#1d2d44`
- `ctlight` → `#3e5c76`
- `ctpblue` → `#0d1321`

Classes de uso:

- Texto: `text-ctdark`, `text-ctgreen`, `text-ctlight`, `text-ctpblue`
- Fundo: `bg-ctdark`, `bg-ctgreen`, `bg-ctlight`, `bg-ctpblue`
- Borda: `border-ctdark`, `border-ctgreen`, `border-ctlight`

Componentes utilitários mantidos por safelist no Tailwind:

- Botões: `ct-btn`, `ct-btn-primary`, `ct-btn-success`, `ct-btn-warning`, `ct-btn-muted`
- Badges: `ct-badge`, `ct-badge-active`, `ct-badge-inactive`

## Build do CSS

O build gera `assets/tailwind.css` (para servir em `/assets/tailwind.css`) e copia para `public/assets/tailwind.css` (para testes/alternativas de deploy).

```bash
npm run build:css
```

Para desenvolvimento:

```bash
npm run dev:css
```

## Ajustes em dados

Foi criada uma migração idempotente para substituir cores verdes previamente salvas em `pipeline_stages.cor` por `#1d2d44`:

- `database/migrations/2026-04-02-update-green-to-blue.sql`
