<?php
?>
<div class="responsive-panel">
  <div class="responsive-header">
    <h2 class="text-xl font-semibold text-ctpblue">Benefícios</h2>
    <a href="<?= $base ?>/admin/beneficios/novo" class="inline-flex items-center justify-center rounded-lg bg-ctgreen px-4 py-3 text-sm font-medium text-white hover:bg-ctdark">Novo benefício</a>
  </div>

  <div class="responsive-table-wrap mt-4">
    <table class="mobile-table-desktop min-w-full text-sm">
    <thead>
      <tr class="text-left text-gray-500 border-b">
        <th class="p-3">Logo</th>
        <th class="p-3">Nome</th>
        <th class="p-3">Parceiro</th>
        <th class="p-3">Ativo</th>
        <th class="p-3">Ações</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach (($beneficios ?? []) as $b): ?>
        <tr class="border-b">
          <td class="p-3">
            <?php if (!empty($b['logo_path'])): ?>
              <img src="<?= $base ?>/uploads/logos/<?= Security::e($b['logo_path']) ?>" alt="Logo" class="h-10 w-10 object-contain" />
            <?php else: ?>
              <span class="text-gray-400">—</span>
            <?php endif; ?>
          </td>
          <td class="p-3"><?= Security::e($b['nome']) ?></td>
          <td class="p-3"><?= Security::e($b['parceiro'] ?? '') ?></td>
          <td class="p-3"><?= (int)($b['ativo'] ?? 0) === 1 ? 'Sim' : 'Não' ?></td>
          <td class="p-3">
            <div class="responsive-card-actions">
              <a href="<?= $base ?>/admin/beneficios/editar/<?= (int)$b['id'] ?>" class="text-ctpblue hover:text-ctgreen">Editar</a>
              <form action="<?= $base ?>/admin/beneficios/excluir/<?= (int)$b['id'] ?>" method="post" class="inline" data-confirm-message="Excluir este benefício?">
                <input type="hidden" name="csrf" value="<?= Security::csrfToken() ?>" />
                <button type="submit" class="text-red-600 hover:text-red-800">Excluir</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($beneficios)): ?>
        <tr>
          <td colspan="5" class="p-4 text-center text-gray-500">Nenhum benefício cadastrado</td>
        </tr>
      <?php endif; ?>
    </tbody>
    </table>
  </div>
  <div class="responsive-card-list mt-4 md:hidden">
    <?php foreach (($beneficios ?? []) as $b): ?>
      <div class="responsive-card">
        <div class="flex items-start gap-3">
          <?php if (!empty($b['logo_path'])): ?>
            <img src="<?= $base ?>/uploads/logos/<?= Security::e($b['logo_path']) ?>" alt="Logo" class="h-12 w-12 shrink-0 object-contain" />
          <?php else: ?>
            <div class="flex h-12 w-12 items-center justify-center rounded-lg bg-gray-200 text-sm text-gray-500">—</div>
          <?php endif; ?>
          <div class="min-w-0">
            <div class="text-base font-semibold text-ctpblue"><?= Security::e($b['nome']) ?></div>
            <div class="mt-1 text-sm text-gray-600"><?= Security::e($b['parceiro'] ?? 'Sem parceiro informado') ?></div>
            <div class="mt-2 text-xs font-medium text-gray-500"><?= (int)($b['ativo'] ?? 0) === 1 ? 'Ativo' : 'Inativo' ?></div>
          </div>
        </div>
        <div class="responsive-card-actions mt-4">
          <a href="<?= $base ?>/admin/beneficios/editar/<?= (int)$b['id'] ?>" class="text-ctpblue hover:text-ctgreen">Editar</a>
          <form action="<?= $base ?>/admin/beneficios/excluir/<?= (int)$b['id'] ?>" method="post" data-confirm-message="Excluir este benefício?">
            <input type="hidden" name="csrf" value="<?= Security::csrfToken() ?>" />
            <button type="submit" class="text-red-600 hover:text-red-800">Excluir</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
