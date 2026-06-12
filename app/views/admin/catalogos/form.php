<?php
$isEditing = !empty($item['id']);
$title = $isEditing ? 'Editar ' . $meta['singular'] : 'Novo ' . $meta['singular'];
$nameValue = (string)($item['nome'] ?? '');
$slugValue = (string)($item['slug'] ?? '');
$activeValue = (int)($item['ativo'] ?? 1) === 1;
?>
<div class="mx-auto max-w-3xl space-y-6">
  <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
    <div>
      <h1 class="text-fluid-title font-bold text-gray-800"><?= Security::e($title) ?></h1>
      <p class="text-fluid-subtitle text-gray-600">Cadastro enxuto para manutenção do módulo de <?= strtolower(Security::e($meta['plural'])) ?>.</p>
    </div>
    <a href="<?= $base . $routeBase ?>" class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
      Voltar
    </a>
  </div>

  <?php if (!empty($error)): ?>
    <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"><?= Security::e($error) ?></div>
  <?php endif; ?>

  <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
    <form method="post" action="<?= $base . $formAction ?>" class="space-y-5">
      <input type="hidden" name="csrf" value="<?= Security::e($csrf ?? Security::csrfToken()) ?>">

      <div>
        <label class="mb-2 block text-sm font-medium text-slate-700">Nome</label>
        <input type="text" name="nome" value="<?= Security::e($nameValue) ?>" maxlength="160" required class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
      </div>

      <div>
        <label class="mb-2 block text-sm font-medium text-slate-700">Slug</label>
        <input type="text" name="slug" value="<?= Security::e($slugValue) ?>" maxlength="180" placeholder="Opcional. Se vazio, sera gerado automaticamente." class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
        <p class="mt-2 text-xs text-slate-500">Use apenas para sobrescrever o identificador gerado automaticamente.</p>
      </div>

      <label class="flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
        <input type="checkbox" name="ativo" value="1" <?= $activeValue ? 'checked' : '' ?> class="h-4 w-4 rounded border-slate-300 text-blue-900 focus:ring-blue-200">
        Registro ativo
      </label>

      <div class="flex flex-wrap gap-3 pt-2">
        <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-blue-900 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-blue-950">
          <?= $isEditing ? 'Salvar alterações' : 'Cadastrar' ?>
        </button>
        <a href="<?= $base . $routeBase ?>" class="inline-flex items-center justify-center rounded-xl border border-slate-200 px-4 py-3 text-sm font-semibold text-slate-600 hover:bg-slate-50">
          Cancelar
        </a>
      </div>
    </form>
  </div>
</div>
