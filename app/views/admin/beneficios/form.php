<?php
?>
<div class="responsive-panel max-w-2xl">
  <h2 class="text-xl font-semibold text-ctpblue mb-4"><?= isset($beneficio['id']) ? 'Editar benefício' : 'Novo benefício' ?></h2>

  <?php if (!empty($error)): ?>
    <div class="mb-4 p-3 bg-red-50 text-red-600 border border-red-200 rounded"><?= Security::e($error) ?></div>
  <?php endif; ?>

  <form action="<?= $base ?><?= isset($beneficio['id']) ? '/admin/beneficios/editar/' . (int)$beneficio['id'] : '/admin/beneficios/novo' ?>" method="post" enctype="multipart/form-data" class="space-y-4">
    <input type="hidden" name="csrf" value="<?= $csrf ?>" />

    <div>
      <label class="block text-sm font-medium text-gray-700">Nome do benefício *</label>
      <input type="text" name="nome" value="<?= Security::e($beneficio['nome'] ?? '') ?>" class="mt-1 w-full border rounded px-3 py-2" required />
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700">Empresa parceira</label>
      <input type="text" name="parceiro" value="<?= Security::e($beneficio['parceiro'] ?? '') ?>" class="mt-1 w-full border rounded px-3 py-2" />
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700">Descrição</label>
      <textarea name="descricao" rows="4" class="mt-1 w-full border rounded px-3 py-2"><?= Security::e($beneficio['descricao'] ?? '') ?></textarea>
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700">Logo da parceira (PNG/JPG/WEBP, até 2MB)</label>
      <input type="file" name="logo" accept="image/*" class="mt-1 w-full" />
      <?php if (!empty($beneficio['logo_path'])): ?>
        <div class="mt-2">
          <span class="text-sm text-gray-500">Logo atual:</span>
          <img src="<?= $base ?>/uploads/logos/<?= Security::e($beneficio['logo_path']) ?>" alt="Logo" class="h-12 object-contain" />
        </div>
      <?php endif; ?>
    </div>

    <div class="flex items-center">
      <input type="checkbox" id="ativo" name="ativo" <?= (int)($beneficio['ativo'] ?? 1) === 1 ? 'checked' : '' ?> class="mr-2" />
      <label for="ativo" class="text-sm text-gray-700">Ativo</label>
    </div>

    <div class="responsive-form-actions pt-2">
      <button type="submit" class="bg-ctgreen text-white px-4 py-2 rounded hover:bg-ctdark">Salvar</button>
      <a href="<?= $base ?>/admin/beneficios" class="text-ctpblue hover:text-ctgreen">Cancelar</a>
    </div>
  </form>
</div>
