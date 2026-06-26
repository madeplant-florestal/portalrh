<?php
$editing = !empty($vaga);
?>
<div class="responsive-panel max-w-2xl">
  <h2 class="text-xl font-semibold text-ctpblue"><?= $editing ? 'Editar vaga' : 'Nova vaga' ?></h2>
  <?php if (!empty($error)): ?>
    <div class="mt-3 bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded"><?= Security::e($error) ?></div>
  <?php endif; ?>
  <form class="mt-4 space-y-3" action="<?= $editing ? $base . '/admin/vagas/editar/' . (int)$vaga['id'] : $base . '/admin/vagas/novo' ?>" method="post">
    <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
    <div>
      <label class="block text-sm">Título</label>
      <input type="text" name="titulo" value="<?= Security::e($vaga['titulo'] ?? '') ?>" required class="mt-1 w-full border rounded px-3 py-2" />
    </div>
    <div>
      <label class="block text-sm">Descrição</label>
      <textarea name="descricao" rows="4" class="mt-1 w-full border rounded px-3 py-2" required><?= Security::e($vaga['descricao'] ?? '') ?></textarea>
    </div>
    <div>
      <label class="block text-sm">Requisitos</label>
      <textarea name="requisitos" rows="3" class="mt-1 w-full border rounded px-3 py-2" required><?= Security::e($vaga['requisitos'] ?? '') ?></textarea>
    </div>
    <div class="grid gap-3 md:grid-cols-2">
      <div>
        <label class="block text-sm">Área</label>
        <input type="text" name="area" value="<?= Security::e($vaga['area'] ?? '') ?>" class="mt-1 w-full border rounded px-3 py-2" />
      </div>
      <div>
        <label class="block text-sm">Local</label>
        <input type="text" name="local" value="<?= Security::e($vaga['local'] ?? '') ?>" class="mt-1 w-full border rounded px-3 py-2" />
      </div>
    </div>
    <div>
      <label class="block text-sm">Empresa / Tenant</label>
      <select name="empresa_id" class="mt-1 w-full border rounded px-3 py-2">
        <option value="">Usar tenant padrão do recrutamento</option>
        <?php foreach (($empresas ?? []) as $empresa): ?>
          <option value="<?= (int)$empresa['id'] ?>" <?= (int)($vaga['empresa_id'] ?? 0) === (int)$empresa['id'] ? 'selected' : '' ?>>
            <?= Security::e($empresa['nome']) ?><?= (int)($empresa['ativo'] ?? 1) === 1 ? '' : ' (inativa)' ?>
          </option>
        <?php endforeach; ?>
      </select>
      <p class="mt-1 text-xs text-gray-500">Quando a vaga estiver vinculada a uma empresa, o webhook utiliza esse tenant; caso contrário, utiliza a configuração padrão.</p>
    </div>
    <label class="inline-flex items-center space-x-2">
      <input type="checkbox" name="ativo" <?= !empty($vaga['ativo']) ? 'checked' : '' ?> />
      <span class="text-sm">Ativo</span>
    </label>
    <div class="responsive-form-actions pt-2">
      <button class="bg-ctgreen text-white px-4 py-2 rounded hover:bg-ctdark">Salvar</button>
      <a href="<?= $base ?>/admin/vagas" class="text-ctpblue hover:text-ctgreen">Cancelar</a>
    </div>
  </form>
</div>
