<?php
$isEditing = !empty($item['id']);
$title = $isEditing ? 'Editar Cargo' : 'Novo Cargo';
$nameValue = (string)($item['nome'] ?? '');
$slugValue = (string)($item['slug'] ?? '');
$activeValue = (int)($item['ativo'] ?? 1) === 1;
?>
<div class="mx-auto max-w-6xl space-y-6">
  <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
    <div>
      <h1 class="text-fluid-title font-bold text-gray-800"><?= Security::e($title) ?></h1>
      <p class="text-fluid-subtitle text-gray-600">Gerencie o cadastro do cargo e os setores permitidos para este relacionamento.</p>
    </div>
    <a href="<?= $base . $routeBase ?>" class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
      Voltar
    </a>
  </div>

  <?php if (!empty($error)): ?>
    <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"><?= Security::e($error) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashError)): ?>
    <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"><?= Security::e($flashError) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashSuccess)): ?>
    <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700"><?= Security::e($flashSuccess) ?></div>
  <?php endif; ?>

  <div class="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(0,0.8fr)]">
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

    <?php if ($isEditing): ?>
      <div class="space-y-6">
        <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
          <div class="flex items-center justify-between gap-3">
            <div>
              <h2 class="text-lg font-semibold text-slate-900">Setores vinculados</h2>
              <p class="text-sm text-slate-500">Remova vínculos individualmente sem alterar o cadastro principal.</p>
            </div>
            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600"><?= count($linkedSetores) ?> vínculo(s)</span>
          </div>

          <div class="mt-4 space-y-3">
            <?php foreach ($linkedSetores as $setor): ?>
              <div class="flex flex-col gap-3 rounded-xl border border-slate-200 p-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <div class="font-semibold text-slate-900"><?= Security::e($setor['nome']) ?></div>
                  <div class="text-xs text-slate-500">Slug: <?= Security::e($setor['slug']) ?></div>
                </div>
                <form method="post" action="<?= $base ?>/admin/cargos/<?= (int)$item['id'] ?>/setores/<?= (int)$setor['id'] ?>/desvincular" data-confirm-message="Remover este vínculo?">
                  <input type="hidden" name="csrf" value="<?= Security::e($csrf ?? Security::csrfToken()) ?>">
                  <button type="submit" class="inline-flex items-center justify-center rounded-xl border border-rose-200 bg-white px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">
                    Remover vínculo
                  </button>
                </form>
              </div>
            <?php endforeach; ?>
            <?php if (empty($linkedSetores)): ?>
              <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-5 text-sm text-slate-500">
                Nenhum setor vinculado a este cargo até o momento.
              </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
          <h2 class="text-lg font-semibold text-slate-900">Associar setores</h2>
          <p class="mt-1 text-sm text-slate-500">Selecione um ou mais setores disponíveis para este cargo.</p>

          <form method="post" action="<?= $base ?>/admin/cargos/<?= (int)$item['id'] ?>/setores/vincular" class="mt-4 space-y-4">
            <input type="hidden" name="csrf" value="<?= Security::e($csrf ?? Security::csrfToken()) ?>">
            <div>
              <label class="mb-2 block text-sm font-medium text-slate-700">Setores disponíveis</label>
              <select name="setor_ids[]" multiple size="10" class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
                <?php foreach ($availableSetores as $setor): ?>
                  <option value="<?= (int)$setor['id'] ?>"><?= Security::e($setor['nome']) ?></option>
                <?php endforeach; ?>
              </select>
              <p class="mt-2 text-xs text-slate-500">Use Ctrl/Cmd para selecionar múltiplos setores.</p>
            </div>
            <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-blue-900 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-blue-950">
              Vincular setores
            </button>
          </form>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>
