<?php
require_once APP_PATH . '/views/partials/modulo-topo.php';
$isEditing = !empty($item['id']);
$title = $isEditing ? 'Editar Setor' : 'Novo Setor';
$nameValue = (string)($item['nome'] ?? '');
$slugValue = (string)($item['slug'] ?? '');
$activeValue = (int)($item['ativo'] ?? 1) === 1;
$empresaIdValue = (int)($item['empresa_id'] ?? 0);
$currentEmpresaInativa = $isEditing
    && $empresaIdValue > 0
    && !array_filter($companies ?? [], static fn(array $empresa): bool => (int)($empresa['id'] ?? 0) === $empresaIdValue);
?>
<div class="mx-auto max-w-6xl space-y-6">
  <?= ui_modulo_topo($base, 'cadastros', 'setores', [
      'titulo' => $title,
      'descricao' => 'Gerencie o cadastro do setor e os cargos habilitados para este relacionamento.',
  ], [['label' => $title]]) ?>

  <?php if (!empty($error)): ?>
    <div class="rounded-ds-md border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger"><?= Security::e($error) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashError)): ?>
    <div class="rounded-ds-md border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger"><?= Security::e($flashError) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashSuccess)): ?>
    <div class="rounded-ds-md border border-success/30 bg-success/10 px-4 py-3 text-sm text-success"><?= Security::e($flashSuccess) ?></div>
  <?php endif; ?>

  <div class="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(0,0.8fr)]">
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

        <div>
          <label class="mb-2 block text-sm font-medium text-text-primary">Empresa *</label>
          <select name="empresa_id" required class="w-full rounded-ds-md border border-border px-4 py-3 text-sm outline-none transition focus:border-focus focus:ring-2 focus:ring-primary-100">
            <option value="">Selecione uma empresa</option>
            <?php if ($currentEmpresaInativa): ?>
              <option value="<?= $empresaIdValue ?>" selected>
                <?= Security::e((string)($item['empresa_nome'] ?? 'Empresa vinculada')) ?> (inativa)
              </option>
            <?php endif; ?>
            <?php foreach (($companies ?? []) as $empresa): ?>
              <option value="<?= (int)$empresa['id'] ?>" <?= $empresaIdValue === (int)$empresa['id'] ? 'selected' : '' ?>>
                <?= Security::e($empresa['nome']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <p class="mt-2 text-xs text-text-secondary">Somente empresas ativas podem ser selecionadas para novos vínculos.</p>
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

    <?php if ($isEditing): ?>
      <div class="space-y-6">
        <div class="rounded-ds-lg bg-surface p-6 shadow-sm ring-1 ring-border">
          <div class="flex items-center justify-between gap-3">
            <div>
              <h2 class="text-lg font-semibold text-text-primary">Cargos vinculados</h2>
              <p class="text-sm text-text-secondary">Remova vínculos individualmente sem alterar o cadastro principal.</p>
            </div>
            <span class="rounded-full bg-surface-secondary px-3 py-1 text-xs font-semibold text-text-secondary"><?= count($linkedCargos) ?> vínculo(s)</span>
          </div>

          <div class="mt-4 space-y-3">
            <?php foreach ($linkedCargos as $cargo): ?>
              <div class="flex flex-col gap-3 rounded-ds-md border border-border p-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <div class="font-semibold text-text-primary"><?= Security::e($cargo['nome']) ?></div>
                  <div class="text-xs text-text-secondary">Slug: <?= Security::e($cargo['slug']) ?></div>
                </div>
                <form method="post" action="<?= $base ?>/admin/setores/<?= (int)$item['id'] ?>/cargos/<?= (int)$cargo['id'] ?>/desvincular" data-confirm-message="Remover este vínculo?">
                  <input type="hidden" name="csrf" value="<?= Security::e($csrf ?? Security::csrfToken()) ?>">
                  <button type="submit" class="inline-flex items-center justify-center rounded-ds-md border border-danger/30 bg-surface px-4 py-2 text-sm font-semibold text-danger hover:bg-danger/10">
                    Remover vínculo
                  </button>
                </form>
              </div>
            <?php endforeach; ?>
            <?php if (empty($linkedCargos)): ?>
              <div class="rounded-ds-md border border-dashed border-border bg-background px-4 py-5 text-sm text-text-secondary">
                Nenhum cargo vinculado a este setor até o momento.
              </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="rounded-ds-lg bg-surface p-6 shadow-sm ring-1 ring-border">
          <h2 class="text-lg font-semibold text-text-primary">Associar cargos</h2>
          <p class="mt-1 text-sm text-text-secondary">Selecione um ou mais cargos disponíveis para este setor.</p>

          <form method="post" action="<?= $base ?>/admin/setores/<?= (int)$item['id'] ?>/cargos/vincular" class="mt-4 space-y-4">
            <input type="hidden" name="csrf" value="<?= Security::e($csrf ?? Security::csrfToken()) ?>">
            <div>
              <label class="mb-2 block text-sm font-medium text-text-primary">Cargos disponíveis</label>
              <select name="cargo_ids[]" multiple size="10" class="w-full rounded-ds-md border border-border px-4 py-3 text-sm outline-none transition focus:border-focus focus:ring-2 focus:ring-primary-100">
                <?php foreach ($availableCargos as $cargo): ?>
                  <option value="<?= (int)$cargo['id'] ?>"><?= Security::e($cargo['nome']) ?></option>
                <?php endforeach; ?>
              </select>
              <p class="mt-2 text-xs text-text-secondary">Use Ctrl/Cmd para selecionar múltiplos cargos.</p>
            </div>
            <button type="submit" class="<?= ui_btn('primario') ?>">
              Vincular cargos
            </button>
          </form>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>
