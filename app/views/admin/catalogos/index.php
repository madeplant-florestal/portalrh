<?php
require_once APP_PATH . '/views/partials/modulo-topo.php';
$queryBase = $base . $routeBase;
$q = (string)($filters['q'] ?? '');
$status = (string)($filters['status'] ?? '');
$canManage = in_array((string)Auth::role(), ['admin', 'rh'], true) || !empty($_SESSION['user_is_supervisor']);
$canDelete = (string)Auth::role() === 'admin' || !empty($_SESSION['user_is_supervisor']);

$actionButtonClass = 'inline-flex h-9 w-9 items-center justify-center rounded-ds-md border border-border bg-surface text-text-secondary shadow-sm transition hover:border-primary-300 hover:bg-primary-50 hover:text-primary-700';
$dangerButtonClass = 'inline-flex h-9 w-9 items-center justify-center rounded-ds-md border border-danger/30 bg-surface text-danger shadow-sm transition hover:bg-danger/10 hover:text-danger';
?>
<div class="space-y-6">
  <?= ui_modulo_topo($base, 'cadastros', basename((string)$routeBase), [
      'titulo' => $meta['plural'],
      'descricao' => 'Gestão de ' . strtolower($meta['plural']) . ' com navegação e ações minimalistas.',
  ]) ?>
  <div class="flex flex-wrap items-center gap-2">
    <a href="<?= $base ?>/admin/colaboradores" class="<?= ui_btn('secundario') ?>">Colaboradores</a>
    <?php if ($canManage): ?>
      <a href="<?= $queryBase ?>/novo" class="<?= ui_btn('primario') ?>">Novo <?= strtolower(Security::e($meta['singular'])) ?></a>
    <?php endif; ?>
  </div>

  <?php if (!empty($flashError)): ?>
    <div class="rounded-ds-md border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger"><?= Security::e($flashError) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashSuccess)): ?>
    <div class="rounded-ds-md border border-success/30 bg-success/10 px-4 py-3 text-sm text-success"><?= Security::e($flashSuccess) ?></div>
  <?php endif; ?>

  <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
    <div class="rounded-ds-lg bg-surface p-5 shadow-sm ring-1 ring-border">
      <p class="text-sm font-medium text-text-secondary">Total de registros</p>
      <p class="mt-2 text-3xl font-bold text-text-primary"><?= (int)($summary['total'] ?? 0) ?></p>
    </div>
    <div class="rounded-ds-lg bg-surface p-5 shadow-sm ring-1 ring-border">
      <p class="text-sm font-medium text-text-secondary">Ativos</p>
      <p class="mt-2 text-3xl font-bold text-success"><?= (int)($summary['ativos'] ?? 0) ?></p>
    </div>
    <div class="rounded-ds-lg bg-surface p-5 shadow-sm ring-1 ring-border">
      <p class="text-sm font-medium text-text-secondary">Inativos</p>
      <p class="mt-2 text-3xl font-bold text-text-secondary"><?= (int)($summary['inativos'] ?? 0) ?></p>
    </div>
    <div class="rounded-ds-lg bg-surface p-5 shadow-sm ring-1 ring-border">
      <p class="text-sm font-medium text-text-secondary">Vinculados a colaboradores</p>
      <p class="mt-2 text-3xl font-bold text-info"><?= (int)($summary['vinculados'] ?? 0) ?></p>
    </div>
  </div>

  <div class="rounded-ds-lg bg-surface p-5 shadow-sm ring-1 ring-border">
    <form class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4" method="get" action="<?= $queryBase ?>">
      <div class="xl:col-span-2">
        <label class="mb-2 block text-sm font-medium text-text-primary">Busca</label>
        <input type="text" name="q" value="<?= Security::e($q) ?>" placeholder="Nome ou slug" class="w-full rounded-ds-md border border-border px-4 py-3 text-sm outline-none transition focus:border-focus focus:ring-2 focus:ring-primary-100">
      </div>
      <div>
        <label class="mb-2 block text-sm font-medium text-text-primary">Status</label>
        <select name="status" class="w-full rounded-ds-md border border-border px-4 py-3 text-sm outline-none transition focus:border-focus focus:ring-2 focus:ring-primary-100">
          <option value="">Todos</option>
          <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Ativos</option>
          <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inativos</option>
        </select>
      </div>
      <div class="flex flex-wrap items-end gap-3">
        <button class="<?= ui_btn('primario') ?>">Filtrar</button>
        <a href="<?= $queryBase ?>" class="<?= ui_btn('secundario') ?>">Limpar</a>
      </div>
    </form>
  </div>

  <div class="rounded-ds-lg bg-surface p-5 shadow-sm ring-1 ring-border">
    <div class="mb-4 flex items-center justify-between">
      <div>
        <h2 class="text-lg font-semibold text-text-primary">Lista de <?= Security::e($meta['plural']) ?></h2>
        <p class="text-sm text-text-secondary">Gerencie os registros do módulo sem alterar o fluxo atual do painel.</p>
      </div>
      <span class="rounded-full bg-surface-secondary px-3 py-1 text-xs font-semibold text-text-secondary"><?= count($items) ?> registro(s)</span>
    </div>

    <div class="responsive-table-wrap">
      <table class="hidden min-w-full text-sm md:table">
        <thead class="bg-background">
          <tr class="border-b border-border">
            <th class="p-3 text-left font-medium text-text-secondary">Nome</th>
            <th class="p-3 text-left font-medium text-text-secondary">Slug</th>
            <th class="p-3 text-left font-medium text-text-secondary">Status</th>
            <?php if ($table === 'empresas'): ?>
              <th class="p-3 text-left font-medium text-text-secondary">Setores</th>
              <th class="p-3 text-left font-medium text-text-secondary">Colaboradores ativos</th>
            <?php else: ?>
              <th class="p-3 text-left font-medium text-text-secondary">Uso em colaboradores</th>
            <?php endif; ?>
            <th class="p-3 text-right font-medium text-text-secondary">Ações</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border">
          <?php foreach ($items as $item): ?>
            <?php $isActive = (int)($item['ativo'] ?? 0) === 1; ?>
            <tr class="hover:bg-surface-secondary">
              <td class="p-3 font-medium text-text-primary"><?= Security::e($item['nome']) ?></td>
              <td class="p-3 text-text-secondary"><?= Security::e($item['slug']) ?></td>
              <td class="p-3">
                <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= $isActive ? 'bg-success/10 text-success' : 'bg-border text-text-secondary' ?>">
                  <?= $isActive ? 'Ativo' : 'Inativo' ?>
                </span>
              </td>
              <?php if ($table === 'empresas'): ?>
                <td class="p-3 text-text-secondary"><?= (int)($item['setores_count'] ?? 0) ?></td>
                <td class="p-3 text-text-secondary"><?= (int)($item['usage_count'] ?? 0) ?></td>
              <?php else: ?>
                <td class="p-3 text-text-secondary"><?= (int)($item['usage_count'] ?? 0) ?></td>
              <?php endif; ?>
              <td class="p-3">
                <div class="flex justify-end gap-2">
                  <?php if ($canManage): ?>
                    <a href="<?= $queryBase ?>/editar/<?= (int)$item['id'] ?>" class="<?= $actionButtonClass ?>" title="Editar <?= strtolower(Security::e($meta['singular'])) ?>" aria-label="Editar <?= strtolower(Security::e($meta['singular'])) ?>">
                      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 1 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
                    </a>
                  <?php endif; ?>
                  <?php if ($canDelete): ?>
                    <form action="<?= $queryBase ?>/excluir/<?= (int)$item['id'] ?>" method="post" data-confirm-message="Excluir este registro?">
                      <input type="hidden" name="csrf" value="<?= Security::csrfToken() ?>">
                      <button type="submit" class="<?= $dangerButtonClass ?>" title="Excluir <?= strtolower(Security::e($meta['singular'])) ?>" aria-label="Excluir <?= strtolower(Security::e($meta['singular'])) ?>">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                      </button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($items)): ?>
            <tr>
              <td colspan="<?= $table === 'empresas' ? 6 : 5 ?>" class="p-6 text-center text-text-secondary">Nenhum registro encontrado para os filtros informados.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="responsive-card-list mt-4 md:hidden">
      <?php foreach ($items as $item): ?>
        <?php $isActive = (int)($item['ativo'] ?? 0) === 1; ?>
        <div class="responsive-card">
          <div class="text-base font-semibold text-text-primary"><?= Security::e($item['nome']) ?></div>
          <div class="mt-1 text-sm text-text-secondary">Slug: <?= Security::e($item['slug']) ?></div>
          <?php if ($table === 'empresas'): ?>
            <div class="mt-1 text-sm text-text-secondary">Setores: <?= (int)($item['setores_count'] ?? 0) ?></div>
            <div class="mt-1 text-sm text-text-secondary">Colaboradores ativos: <?= (int)($item['usage_count'] ?? 0) ?></div>
          <?php else: ?>
            <div class="mt-1 text-sm text-text-secondary">Vinculados: <?= (int)($item['usage_count'] ?? 0) ?></div>
          <?php endif; ?>
          <div class="mt-3">
            <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= $isActive ? 'bg-success/10 text-success' : 'bg-border text-text-secondary' ?>">
              <?= $isActive ? 'Ativo' : 'Inativo' ?>
            </span>
          </div>
          <div class="mt-4 flex gap-2">
            <?php if ($canManage): ?>
              <a href="<?= $queryBase ?>/editar/<?= (int)$item['id'] ?>" class="<?= $actionButtonClass ?>" title="Editar <?= strtolower(Security::e($meta['singular'])) ?>" aria-label="Editar <?= strtolower(Security::e($meta['singular'])) ?>">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 1 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
              </a>
            <?php endif; ?>
            <?php if ($canDelete): ?>
              <form action="<?= $queryBase ?>/excluir/<?= (int)$item['id'] ?>" method="post" data-confirm-message="Excluir este registro?">
                <input type="hidden" name="csrf" value="<?= Security::csrfToken() ?>">
                <button type="submit" class="<?= $dangerButtonClass ?>" title="Excluir <?= strtolower(Security::e($meta['singular'])) ?>" aria-label="Excluir <?= strtolower(Security::e($meta['singular'])) ?>">
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                </button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
