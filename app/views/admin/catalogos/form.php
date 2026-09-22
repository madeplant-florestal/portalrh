<?php
require_once APP_PATH . '/views/partials/modulo-topo.php';
$isEditing = !empty($item['id']);
$title = $isEditing ? 'Editar ' . $meta['singular'] : 'Novo ' . $meta['singular'];
$nameValue = (string)($item['nome'] ?? '');
$slugValue = (string)($item['slug'] ?? '');
$activeValue = (int)($item['ativo'] ?? 1) === 1;
?>
<div class="mx-auto max-w-3xl space-y-6">
  <?= ui_modulo_topo($base, 'cadastros', basename((string)$routeBase), [
      'titulo' => $title,
      'descricao' => 'Cadastro enxuto para manutenção do módulo de ' . strtolower(Security::e($meta['plural'])) . '.',
  ], [['label' => $title]]) ?>

  <?php if (!empty($error)): ?>
    <div class="rounded-ds-md border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger"><?= Security::e($error) ?></div>
  <?php endif; ?>

  <div class="rounded-ds-lg bg-surface p-6 shadow-sm ring-1 ring-border">
    <form method="post" action="<?= $base . $formAction ?>" class="space-y-5">
      <input type="hidden" name="csrf" value="<?= Security::e($csrf ?? Security::csrfToken()) ?>">

      <div>
        <label class="mb-2 block text-sm font-medium text-text-primary">Nome</label>
        <input type="text" name="nome" value="<?= Security::e($nameValue) ?>" maxlength="160" required class="w-full rounded-ds-md border border-border px-4 py-3 text-sm outline-none transition focus:border-focus focus:ring-2 focus:ring-primary-100">
      </div>

      <div>
        <label class="mb-2 block text-sm font-medium text-text-primary">Slug</label>
        <input type="text" name="slug" value="<?= Security::e($slugValue) ?>" maxlength="180" placeholder="Opcional. Se vazio, sera gerado automaticamente." class="w-full rounded-ds-md border border-border px-4 py-3 text-sm outline-none transition focus:border-focus focus:ring-2 focus:ring-primary-100">
        <p class="mt-2 text-xs text-text-secondary">Use apenas para sobrescrever o identificador gerado automaticamente.</p>
      </div>

      <label class="flex items-center gap-3 rounded-ds-md border border-border bg-background px-4 py-3 text-sm text-text-primary">
        <input type="checkbox" name="ativo" value="1" <?= $activeValue ? 'checked' : '' ?> class="h-4 w-4 rounded border-border text-primary-700 focus:ring-primary-100">
        Registro ativo
      </label>

      <div class="flex flex-wrap gap-3 pt-2">
        <button type="submit" class="<?= ui_btn('primario') ?>">
          <?= $isEditing ? 'Salvar alterações' : 'Cadastrar' ?>
        </button>
        <a href="<?= $base . $routeBase ?>" class="<?= ui_btn('secundario') ?>">
          Cancelar
        </a>
      </div>
    </form>
  </div>
</div>
