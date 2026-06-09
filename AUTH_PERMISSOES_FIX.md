# Correção de acesso negado pós-login

## Ajustes aplicados

- Normalização de role no login (`trim` + `lowercase`).
- Fallback de role para `admin` quando o e-mail do usuário coincide com o supervisor configurado e a role vem vazia.
- Promoção de sessão para supervisor quando role final é `admin`.
- Fallback seguro de role para `viewer` quando valor vier vazio.
- Validação de permissão com normalização de roles permitidas.
- Usuário não autenticado em verificação de role agora é redirecionado para `/login`.

## Arquivo alterado

- `app/core/Auth.php`

## Testes de regressão executados

- Login real com `fabio.ozuna@madeplant.com.br` + `23082524`:
  - Resultado: redirecionamento para `/dashboard`.
- Acesso a `/dashboard` com sessão autenticada:
  - Resultado: carregamento do dashboard sem “Acesso negado”.
- Verificação de autorização:
  - Role `admin` acessa rota administrativa (`Auth::requireRole(['admin'])`).
  - Role `viewer` continua bloqueada em rota de admin (retorna “Acesso negado”).
