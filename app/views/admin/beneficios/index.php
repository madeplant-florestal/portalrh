<?php
require_once APP_PATH . '/views/partials/modulo-topo.php';
?>
<div class="space-y-4">
  <?= ui_modulo_topo($base, 'cadastros', 'beneficios', ['titulo' => 'Benefícios', 'descricao' => 'Benefícios e parceiros disponíveis aos colaboradores.']) ?>
  <div class="flex flex-wrap items-center gap-2"><a href="<?= $base ?>/admin/beneficios/novo" class="<?= ui_btn('primario') ?>">Novo benefício</a></div>
  <div class="responsive-panel">
  <div class="responsive-table-wrap mt-4">
    <table class="hidden min-w-full text-sm md:table">
    <thead>
      <tr class="text-left text-text-secondary border-b">
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
              <span class="text-text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="p-3"><?= Security::e($b['nome']) ?></td>
          <td class="p-3"><?= Security::e($b['parceiro'] ?? '') ?></td>
          <td class="p-3"><?= (int)($b['ativo'] ?? 0) === 1 ? 'Sim' : 'Não' ?></td>
          <td class="p-3">
            <div class="responsive-card-actions">
              <a href="<?= $base ?>/admin/beneficios/editar/<?= (int)$b['id'] ?>" class="text-primary-700 hover:text-primary-700">Editar</a>
              <form action="<?= $base ?>/admin/beneficios/excluir/<?= (int)$b['id'] ?>" method="post" class="inline" data-confirm-message="Excluir este benefício?">
                <input type="hidden" name="csrf" value="<?= Security::csrfToken() ?>" />
                <button type="submit" class="text-danger hover:text-danger">Excluir</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($beneficios)): ?>
        <tr>
          <td colspan="5" class="p-4 text-center text-text-secondary">Nenhum benefício cadastrado</td>
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
            <div class="flex h-12 w-12 items-center justify-center rounded-ds-md bg-border text-sm text-text-secondary">—</div>
          <?php endif; ?>
          <div class="min-w-0">
            <div class="text-base font-semibold text-primary-700"><?= Security::e($b['nome']) ?></div>
            <div class="mt-1 text-sm text-text-secondary"><?= Security::e($b['parceiro'] ?? 'Sem parceiro informado') ?></div>
            <div class="mt-2 text-xs font-medium text-text-secondary"><?= (int)($b['ativo'] ?? 0) === 1 ? 'Ativo' : 'Inativo' ?></div>
          </div>
        </div>
        <div class="responsive-card-actions mt-4">
          <a href="<?= $base ?>/admin/beneficios/editar/<?= (int)$b['id'] ?>" class="text-primary-700 hover:text-primary-700">Editar</a>
          <form action="<?= $base ?>/admin/beneficios/excluir/<?= (int)$b['id'] ?>" method="post" data-confirm-message="Excluir este benefício?">
            <input type="hidden" name="csrf" value="<?= Security::csrfToken() ?>" />
            <button type="submit" class="text-danger hover:text-danger">Excluir</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
</div>
