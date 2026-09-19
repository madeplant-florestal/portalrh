<?php
/**
 * Layout das páginas públicas SENSÍVEIS (Entrevista de Desligamento). Diferente de layouts/main.php:
 * nenhum recurso de terceiro (sem Google Fonts, sem CDN, sem script) — a URL com o token nunca é
 * enviada a outro domínio — e nenhuma marca de área administrativa.
 */
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <meta name="referrer" content="no-referrer">
  <title>Entrevista de Desligamento — Madeplant</title>
  <link rel="stylesheet" href="<?= Security::e((string)($base ?? '')) ?>/assets/tailwind.css?v=<?= urlencode(Config::assetVersion('assets/tailwind.css')) ?>">
</head>
<body class="min-h-screen bg-[#F7F6F1] font-sans text-[#2B2E22] antialiased">
  <main>
    <?= $content ?>
  </main>
</body>
</html>
