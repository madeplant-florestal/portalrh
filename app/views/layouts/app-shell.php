<?php

/**
 * AppShell V2 (Design System Portal RH, Fase 2) — layout OPT-IN.
 *
 * Coexiste com `layouts/admin` (sidebar), que continua sendo o shell de todas as telas atuais. Uma página migrada escolhe
 * este layout explicitamente: `$this->view->render('...', $dados, 'layouts/app-shell')`. Estrutura: Header V2 (identidade +
 * usuário; sem menu de módulos) → conteúdo com os gutters responsivos da Fase 1 (`px-gutter`), sem largura máxima.
 * Breadcrumb / PageHeader / ModuleTabs são chamados pela própria view (helpers em `partials/ui-shell.php`).
 *
 * Variáveis opcionais do `$params`: `tituloPagina` (string, <title>). Não decide autorização: quem chega aqui já passou
 * pelos gates do controller. Mensagens de sucesso/erro continuam sendo renderizadas pela própria view, como hoje.
 * Carrega os mesmos scripts/metas do layout atual (admin.js é seguro sem sidebar) para que views migradas mantenham
 * confirmações, máscaras e demais comportamentos existentes.
 */
require_once APP_PATH . '/views/partials/ui-shell.php';

$base = $base ?? (Config::app()['base_url'] ?? '');
$tituloPagina = trim((string)($tituloPagina ?? ''));
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="app-base" content="<?= Security::e($base) ?>">
  <meta name="csrf-token" content="<?= Security::e(Security::csrfToken()) ?>">
  <title><?= Security::e($tituloPagina !== '' ? $tituloPagina . ' — Portal RH' : 'Portal RH') ?></title>
  <link rel="stylesheet" href="<?= $base ?>/assets/tailwind.css?v=<?= urlencode(Config::assetVersion('assets/tailwind.css')) ?>">
  <script src="<?= $base ?>/assets/phone-utils.js?v=<?= urlencode(Config::assetVersion('assets/phone-utils.js')) ?>" defer></script>
  <script src="<?= $base ?>/assets/admin.js?v=<?= urlencode(Config::assetVersion('assets/admin.js')) ?>" defer></script>
</head>
<body class="min-h-screen bg-background font-ds text-ds-body text-text-primary" data-app-shell-v2="1">
  <a href="#conteudo" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-2 focus:z-50 focus:rounded-ds-md focus:bg-surface focus:px-3 focus:py-2 focus:text-primary-700 focus:shadow-elevated">Ir para o conteúdo</a>
  <?= ui_header_v2($base . '/admin', $base . '/assets/logo-escura.png', $base . '/admin/logout', (string)($_SESSION['user_name'] ?? '')) ?>
  <main id="conteudo" tabindex="-1" class="px-gutter py-6 focus:outline-none">
    <?= $content ?>
  </main>
</body>
</html>
