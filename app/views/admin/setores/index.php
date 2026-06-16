<?php
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
$actionButtonClass = 'inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 shadow-sm transition hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700';
$dangerButtonClass = 'inline-flex h-9 w-9 items-center justify-center rounded-lg border border-rose-200 bg-white text-rose-600 shadow-sm transition hover:bg-rose-50 hover:text-rose-700';
?>
<div class="space-y-6">
  <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
    <div>
      <h1 class="text-fluid-title font-bold text-gray-800">Setores</h1>
      <p class="text-fluid-subtitle text-gray-600">Gestão de setores com vínculo obrigatório de empresa, filtros e exportações administrativas.</p>
    </div>
    <div class="flex flex-wrap gap-3">
      <?php if ($canManage): ?>
        <a href="<?= $queryBase . '/novo' ?>" class="inline-flex items-center justify-center rounded-xl bg-blue-900 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-blue-950">
          Novo setor
        </a>
      <?php endif; ?>
      <a href="<?= $queryBase . '/export?' . http_build_query(array_merge($params, ['format' => 'excel'])) ?>" class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
        Exportar Excel
      </a>
      <a href="<?= $queryBase . '/export?' . http_build_query(array_merge($params, ['format' => 'csv'])) ?>" class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
        Exportar CSV
      </a>
      <a href="<?= $queryBase . '/export?' . http_build_query(array_merge($params, ['format' => 'pdf'])) ?>" class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
        Exportar PDF
      </a>
    </div>
  </div>

  <?php if (!empty($flashError)): ?>
    <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"><?= Security::e($flashError) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashSuccess)): ?>
    <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700"><?= Security::e($flashSuccess) ?></div>
  <?php endif; ?>

  <?php if ((int)$legacyWithoutEmpresaCount > 0): ?>
    <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 shadow-sm">
      <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
          <h2 class="text-base font-semibold text-amber-900">Saneamento pendente</h2>
          <p class="mt-1 text-sm text-amber-800">
            Existem <?= (int)$legacyWithoutEmpresaCount ?> setor(es) legado(s) sem empresa vinculada. O sistema continua compatível, mas novos cadastros e edições exigem empresa válida.
          </p>
        </div>
        <?php if ($canManage): ?>
          <form method="post" action="<?= $queryBase ?>/saneamento/empresa" class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <input type="hidden" name="csrf" value="<?= Security::csrfToken() ?>">
            <div>
              <label class="mb-2 block text-sm font-medium text-amber-900">Empresa para saneamento</label>
              <select name="empresa_id" required class="min-w-[240px] rounded-xl border border-amber-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-amber-400 focus:ring-2 focus:ring-amber-100">
                <option value="">Selecione</option>
                <?php foreach ($companies as $company): ?>
                  <?php if ((int)($company['ativo'] ?? 0) === 1): ?>
                    <option value="<?= (int)$company['id'] ?>"><?= Security::e($company['nome']) ?></option>
                  <?php endif; ?>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-amber-600 px-4 py-3 text-sm font-semibold text-white hover:bg-amber-700">
              Sanear legados
            </button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <p class="text-sm font-medium text-slate-500">Total de setores</p>
      <p class="mt-2 text-3xl font-bold text-slate-900"><?= (int)($summary['total'] ?? 0) ?></p>
    </div>
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <p class="text-sm font-medium text-slate-500">Ativos</p>
      <p class="mt-2 text-3xl font-bold text-emerald-600"><?= (int)($summary['ativos'] ?? 0) ?></p>
    </div>
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <p class="text-sm font-medium text-slate-500">Inativos</p>
      <p class="mt-2 text-3xl font-bold text-slate-500"><?= (int)($summary['inativos'] ?? 0) ?></p>
    </div>
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <p class="text-sm font-medium text-slate-500">Sem empresa</p>
      <p class="mt-2 text-3xl font-bold text-amber-600"><?= (int)($summary['sem_empresa'] ?? 0) ?></p>
    </div>
  </div>

  <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
    <form class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-5" method="get" action="<?= $queryBase ?>">
      <div class="xl:col-span-2">
        <label class="mb-2 block text-sm font-medium text-slate-700">Busca</label>
        <input type="text" name="q" value="<?= Security::e($q) ?>" placeholder="Nome do setor, slug ou empresa" class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
      </div>
      <div>
        <label class="mb-2 block text-sm font-medium text-slate-700">Status</label>
        <select name="status" class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
          <option value="">Todos</option>
          <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Ativos</option>
          <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inativos</option>
        </select>
      </div>
      <div>
        <label class="mb-2 block text-sm font-medium text-slate-700">Empresa</label>
        <select name="empresa" class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
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
        <button class="inline-flex items-center justify-center rounded-xl bg-blue-900 px-4 py-3 text-sm font-semibold text-white hover:bg-blue-950">Filtrar</button>
        <a href="<?= $queryBase ?>" class="inline-flex items-center justify-center rounded-xl border border-slate-200 px-4 py-3 text-sm font-semibold text-slate-600 hover:bg-slate-50">Limpar</a>
      </div>
    </form>
  </div>

  <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
    <div class="mb-4 flex items-center justify-between">
      <div>
        <h2 class="text-lg font-semibold text-slate-900">Lista de setores</h2>
        <p class="text-sm text-slate-500">Consulta otimizada com empresa associada, cargos vinculados e uso em colaboradores.</p>
      </div>
      <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600"><?= (int)$total ?> registro(s)</span>
    </div>

    <div class="responsive-table-wrap">
      <table class="mobile-table-desktop min-w-full text-sm">
        <thead class="bg-slate-50">
          <tr class="border-b border-slate-200">
            <th class="p-3 text-left font-medium text-slate-500">Setor</th>
            <th class="p-3 text-left font-medium text-slate-500">Empresa</th>
            <th class="p-3 text-left font-medium text-slate-500">Slug</th>
            <th class="p-3 text-left font-medium text-slate-500">Status</th>
            <th class="p-3 text-left font-medium text-slate-500">Cargos</th>
            <th class="p-3 text-left font-medium text-slate-500">Colaboradores</th>
            <th class="p-3 text-right font-medium text-slate-500">Ações</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-200">
          <?php foreach ($items as $item): ?>
            <?php $isActive = (int)($item['ativo'] ?? 0) === 1; ?>
            <tr class="hover:bg-slate-50">
              <td class="p-3 font-medium text-slate-900"><?= Security::e($item['nome']) ?></td>
              <td class="p-3 text-slate-600">
                <?= !empty($item['empresa_nome']) ? Security::e($item['empresa_nome']) : '<span class="text-amber-700">Sem empresa</span>' ?>
              </td>
              <td class="p-3 text-slate-600"><?= Security::e($item['slug']) ?></td>
              <td class="p-3">
                <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= $isActive ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600' ?>">
                  <?= $isActive ? 'Ativo' : 'Inativo' ?>
                </span>
              </td>
              <td class="p-3 text-slate-600"><?= (int)($item['cargos_vinculados'] ?? 0) ?></td>
              <td class="p-3 text-slate-600"><?= (int)($item['colaboradores_vinculados'] ?? 0) ?></td>
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
              <td colspan="7" class="p-6 text-center text-slate-500">Nenhum setor encontrado para os filtros informados.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="responsive-card-list mt-4 md:hidden">
      <?php foreach ($items as $item): ?>
        <?php $isActive = (int)($item['ativo'] ?? 0) === 1; ?>
        <div class="responsive-card">
          <div class="text-base font-semibold text-slate-900"><?= Security::e($item['nome']) ?></div>
          <div class="mt-1 text-sm text-slate-600">Empresa: <?= !empty($item['empresa_nome']) ? Security::e($item['empresa_nome']) : 'Sem empresa' ?></div>
          <div class="mt-1 text-sm text-slate-600">Slug: <?= Security::e($item['slug']) ?></div>
          <div class="mt-1 text-sm text-slate-600">Cargos: <?= (int)($item['cargos_vinculados'] ?? 0) ?></div>
          <div class="mt-1 text-sm text-slate-600">Colaboradores: <?= (int)($item['colaboradores_vinculados'] ?? 0) ?></div>
          <div class="mt-3">
            <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= $isActive ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600' ?>">
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
        <div class="text-gray-500">Página <?= (int)$page ?> de <?= (int)$pages ?></div>
        <div class="responsive-form-actions">
          <?php
          $prev = max(1, (int)$page - 1);
          $next = min((int)$pages, (int)$page + 1);
          $prevParams = array_merge($params, ['page' => $prev]);
          $nextParams = array_merge($params, ['page' => $next]);
          ?>
          <a href="<?= $queryBase . '?' . http_build_query($prevParams) ?>" class="px-3 py-1 border rounded <?= (int)$page <= 1 ? 'pointer-events-none opacity-50' : 'hover:bg-gray-50' ?>">Anterior</a>
          <a href="<?= $queryBase . '?' . http_build_query($nextParams) ?>" class="px-3 py-1 border rounded <?= (int)$page >= (int)$pages ? 'pointer-events-none opacity-50' : 'hover:bg-gray-50' ?>">Próxima</a>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>
