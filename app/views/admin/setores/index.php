<?php
require_once APP_PATH . '/views/partials/modulo-topo.php';
$queryBase = $base . $routeBase;
$q = (string)($filters['q'] ?? '');
$status = (string)($filters['status'] ?? '');
$empresa = (string)($filters['empresa'] ?? '');
$params = [];
if ($q !== '') { $params['q'] = $q; }
if ($status !== '') { $params['status'] = $status; }
if ($empresa !== '') { $params['empresa'] = $empresa; }
$canManage = in_array((string)Auth::role(), ['admin', 'rh'], true) || !empty($_SESSION['user_is_supervisor']);
$canDelete = (string)Auth::role() === 'admin' || !empty($_SESSION['user_is_supervisor']);
$actionButtonClass = 'inline-flex h-9 w-9 items-center justify-center rounded-ds-md border border-border bg-surface text-text-secondary shadow-sm transition hover:border-primary-300 hover:bg-primary-50 hover:text-primary-700';
$dangerButtonClass = 'inline-flex h-9 w-9 items-center justify-center rounded-ds-md border border-danger/30 bg-surface text-danger shadow-sm transition hover:bg-danger/10 hover:text-danger';
?>
<div class="space-y-6">
  <?= ui_modulo_topo($base, 'cadastros', 'setores', [
      'titulo' => 'Setores',
      'descricao' => 'Gestão de setores com vínculo obrigatório de empresa, filtros e exportações administrativas.',
  ]) ?>
  <div class="flex flex-wrap items-center gap-2">
      <?php if ($canManage): ?>
        <a href="<?= $queryBase . '/novo' ?>" class="<?= ui_btn('primario') ?>">
          Novo setor
        </a>
      <?php endif; ?>
      <a href="<?= $queryBase . '/export?' . http_build_query(array_merge($params, ['format' => 'excel'])) ?>" class="<?= ui_btn('secundario') ?>">
        Exportar Excel
      </a>
      <a href="<?= $queryBase . '/export?' . http_build_query(array_merge($params, ['format' => 'csv'])) ?>" class="<?= ui_btn('secundario') ?>">
        Exportar CSV
      </a>
      <a href="<?= $queryBase . '/export?' . http_build_query(array_merge($params, ['format' => 'pdf'])) ?>" class="<?= ui_btn('secundario') ?>">
        Exportar PDF
      </a>
    </div>

  <?php if (!empty($flashError)): ?>
    <div class="rounded-ds-md border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger"><?= Security::e($flashError) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashSuccess)): ?>
    <div class="rounded-ds-md border border-success/30 bg-success/10 px-4 py-3 text-sm text-success"><?= Security::e($flashSuccess) ?></div>
  <?php endif; ?>

  <?php if ((int)$legacyWithoutEmpresaCount > 0): ?>
    <div class="rounded-ds-lg border border-warning/30 bg-warning/10 p-5 shadow-sm">
      <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
          <h2 class="text-base font-semibold text-warning">Saneamento pendente</h2>
          <p class="mt-1 text-sm text-warning">
            Existem <?= (int)$legacyWithoutEmpresaCount ?> setor(es) legado(s) sem empresa vinculada. O sistema continua compatível, mas novos cadastros e edições exigem empresa válida.
          </p>
        </div>
        <?php if ($canManage): ?>
          <form method="post" action="<?= $queryBase ?>/saneamento/empresa" class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <input type="hidden" name="csrf" value="<?= Security::csrfToken() ?>">
            <div>
              <label class="mb-2 block text-sm font-medium text-warning">Empresa para saneamento</label>
              <select name="empresa_id" required class="min-w-[240px] rounded-ds-md border border-warning/30 bg-surface px-4 py-3 text-sm outline-none transition focus:border-warning focus:ring-2 focus:ring-warning/20">
                <option value="">Selecione</option>
                <?php foreach ($companies as $company): ?>
                  <?php if ((int)($company['ativo'] ?? 0) === 1): ?>
                    <option value="<?= (int)$company['id'] ?>"><?= Security::e($company['nome']) ?></option>
                  <?php endif; ?>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="submit" class="inline-flex items-center justify-center rounded-ds-md bg-warning px-4 py-3 text-sm font-semibold text-white hover:bg-warning/90">
              Sanear legados
            </button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
    <div class="rounded-ds-lg bg-surface p-5 shadow-sm ring-1 ring-border">
      <p class="text-sm font-medium text-text-secondary">Total de setores</p>
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
      <p class="text-sm font-medium text-text-secondary">Sem empresa</p>
      <p class="mt-2 text-3xl font-bold text-warning"><?= (int)($summary['sem_empresa'] ?? 0) ?></p>
    </div>
  </div>

  <div class="rounded-ds-lg bg-surface p-5 shadow-sm ring-1 ring-border">
    <form class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-5" method="get" action="<?= $queryBase ?>">
      <div class="xl:col-span-2">
        <label class="mb-2 block text-sm font-medium text-text-primary">Busca</label>
        <input type="text" name="q" value="<?= Security::e($q) ?>" placeholder="Nome do setor, slug ou empresa" class="w-full rounded-ds-md border border-border px-4 py-3 text-sm outline-none transition focus:border-focus focus:ring-2 focus:ring-primary-100">
      </div>
      <div>
        <label class="mb-2 block text-sm font-medium text-text-primary">Status</label>
        <select name="status" class="w-full rounded-ds-md border border-border px-4 py-3 text-sm outline-none transition focus:border-focus focus:ring-2 focus:ring-primary-100">
          <option value="">Todos</option>
          <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Ativos</option>
          <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inativos</option>
        </select>
      </div>
      <div>
        <label class="mb-2 block text-sm font-medium text-text-primary">Empresa</label>
        <select name="empresa" class="w-full rounded-ds-md border border-border px-4 py-3 text-sm outline-none transition focus:border-focus focus:ring-2 focus:ring-primary-100">
          <option value="">Todas</option>
          <option value="sem_empresa" <?= $empresa === 'sem_empresa' ? 'selected' : '' ?>>Sem empresa</option>
          <?php foreach ($companies as $company): ?>
            <option value="<?= (int)$company['id'] ?>" <?= $empresa === (string)(int)$company['id'] ? 'selected' : '' ?>>
              <?= Security::e($company['nome']) ?><?= (int)($company['ativo'] ?? 1) === 1 ? '' : ' (inativa)' ?>
            </option>
          <?php endforeach; ?>
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
        <h2 class="text-lg font-semibold text-text-primary">Lista de setores</h2>
        <p class="text-sm text-text-secondary">Consulta otimizada com empresa associada, cargos vinculados e uso em colaboradores.</p>
      </div>
      <span class="rounded-full bg-surface-secondary px-3 py-1 text-xs font-semibold text-text-secondary"><?= (int)$total ?> registro(s)</span>
    </div>

    <div class="responsive-table-wrap">
      <table class="hidden min-w-full text-sm md:table">
        <thead class="bg-background">
          <tr class="border-b border-border">
            <th class="p-3 text-left font-medium text-text-secondary">Setor</th>
            <th class="p-3 text-left font-medium text-text-secondary">Empresa</th>
            <th class="p-3 text-left font-medium text-text-secondary">Slug</th>
            <th class="p-3 text-left font-medium text-text-secondary">Status</th>
            <th class="p-3 text-left font-medium text-text-secondary">Cargos</th>
            <th class="p-3 text-left font-medium text-text-secondary">Colaboradores</th>
            <th class="p-3 text-right font-medium text-text-secondary">Ações</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border">
          <?php foreach ($items as $item): ?>
            <?php $isActive = (int)($item['ativo'] ?? 0) === 1; ?>
            <tr class="hover:bg-surface-secondary">
              <td class="p-3 font-medium text-text-primary"><?= Security::e($item['nome']) ?></td>
              <td class="p-3 text-text-secondary">
                <?= !empty($item['empresa_nome']) ? Security::e($item['empresa_nome']) : '<span class="text-warning">Sem empresa</span>' ?>
              </td>
              <td class="p-3 text-text-secondary"><?= Security::e($item['slug']) ?></td>
              <td class="p-3">
                <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= $isActive ? 'bg-success/10 text-success' : 'bg-border text-text-secondary' ?>">
                  <?= $isActive ? 'Ativo' : 'Inativo' ?>
                </span>
              </td>
              <td class="p-3 text-text-secondary"><?= (int)($item['cargos_vinculados'] ?? 0) ?></td>
              <td class="p-3 text-text-secondary"><?= (int)($item['colaboradores_vinculados'] ?? 0) ?></td>
              <td class="p-3">
                <div class="flex justify-end gap-2">
                  <?php if ($canManage): ?>
                    <a href="<?= $queryBase ?>/editar/<?= (int)$item['id'] ?>" class="<?= $actionButtonClass ?>" title="Editar setor" aria-label="Editar setor">
                      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 1 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
                    </a>
                  <?php endif; ?>
                  <?php if ($canDelete): ?>
                    <form action="<?= $queryBase ?>/excluir/<?= (int)$item['id'] ?>" method="post" data-confirm-message="Excluir este setor?">
                      <input type="hidden" name="csrf" value="<?= Security::csrfToken() ?>">
                      <button type="submit" class="<?= $dangerButtonClass ?>" title="Excluir setor" aria-label="Excluir setor">
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
              <td colspan="7" class="p-6 text-center text-text-secondary">Nenhum setor encontrado para os filtros informados.</td>
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
          <div class="mt-1 text-sm text-text-secondary">Empresa: <?= !empty($item['empresa_nome']) ? Security::e($item['empresa_nome']) : 'Sem empresa' ?></div>
          <div class="mt-1 text-sm text-text-secondary">Slug: <?= Security::e($item['slug']) ?></div>
          <div class="mt-1 text-sm text-text-secondary">Cargos: <?= (int)($item['cargos_vinculados'] ?? 0) ?></div>
          <div class="mt-1 text-sm text-text-secondary">Colaboradores: <?= (int)($item['colaboradores_vinculados'] ?? 0) ?></div>
          <div class="mt-3">
            <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= $isActive ? 'bg-success/10 text-success' : 'bg-border text-text-secondary' ?>">
              <?= $isActive ? 'Ativo' : 'Inativo' ?>
            </span>
          </div>
          <div class="mt-4 flex gap-2">
            <?php if ($canManage): ?>
              <a href="<?= $queryBase ?>/editar/<?= (int)$item['id'] ?>" class="<?= $actionButtonClass ?>" title="Editar setor" aria-label="Editar setor">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 1 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
              </a>
            <?php endif; ?>
            <?php if ($canDelete): ?>
              <form action="<?= $queryBase ?>/excluir/<?= (int)$item['id'] ?>" method="post" data-confirm-message="Excluir este setor?">
                <input type="hidden" name="csrf" value="<?= Security::csrfToken() ?>">
                <button type="submit" class="<?= $dangerButtonClass ?>" title="Excluir setor" aria-label="Excluir setor">
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                </button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if (($pages ?? 1) > 1): ?>
      <div class="responsive-pagination mt-6 text-sm">
        <div class="text-text-secondary">Página <?= (int)$page ?> de <?= (int)$pages ?></div>
        <div class="responsive-form-actions">
          <?php
          $prev = max(1, (int)$page - 1);
          $next = min((int)$pages, (int)$page + 1);
          $prevParams = array_merge($params, ['page' => $prev]);
          $nextParams = array_merge($params, ['page' => $next]);
          ?>
          <a href="<?= $queryBase . '?' . http_build_query($prevParams) ?>" class="px-3 py-1 border rounded <?= (int)$page <= 1 ? 'pointer-events-none opacity-50' : 'hover:bg-surface-secondary' ?>">Anterior</a>
          <a href="<?= $queryBase . '?' . http_build_query($nextParams) ?>" class="px-3 py-1 border rounded <?= (int)$page >= (int)$pages ? 'pointer-events-none opacity-50' : 'hover:bg-surface-secondary' ?>">Próxima</a>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>
