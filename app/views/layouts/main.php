<?php
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="app-base" content="<?= Security::e($base ?? '') ?>">
  <meta name="csrf-token" content="<?= Security::e(Security::csrfToken()) ?>">
  <?php if (!empty($noIndex)): ?>
  <meta name="robots" content="noindex,nofollow">
  <?php endif; ?>
  <title>RH Madeplant</title>
  <link rel="stylesheet" href="<?= $base ?>/assets/tailwind.css?v=<?= urlencode(Config::assetVersion('assets/tailwind.css')) ?>">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'Montserrat', system-ui, -apple-system, sans-serif; }
    .share-menu-panel {
      width: min(22rem, calc(100vw - 1rem));
      max-width: calc(100vw - 1rem);
      right: 0;
      left: auto;
    }
    .share-menu-actions {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 0.5rem;
    }
    .share-menu-action {
      min-height: 2.625rem;
      white-space: normal;
      line-height: 1.2;
      overflow-wrap: anywhere;
      word-break: normal;
    }
    @media (max-width: 640px) {
      .share-menu-panel {
        right: -0.25rem;
      }
    }
  </style>
  <script src="<?= $base ?>/assets/phone-utils.js?v=<?= urlencode(Config::assetVersion('assets/phone-utils.js')) ?>" defer></script>
  <script src="<?= $base ?>/assets/share-utils.js?v=<?= urlencode(Config::assetVersion('assets/share-utils.js')) ?>" defer></script>
  <script src="<?= $base ?>/assets/public.js?v=<?= urlencode(Config::assetVersion('assets/public.js')) ?>" defer></script>
</head>
<body class="min-h-screen flex flex-col bg-background">
  <?php 
  if (!isset($isLoginPage)) {
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $isLoginPage = (strpos($uri, '/admin/login') !== false);
  }
  $isLoginPage = (bool)$isLoginPage;
  ?>
  
  <?php if (!$isLoginPage): ?>
  <header class="bg-primary-800 text-white">
    <div class="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between">
      <a href="<?= $base ?>/vagas" class="flex items-center gap-3">
        <button type="button" class="app-nav-toggle touch-target sm:hidden" aria-controls="public-menu" aria-expanded="false" data-public-menu-toggle="1">
          <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <img src="<?= $base ?>/assets/logo.png" alt="Madeplant Florestal" class="h-7 w-auto object-contain">
      </a>
      <nav class="text-sm items-center gap-5 hidden sm:flex">
        <a href="<?= $base ?>/vagas" class="font-medium hover:text-primary-100">Trabalhe Conosco</a>
        <div class="relative hidden sm:block">
          <button
            type="button"
            id="share-menu-trigger"
            data-share-trigger="1"
            aria-haspopup="dialog"
            aria-expanded="false"
            aria-controls="share-menu-panel"
            class="inline-flex items-center gap-2 hover:text-primary-100 focus:outline-none focus:ring-2 focus:ring-white rounded px-2 py-1"
          >
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
              <path d="M18 16a3 3 0 0 0-2.394 1.193l-6.652-3.326a3.02 3.02 0 0 0 0-1.734l6.652-3.326A3 3 0 1 0 15 7a2.98 2.98 0 0 0 .046.517L8.394 10.84a3 3 0 1 0 0 2.32l6.652 3.323A2.98 2.98 0 0 0 15 17a3 3 0 1 0 3-1z"/>
            </svg>
            Compartilhar
          </button>
          <div
            id="share-menu-panel"
            data-share-panel="1"
            role="dialog"
            aria-modal="false"
            aria-labelledby="share-menu-title"
            class="share-menu-panel hidden absolute mt-2 bg-white text-text-primary rounded-ds-md shadow-elevated border border-border z-50"
          >
            <div class="p-4">
              <h3 id="share-menu-title" class="text-sm font-semibold text-text-primary">Compartilhar esta página</h3>
              <p class="mt-1 text-xs text-text-secondary">Escolha uma plataforma para divulgar as vagas.</p>
              <div class="share-menu-actions mt-3 text-xs">
                <a data-share-link="facebook" target="_blank" rel="noopener noreferrer" class="share-menu-action px-3 py-2 rounded-ds-sm border border-border hover:bg-surface-secondary focus:outline-none focus:ring-2 focus:ring-focus">Facebook</a>
                <a data-share-link="linkedin" target="_blank" rel="noopener noreferrer" class="share-menu-action px-3 py-2 rounded-ds-sm border border-border hover:bg-surface-secondary focus:outline-none focus:ring-2 focus:ring-focus">LinkedIn</a>
                <a data-share-link="twitter" target="_blank" rel="noopener noreferrer" class="share-menu-action px-3 py-2 rounded-ds-sm border border-border hover:bg-surface-secondary focus:outline-none focus:ring-2 focus:ring-focus">Twitter</a>
                <a data-share-link="whatsapp" target="_blank" rel="noopener noreferrer" class="share-menu-action px-3 py-2 rounded-ds-sm border border-border hover:bg-surface-secondary focus:outline-none focus:ring-2 focus:ring-focus">WhatsApp</a>
                <a data-share-link="email" class="share-menu-action px-3 py-2 rounded-ds-sm border border-border hover:bg-surface-secondary focus:outline-none focus:ring-2 focus:ring-focus">E-mail</a>
                <button type="button" data-share-copy="1" class="share-menu-action text-left px-3 py-2 rounded-ds-sm border border-border hover:bg-surface-secondary focus:outline-none focus:ring-2 focus:ring-focus">Copiar link</button>
              </div>
              <button type="button" data-share-native="1" class="share-menu-action mt-2 w-full text-left text-xs px-3 py-2 rounded-ds-sm border border-border hover:bg-surface-secondary focus:outline-none focus:ring-2 focus:ring-focus">Compartilhar no dispositivo</button>
              <div data-share-feedback="1" class="mt-2 text-xs text-text-secondary" role="status" aria-live="polite"></div>
            </div>
          </div>
        </div>
      </nav>
    </div>
    <div id="public-menu" class="hidden sm:hidden border-t border-white/10">
      <nav class="px-4 py-2 flex flex-col gap-2 text-sm bg-primary-800">
        <a href="<?= $base ?>/vagas" class="hover:text-primary-100">Trabalhe Conosco</a>
      </nav>
    </div>
  </header>
  <?php endif; ?>

  <?php
  // Vitrine pública de vagas (Trabalhe Conosco): o hero precisa sangrar até a borda da viewport, então essas duas
  // páginas (`home/index`, `home/vaga`) pedem `isFullBleed` e cuidam elas mesmas do container `max-w-6xl mx-auto
  // px-4` para o restante do conteúdo. Login, recuperação de senha e a confirmação de candidatura continuam com o
  // container padrão — nada muda para elas.
  $isFullBleed = !$isLoginPage && !empty($isFullBleed);
  ?>
  <main class="<?= $isLoginPage ? '' : 'flex-1 ' . ($isFullBleed ? '' : 'max-w-6xl mx-auto px-4 py-8 w-full') ?>">
    <?= $content ?>
  </main>

  <?php if (!$isLoginPage): ?>
  <footer class="bg-primary-900 text-white">
    <div class="max-w-6xl mx-auto px-4 py-8 flex flex-col items-center gap-2 text-center">
      <img src="<?= $base ?>/assets/Isotipolinear.png" alt="" aria-hidden="true" class="h-10 w-auto opacity-70">
      <p class="text-ds-caption text-white/70">
        © <?= date('Y') ?> <?= Config::app()['product_name'] ?? 'RH Madeplant' ?>. Todos os direitos reservados. • v<?= Config::app()['version'] ?? '' ?>
      </p>
    </div>
  </footer>
  <?php endif; ?>
</body>
</html>
