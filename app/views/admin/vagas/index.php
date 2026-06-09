<?php
?>
<div class="responsive-panel">
  <div class="responsive-header">
    <h2 class="text-xl font-semibold text-ctpblue">Vagas</h2>
    <a href="<?= $base ?>/admin/vagas/novo" class="inline-flex items-center justify-center rounded-lg bg-ctgreen px-4 py-3 text-sm font-medium text-white hover:bg-ctdark">Nova vaga</a>
  </div>
  <div class="responsive-table-wrap mt-4">
    <table class="mobile-table-desktop min-w-full text-sm">
    <thead>
      <tr class="border-b">
        <th class="text-left p-3">Título</th>
        <th class="text-left p-3">Área</th>
        <th class="text-left p-3">Local</th>
        <th class="text-left p-3">Ativo</th>
        <th class="text-left p-3">Ações</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($vagas as $v): ?>
        <tr class="border-b hover:bg-gray-50">
          <td class="p-3 font-medium text-ctpblue"><?= Security::e($v['titulo']) ?></td>
          <td class="p-3"><?= Security::e($v['area']) ?></td>
          <td class="p-3"><?= Security::e($v['local']) ?></td>
          <td class="p-3">
            <span class="px-2 py-1 rounded text-white <?= (int)$v['ativo'] === 1 ? 'bg-ctgreen' : 'bg-red-400' ?>">
              <?= (int)$v['ativo'] === 1 ? 'Sim' : 'Não' ?>
            </span>
          </td>
          <td class="p-3">
            <div class="responsive-card-actions">
              <a href="<?= $base ?>/admin/vagas/editar/<?= (int)$v['id'] ?>" class="text-ctpblue hover:text-ctgreen">Editar</a>
              <form action="<?= $base ?>/admin/vagas/excluir/<?= (int)$v['id'] ?>" method="post" class="inline" data-confirm-message="Excluir esta vaga?">
                <input type="hidden" name="csrf" value="<?= Security::e(Security::csrfToken()) ?>">
                <button class="text-red-600 hover:text-red-800">Excluir</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    </table>
  </div>
  <div class="responsive-card-list mt-4 md:hidden">
    <?php foreach ($vagas as $v): ?>
      <div class="responsive-card">
        <div class="text-base font-semibold text-ctpblue"><?= Security::e($v['titulo']) ?></div>
        <div class="mt-2 text-sm text-gray-600">Área: <?= Security::e($v['area']) ?></div>
        <div class="mt-1 text-sm text-gray-600">Local: <?= Security::e($v['local']) ?></div>
        <div class="mt-3">
          <span class="rounded px-2 py-1 text-xs font-semibold text-white <?= (int)$v['ativo'] === 1 ? 'bg-ctgreen' : 'bg-red-400' ?>">
            <?= (int)$v['ativo'] === 1 ? 'Ativa' : 'Inativa' ?>
          </span>
        </div>
        <div class="responsive-card-actions mt-4">
          <a href="<?= $base ?>/admin/vagas/editar/<?= (int)$v['id'] ?>" class="text-ctpblue hover:text-ctgreen">Editar</a>
          <form action="<?= $base ?>/admin/vagas/excluir/<?= (int)$v['id'] ?>" method="post" data-confirm-message="Excluir esta vaga?">
            <input type="hidden" name="csrf" value="<?= Security::e(Security::csrfToken()) ?>">
            <button class="text-red-600 hover:text-red-800">Excluir</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
