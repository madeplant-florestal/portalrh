<?php
// Central do Portal RH (Fase 3A): primeira tela no AppShell V2. Raiz da navegação — sem breadcrumb redundante.
// `$modulos` vem do PortalNavegacaoService (caminhos relativos); aqui só se aplica o `$base` e se renderiza.
require_once APP_PATH . '/views/partials/ui-shell.php';
$cards = array_map(static function (array $m) use ($base): array {
    $m['href'] = $base . $m['href'];
    return $m;
}, $modulos ?? []);
?>
<div class="space-y-6">
  <?= ui_page_header([
      'titulo' => 'Central do Portal RH',
      'descricao' => 'Escolha um módulo para continuar. Os acessos exibidos dependem das suas permissões.',
      'marca' => true,
  ]) ?>
  <?php if ($cards === []): ?>
    <div class="mx-auto max-w-md rounded-ds-lg border border-border bg-surface px-6 py-10 text-center shadow-resting">
      <span class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-surface-secondary text-text-muted"><?= ui_module_icon('generico') ?></span>
      <h2 class="mt-3 text-sm font-bold text-text-primary">Nenhum módulo disponível</h2>
      <p class="mt-1 text-[12.5px] text-text-secondary">Você ainda não possui acessos liberados no Portal RH. Fale com o administrador do sistema.</p>
    </div>
  <?php else: ?>
    <?= ui_module_grid($cards) ?>
    <?php // Manual de Uso: antes só existia na sidebar antiga; sem ela, esta é a entrada (gate de role do controller: qualquer usuário autenticado). ?>
    <p class="text-center text-ds-caption text-text-secondary">Precisa de ajuda? <a href="<?= $base ?>/admin/manual" class="font-semibold text-primary-700 underline hover:text-primary-800">Manual de Uso</a></p>
  <?php endif; ?>
</div>
