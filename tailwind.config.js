/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './app/views/**/*.php',
  ],
  safelist: [
    'ct-btn',
    'ct-btn-primary',
    'ct-btn-success',
    'ct-btn-warning',
    'ct-btn-muted',
    'ct-badge',
    'ct-badge-active',
    'ct-badge-inactive',
  ],
  theme: {
    extend: {
      screens: {
        xs: '480px',
        sm: '640px',
        md: '768px',
        lg: '1024px',
        xl: '1280px'
      },
      colors: {
        ctdark: '#0d1321',
        ctgreen: '#1d2d44',
        ctlight: '#3e5c76',
        ctpblue: '#0d1321',

        // ---- Design System Portal RH (docs/claude/DESIGN-SYSTEM-PORTAL-RH.md, §6) — Fase 1: fundação. ----
        // Tokens NOVOS, com nomes próprios: não substituem `ct*` nem as cores nativas do Tailwind, então nenhuma tela
        // atual muda. Só passam a existir no CSS compilado as classes efetivamente usadas nas views (ex.: bg-primary-700).
        primary: {
          50: '#F2F4EC',
          100: '#E4E9D6',
          300: '#A9B885',
          400: '#819158',
          600: '#566B41',
          700: '#3B4822', // cor institucional principal (Pantone 5747 C)
          800: '#2E3919',
          900: '#232B13',
        },
        background: '#F7F6F1',
        surface: '#FFFFFF',
        'surface-secondary': '#EFECE1',
        border: '#E2DFD0', // border-border (a cor padrão do utilitário `border` NÃO é alterada)
        'text-primary': '#2B2E22',
        'text-secondary': '#5B5F4E',
        'text-muted': '#8B8F7A',
        success: '#2F7D5C',
        warning: '#8A6A3F',
        danger: '#B23B3B',
        info: '#46618C',
        focus: '#3B4822',
        // Apoio institucional (uso pontual: hero/login/empty state, chips, séries de dataviz — nunca massas grandes).
        'support-beige': '#F1E7DF',
        'support-sage': '#DAD9BD',
        'support-brown-dark': '#4D3726',
        'support-brown': '#785B42',
      },
      fontFamily: {
        sans: ['Montserrat','system-ui','-apple-system','sans-serif'],
        // Família de sistema do Design System (95% da interface). NÃO substitui `sans` (Montserrat) das telas atuais.
        ds: ['"Segoe UI"', 'Helvetica', 'Arial', 'sans-serif'],
        // Fonte institucional NewBlack (wordmark, H1 "Central do Portal RH", boas-vindas/login). O .woff2 oficial ainda não
        // foi entregue e NÃO há @font-face: enquanto isso, o navegador cai no fallback de sistema.
        brand: ['NewBlack', '"Segoe UI"', 'Helvetica', 'Arial', 'sans-serif'],
      },
      // Escala tipográfica (§6): tamanho / line-height / peso.
      fontSize: {
        'ds-h1': ['28px', { lineHeight: '1.2', fontWeight: '800' }],
        'ds-h2': ['19px', { lineHeight: '1.3', fontWeight: '800' }],
        'ds-h3': ['15px', { lineHeight: '1.4', fontWeight: '700' }],
        'ds-body': ['14px', { lineHeight: '1.55', fontWeight: '400' }],
        'ds-label': ['12.5px', { lineHeight: '1.4', fontWeight: '600' }],
        'ds-caption': ['12px', { lineHeight: '1.4', fontWeight: '400' }],
        'ds-badge': ['11px', { lineHeight: '1', fontWeight: '700' }],
        'ds-kpi': ['28px', { lineHeight: '1.1', fontWeight: '800' }],
        'ds-kpi-sm': ['26px', { lineHeight: '1.1', fontWeight: '800' }],
        'ds-button': ['13px', { lineHeight: '1', fontWeight: '600' }],
      },
      // Radius do DS com prefixo `ds-`: `rounded-sm/md/lg` nativos (usados em centenas de telas) permanecem intactos.
      borderRadius: {
        'ds-sm': '6px',
        'ds-md': '10px',
        'ds-lg': '14px',
      },
      boxShadow: {
        resting: '0 1px 2px rgba(43,46,34,.05)',
        elevated: '0 6px 18px rgba(59,72,34,.12)',
      },
      // Escala de espaçamento 4·8·12·16·20·24·32·40·56 já existe nativamente (1, 2, 3, 4, 5, 6, 8, 10, 14): não é redefinida.
      // Só o gutter de página, que varia por breakpoint via variável CSS (assets/tailwind-input.css).
      spacing: {
        gutter: 'var(--gutter-page)',
      }
    },
  },
  plugins: [],
}
