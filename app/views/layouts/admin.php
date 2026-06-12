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
  <style>
    body { font-family: 'Montserrat', system-ui, -apple-system, sans-serif; }
    .form-choice-group {
      display: flex;
      flex-direction: column;
      gap: 0.75rem;
    }
    .form-choice-card {
      display: flex;
      min-height: 3rem;
      align-items: center;
      gap: 0.75rem;
      border: 1px solid #d1d5db;
      border-radius: 0.75rem;
      background: #fff;
      padding: 0.875rem 1rem;
      line-height: 1.35;
      cursor: pointer;
      transition: border-color .18s ease, background-color .18s ease, box-shadow .18s ease;
    }
    .form-choice-card:hover {
      border-color: #94a3b8;
      background: #f8fafc;
    }
    .form-choice-card:focus-within {
      border-color: #3e5c76;
      box-shadow: 0 0 0 3px rgba(62, 92, 118, 0.14);
    }
    .form-choice-card input {
      margin: 0;
      flex: 0 0 auto;
    }
    .form-choice-card span {
      min-width: 0;
      overflow-wrap: anywhere;
    }
    .form-choice-card.is-disabled {
      opacity: 0.7;
      cursor: not-allowed;
    }
    .form-inline-grid {
      display: grid;
      gap: 1rem;
    }
    @media (min-width: 769px) {
      .form-choice-group.is-inline {
        flex-direction: row;
        flex-wrap: wrap;
      }
      .form-choice-group.is-inline .form-choice-card {
        flex: 1 1 11rem;
      }
      .form-choice-group.is-inline.is-compact .form-choice-card {
        flex: 1 1 0;
        min-width: 0;
      }
      .form-inline-grid.form-inline-grid-3 {
        grid-template-columns: repeat(3, minmax(0, 1fr));
      }
    }
  </style>
  <script src="<?= $base ?>/assets/phone-utils.js?v=<?= urlencode(Config::app()['version'] ?? '') ?>" defer></script>
  <script src="<?= $base ?>/assets/admin.js?v=<?= urlencode(Config::app()['version'] ?? '') ?>" defer></script>
</head>
<body class="app-shell min-h-screen bg-gray-50" data-admin-shell="1">
  <header class="app-header" data-admin-header="1">
    <button type="button" class="app-nav-toggle touch-target menu-toggle" aria-controls="admin-drawer" aria-expanded="false" data-admin-menu-toggle="1">
      <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
    </button>
    <div class="app-header-brand">
      <img src="<?= $base ?>/assets/logo.png" alt="RH Madeplant" class="h-6 w-auto object-contain">
      <span class="app-header-brand-label text-sm font-semibold">Painel</span>
    </div>
  </header>
  <div class="app" data-admin-app="1">
    <aside id="admin-drawer" class="sidebar" data-admin-sidebar="1" aria-hidden="true">
      <?php include APP_PATH . '/views/layouts/sidebar.php'; ?>
    </aside>
    <div id="admin-overlay" class="app-overlay" data-admin-overlay="1"></div>
    <main class="content bg-gray-100" data-admin-content="1">
      <?= $content ?>
    </main>
  </div>

  <footer class="app-footer border-t bg-white" data-admin-footer="1">
    <div class="px-6 py-3 text-gray-500 text-sm text-center">
      © <?= date("Y"); ?> 
      <strong>RH Madeplant</strong> - Todos os direitos reservados.
    </div>
  </footer>
</body>
</html>
