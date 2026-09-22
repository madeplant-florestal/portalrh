<?php
require_once APP_PATH . '/views/partials/modulo-topo.php';
?>
<div class="space-y-4 max-w-2xl">
  <?= ui_modulo_topo($base, 'cadastros', 'beneficios', ['titulo' => isset($beneficio['id']) ? 'Editar benefício' : 'Novo benefício', 'descricao' => 'Benefícios e parceiros disponíveis aos colaboradores.'], [['label' => isset($beneficio['id']) ? 'Editar benefício' : 'Novo benefício']]) ?>
  <div class="responsive-panel">

  <?php if (!empty($error)): ?>
    <div class="mb-4 p-3 bg-danger/10 text-danger border border-danger/30 rounded"><?= Security::e($error) ?></div>
  <?php endif; ?>

  <form action="<?= $base ?><?= isset($beneficio['id']) ? '/admin/beneficios/editar/' . (int)$beneficio['id'] : '/admin/beneficios/novo' ?>" method="post" enctype="multipart/form-data" class="space-y-4">
    <input type="hidden" name="csrf" value="<?= $csrf ?>" />

    <div>
      <label class="block text-sm font-medium text-text-primary">Nome do benefício *</label>
      <input type="text" name="nome" value="<?= Security::e($beneficio['nome'] ?? '') ?>" class="mt-1 w-full border rounded px-3 py-2" required />
    </div>

    <div>
      <label class="block text-sm font-medium text-text-primary">Empresa parceira</label>
      <input type="text" name="parceiro" value="<?= Security::e($beneficio['parceiro'] ?? '') ?>" class="mt-1 w-full border rounded px-3 py-2" />
    </div>

    <div>
      <label class="block text-sm font-medium text-text-primary">Descrição</label>
      <textarea name="descricao" rows="4" class="mt-1 w-full border rounded px-3 py-2"><?= Security::e($beneficio['descricao'] ?? '') ?></textarea>
    </div>

    <div>
      <label class="block text-sm font-medium text-text-primary">Logo da parceira (PNG/JPG/WEBP, até 2MB)</label>
      <input type="file" name="logo" accept="image/*" class="mt-1 w-full" />
      <?php if (!empty($beneficio['logo_path'])): ?>
        <div class="mt-2">
          <span class="text-sm text-text-secondary">Logo atual:</span>
          <img src="<?= $base ?>/uploads/logos/<?= Security::e($beneficio['logo_path']) ?>" alt="Logo" class="h-12 object-contain" />
        </div>
      <?php endif; ?>
    </div>

    <div class="flex items-center">
      <input type="checkbox" id="ativo" name="ativo" <?= (int)($beneficio['ativo'] ?? 1) === 1 ? 'checked' : '' ?> class="mr-2" />
      <label for="ativo" class="text-sm text-text-primary">Ativo</label>
    </div>

    <div class="responsive-form-actions pt-2">
      <button type="submit" class="<?= ui_btn('primario') ?>">Salvar</button>
      <a href="<?= $base ?>/admin/beneficios" class="text-primary-700 hover:text-primary-700">Cancelar</a>
    </div>
  </form>
</div>
</div>
