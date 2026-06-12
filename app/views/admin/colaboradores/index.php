<?php
$queryBase = $base . '/admin/colaboradores';
$q = (string)($filters['q'] ?? '');
$cargoId = (int)($filters['cargo_id'] ?? 0);
$empresaId = (int)($filters['empresa_id'] ?? 0);
$setorId = (int)($filters['setor_id'] ?? 0);
$status = (string)($filters['status'] ?? '');
$actionButtonClass = 'inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 shadow-sm transition hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700';
?>
<div class="space-y-6">
  <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
    <div>
      <h1 class="text-fluid-title font-bold text-gray-800">Colaboradores</h1>
      <p class="text-fluid-subtitle text-gray-600">Base cadastral proveniente do banco de dados do RH Madeplant.</p>
    </div>
    <div class="flex flex-wrap gap-3">
      <a href="<?= $base ?>/admin/empresas" class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
        Empresas
      </a>
      <a href="<?= $base ?>/admin/setores" class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
        Setores
      </a>
      <a href="<?= $base ?>/admin/cargos" class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
        Cargos
      </a>
      <a href="<?= $base ?>/admin/avaliacoes" class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
        Avaliações
      </a>
      <a href="<?= $base ?>/admin" class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
        Dashboard
      </a>
    </div>
  </div>

  <?php if (!empty($flashError)): ?>
    <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 shadow-sm"><?= Security::e($flashError) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashSuccess)): ?>
    <div class="rounded-2xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 shadow-sm"><?= Security::e($flashSuccess) ?></div>
  <?php endif; ?>

  <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-5">
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <p class="text-sm font-medium text-slate-500">Total de colaboradores</p>
      <p class="mt-2 text-3xl font-bold text-slate-900"><?= (int)($summary['total'] ?? 0) ?></p>
    </div>
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <p class="text-sm font-medium text-slate-500">Registros ativos</p>
      <p class="mt-2 text-3xl font-bold text-emerald-600"><?= (int)($summary['ativos'] ?? 0) ?></p>
    </div>
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <p class="text-sm font-medium text-slate-500">Com empresa vinculada</p>
      <p class="mt-2 text-3xl font-bold text-sky-600"><?= (int)($summary['com_empresa'] ?? 0) ?></p>
    </div>
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <p class="text-sm font-medium text-slate-500">Com setor vinculado</p>
      <p class="mt-2 text-3xl font-bold text-violet-600"><?= (int)($summary['com_setor'] ?? 0) ?></p>
    </div>
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <p class="text-sm font-medium text-slate-500">Cargos distintos</p>
      <p class="mt-2 text-3xl font-bold text-amber-600"><?= (int)($summary['cargos_distintos'] ?? 0) ?></p>
    </div>
  </div>

  <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
    <form class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-5" method="get" action="<?= $queryBase ?>">
      <div class="xl:col-span-2">
        <label class="mb-2 block text-sm font-medium text-slate-700">Busca</label>
        <input type="text" name="q" value="<?= Security::e($q) ?>" placeholder="Nome, cargo, empresa ou setor" class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
      </div>
      <div>
        <label class="mb-2 block text-sm font-medium text-slate-700">Cargo</label>
        <select name="cargo_id" class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
          <option value="">Todos</option>
          <?php foreach ($cargoOptions as $item): ?>
            <option value="<?= (int)$item['id'] ?>" <?= $cargoId === (int)$item['id'] ? 'selected' : '' ?>><?= Security::e($item['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="mb-2 block text-sm font-medium text-slate-700">Empresa</label>
        <select name="empresa_id" class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
          <option value="">Todas</option>
          <?php foreach ($empresaOptions as $item): ?>
            <option value="<?= (int)$item['id'] ?>" <?= $empresaId === (int)$item['id'] ? 'selected' : '' ?>><?= Security::e($item['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="mb-2 block text-sm font-medium text-slate-700">Setor</label>
        <select name="setor_id" class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
          <option value="">Todos</option>
          <?php foreach ($setorOptions as $item): ?>
            <option value="<?= (int)$item['id'] ?>" <?= $setorId === (int)$item['id'] ? 'selected' : '' ?>><?= Security::e($item['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="mb-2 block text-sm font-medium text-slate-700">Vinculação</label>
        <select name="status" class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
          <option value="">Todos</option>
          <option value="vinculados" <?= $status === 'vinculados' ? 'selected' : '' ?>>Com empresa e setor</option>
          <option value="pendentes" <?= $status === 'pendentes' ? 'selected' : '' ?>>Pendentes de vínculo</option>
        </select>
      </div>
      <div class="md:col-span-2 xl:col-span-5 flex flex-wrap gap-3">
        <button class="inline-flex items-center justify-center rounded-xl bg-blue-900 px-4 py-3 text-sm font-semibold text-white hover:bg-blue-950">Filtrar</button>
        <a href="<?= $queryBase ?>" class="inline-flex items-center justify-center rounded-xl border border-slate-200 px-4 py-3 text-sm font-semibold text-slate-600 hover:bg-slate-50">Limpar</a>
      </div>
    </form>
  </div>

  <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
    <div class="mb-4 flex items-center justify-between">
      <div>
        <h2 class="text-lg font-semibold text-slate-900">Lista de Colaboradores</h2>
        <p class="text-sm text-slate-500">Informações reais carregadas da tabela `colaboradores`.</p>
      </div>
      <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600"><?= count($colaboradores) ?> registro(s)</span>
    </div>

    <div class="responsive-table-wrap">
      <table class="mobile-table-desktop min-w-full text-sm">
        <thead class="bg-slate-50">
          <tr class="border-b border-slate-200">
            <th class="p-3 text-left font-medium text-slate-500">Nome</th>
            <th class="p-3 text-left font-medium text-slate-500">Cargo</th>
            <th class="p-3 text-left font-medium text-slate-500">Empresa</th>
            <th class="p-3 text-left font-medium text-slate-500">Setor</th>
            <th class="p-3 text-left font-medium text-slate-500">Matrícula</th>
            <th class="p-3 text-left font-medium text-slate-500">Salário</th>
            <th class="p-3 text-left font-medium text-slate-500">Status</th>
            <th class="p-3 text-right font-medium text-slate-500">Ações</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-200">
          <?php foreach ($colaboradores as $colaborador): ?>
            <tr class="hover:bg-slate-50">
              <td class="p-3 font-medium text-slate-900"><?= Security::e($colaborador['nome']) ?></td>
              <td class="p-3 text-slate-700"><?= Security::e($colaborador['cargo_nome']) ?></td>
              <td class="p-3 text-slate-700"><?= Security::e($colaborador['empresa_nome'] ?: 'Não vinculado') ?></td>
              <td class="p-3 text-slate-700"><?= Security::e($colaborador['setor_nome'] ?: 'Não vinculado') ?></td>
              <td class="p-3 text-slate-700"><?= Security::e($colaborador['matricula'] ?: 'Não informada') ?></td>
              <td class="p-3 text-slate-700"><?= !empty($colaborador['salario_atual']) ? 'R$ ' . Security::e(number_format((float)$colaborador['salario_atual'], 2, ',', '.')) : 'Não informado' ?></td>
              <td class="p-3">
                <?php $isActive = (int)($colaborador['ativo'] ?? 0) === 1; ?>
                <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= $isActive ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600' ?>">
                  <?= $isActive ? 'Ativo' : 'Inativo' ?>
                </span>
              </td>
              <td class="p-3">
                <div class="flex justify-end gap-2">
                  <a href="<?= $base ?>/admin/colaboradores/rh/editar/<?= (int)$colaborador['id'] ?>" class="<?= $actionButtonClass ?>" title="Editar dados RH" aria-label="Editar dados RH">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
                  </a>
                  <a href="<?= $base ?>/admin/avaliacoes?colaborador_id=<?= (int)$colaborador['id'] ?>" class="<?= $actionButtonClass ?>" title="Ver avaliações" aria-label="Ver avaliações">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                  </a>
                  <?php if (!empty($colaborador['empresa_id'])): ?>
                    <a href="<?= $base ?>/admin/empresas/editar/<?= (int)$colaborador['empresa_id'] ?>" class="<?= $actionButtonClass ?>" title="Gerenciar empresa" aria-label="Gerenciar empresa">
                      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 9h.01"/><path d="M15 9h.01"/><path d="M9 13h.01"/><path d="M15 13h.01"/></svg>
                    </a>
                  <?php endif; ?>
                  <?php if (!empty($colaborador['setor_id'])): ?>
                    <a href="<?= $base ?>/admin/setores/editar/<?= (int)$colaborador['setor_id'] ?>" class="<?= $actionButtonClass ?>" title="Gerenciar setor" aria-label="Gerenciar setor">
                      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 21V7l8-4 8 4v14"/><path d="M9 21V9h6v12"/><path d="M9 13h6"/></svg>
                    </a>
                  <?php endif; ?>
                  <?php if (!empty($colaborador['cargo_id'])): ?>
                    <a href="<?= $base ?>/admin/cargos/editar/<?= (int)$colaborador['cargo_id'] ?>" class="<?= $actionButtonClass ?>" title="Gerenciar cargo" aria-label="Gerenciar cargo">
                      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 6V4a2 2 0 0 0-2-2 2 2 0 0 0-2 2v2"/><path d="M4 8h16v11a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8z"/><path d="M10 12h4"/></svg>
                    </a>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($colaboradores)): ?>
            <tr>
              <td colspan="8" class="p-6 text-center text-slate-500">Nenhum colaborador encontrado para os filtros informados.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="responsive-card-list mt-4 md:hidden">
      <?php foreach ($colaboradores as $colaborador): ?>
        <?php $isActive = (int)($colaborador['ativo'] ?? 0) === 1; ?>
        <div class="responsive-card">
          <div class="text-base font-semibold text-slate-900"><?= Security::e($colaborador['nome']) ?></div>
          <div class="mt-2 text-sm text-slate-600">Cargo: <?= Security::e($colaborador['cargo_nome']) ?></div>
          <div class="mt-1 text-sm text-slate-600">Empresa: <?= Security::e($colaborador['empresa_nome'] ?: 'Não vinculado') ?></div>
          <div class="mt-1 text-sm text-slate-600">Setor: <?= Security::e($colaborador['setor_nome'] ?: 'Não vinculado') ?></div>
          <div class="mt-1 text-sm text-slate-600">Matrícula: <?= Security::e($colaborador['matricula'] ?: 'Não informada') ?></div>
          <div class="mt-1 text-sm text-slate-600">Salário: <?= !empty($colaborador['salario_atual']) ? 'R$ ' . Security::e(number_format((float)$colaborador['salario_atual'], 2, ',', '.')) : 'Não informado' ?></div>
          <div class="mt-3">
            <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= $isActive ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600' ?>">
              <?= $isActive ? 'Ativo' : 'Inativo' ?>
            </span>
          </div>
          <div class="mt-4 flex gap-2">
            <a href="<?= $base ?>/admin/colaboradores/rh/editar/<?= (int)$colaborador['id'] ?>" class="<?= $actionButtonClass ?>" title="Editar dados RH" aria-label="Editar dados RH">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
            </a>
            <a href="<?= $base ?>/admin/avaliacoes?colaborador_id=<?= (int)$colaborador['id'] ?>" class="<?= $actionButtonClass ?>" title="Ver avaliações" aria-label="Ver avaliações">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
            </a>
            <?php if (!empty($colaborador['empresa_id'])): ?>
              <a href="<?= $base ?>/admin/empresas/editar/<?= (int)$colaborador['empresa_id'] ?>" class="<?= $actionButtonClass ?>" title="Gerenciar empresa" aria-label="Gerenciar empresa">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 9h.01"/><path d="M15 9h.01"/><path d="M9 13h.01"/><path d="M15 13h.01"/></svg>
              </a>
            <?php endif; ?>
            <?php if (!empty($colaborador['setor_id'])): ?>
              <a href="<?= $base ?>/admin/setores/editar/<?= (int)$colaborador['setor_id'] ?>" class="<?= $actionButtonClass ?>" title="Gerenciar setor" aria-label="Gerenciar setor">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 21V7l8-4 8 4v14"/><path d="M9 21V9h6v12"/><path d="M9 13h6"/></svg>
              </a>
            <?php endif; ?>
            <?php if (!empty($colaborador['cargo_id'])): ?>
              <a href="<?= $base ?>/admin/cargos/editar/<?= (int)$colaborador['cargo_id'] ?>" class="<?= $actionButtonClass ?>" title="Gerenciar cargo" aria-label="Gerenciar cargo">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 6V4a2 2 0 0 0-2-2 2 2 0 0 0-2 2v2"/><path d="M4 8h16v11a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8z"/><path d="M10 12h4"/></svg>
              </a>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
