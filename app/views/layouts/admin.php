<?php
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="app-base" content="<?= Security::e($base ?? '') ?>">
  <meta name="csrf-token" content="<?= Security::e(Security::csrfToken()) ?>">
  <title>RH Madeplant - Painel</title>
  <link rel="stylesheet" href="<?= $base ?>/assets/tailwind.css?v=<?= urlencode(Config::app()['version'] ?? '') ?>">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style> body { font-family: 'Montserrat', system-ui, -apple-system, sans-serif; } </style>
  <script src="<?= $base ?>/assets/phone-utils.js?v=<?= urlencode(Config::app()['version'] ?? '') ?>" defer></script>
  <script src="<?= $base ?>/assets/admin.js?v=<?= urlencode(Config::app()['version'] ?? '') ?>" defer></script>
</head>
<body class="app-shell min-h-screen bg-gray-50">
  <header class="app-header">
    <button type="button" class="app-nav-toggle touch-target menu-toggle" aria-controls="admin-drawer" aria-expanded="false" data-admin-menu-toggle="1">
      <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
    </button>
    <div class="app-header-brand">
      <img src="<?= $base ?>/assets/logo.png" alt="RH Madeplant" class="h-6 w-auto object-contain">
      <span class="app-header-brand-label text-sm font-semibold">Painel</span>
    </div>
  </header>
  <div class="app">
    <aside id="admin-drawer" class="sidebar" data-admin-sidebar="1" aria-hidden="true">
      <?php include APP_PATH . '/views/layouts/sidebar.php'; ?>
    </aside>
    <div id="admin-overlay" class="app-overlay" data-admin-overlay="1"></div>
    <main class="content bg-gray-100">
      <?= $content ?>
    </main>
  </div>

  <footer class="border-t bg-white">
    <div class="px-6 py-3 text-gray-500 text-sm text-center">
      © <?= date("Y"); ?> 
      <strong>RH Madeplant</strong> - Todos os direitos reservados.
    </div>
  </footer>
</body>
</html>
