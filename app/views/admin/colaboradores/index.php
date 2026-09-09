<?php
$queryBase = $base . '/admin/colaboradores';
$q = (string)($filters['q'] ?? '');
$empresaSel = (string)($filters['empresa'] ?? '');
$setorSel = (string)($filters['setor'] ?? '');
$cargoSel = (string)($filters['cargo'] ?? '');
$situacao = (string)($filters['situacao'] ?? '');
$page = max(1, (int)($page ?? 1));
$pages = max(1, (int)($pages ?? 1));
$perPage = in_array((int)($perPage ?? 20), [20, 50, 100], true) ? (int)$perPage : 20;
$total = (int)($total ?? count($colaboradores));
$empresaOpcoes = $opcoesFiltro['empresas'] ?? [];
$setorOpcoes = $opcoesFiltro['setores'] ?? [];
$cargoOpcoes = $opcoesFiltro['cargos'] ?? [];
$filterParams = [
  'q' => $q,
  'empresa' => $empresaSel,
  'setor' => $setorSel,
  'cargo' => $cargoSel,
  'situacao' => $situacao,
  'per_page' => $perPage,
];
$actionButtonClass = 'inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 shadow-sm transition hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700';
$toolbarIconButtonClass = 'group relative inline-flex h-11 w-11 items-center justify-center rounded-2xl border border-slate-200 bg-white text-slate-500 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:border-slate-300 hover:bg-slate-50 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-100 focus:ring-offset-2';
$toolbarMenuLinkClass = 'flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50 hover:text-slate-900';
$pageWindowStart = max(1, $page - 2);
$pageWindowEnd = min($pages, $page + 2);
if (($pageWindowEnd - $pageWindowStart) < 4) {
  $pageWindowStart = max(1, $pageWindowEnd - 4);
  $pageWindowEnd = min($pages, $pageWindowStart + 4);
}

// CPF mascarado para a listagem administrativa: expõe só os 6 dígitos centrais, o suficiente
// para diferenciar homônimos sem revelar o documento completo. Nunca vai para log.
$mascararCpf = static function (?string $cpf): string {
  $digitos = preg_replace('/\D/', '', (string)$cpf);
  if (strlen((string)$digitos) !== 11) {
    return 'Não informado';
  }
  return '***.' . substr($digitos, 3, 3) . '.' . substr($digitos, 6, 3) . '-**';
};

$formatarData = static function ($valor): string {
  return !empty($valor) ? DateHelper::formatBrazilianDate((string)$valor) : 'Não informada';
};
$formatarSalario = static function ($valor): string {
  return ($valor !== null && $valor !== '' && (float)$valor > 0)
    ? 'R$ ' . number_format((float)$valor, 2, ',', '.')
    : 'Não informado';
};
?>
<div class="space-y-6">
  <div class="flex flex-col gap-3">
    <div>
      <h1 class="text-fluid-title font-bold text-gray-800">Colaboradores</h1>
      <p class="text-fluid-subtitle text-gray-600">Base cadastral oficial sincronizada com o sistema de RH da Madeplant.</p>
    </div>
  </div>

  <?php if (!empty($erro)): ?>
    <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 shadow-sm"><?= Security::e($erro) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashError)): ?>
    <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 shadow-sm"><?= Security::e($flashError) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashSuccess)): ?>
    <div class="rounded-2xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 shadow-sm"><?= Security::e($flashSuccess) ?></div>
  <?php endif; ?>

  <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <p class="text-sm font-medium text-slate-500">Contratos no histórico</p>
      <p class="mt-2 text-3xl font-bold text-slate-900"><?= (int)($summary['contratos'] ?? 0) ?></p>
    </div>
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <p class="text-sm font-medium text-slate-500">Colaboradores ativos</p>
      <p class="mt-2 text-3xl font-bold text-emerald-600"><?= (int)($summary['ativos'] ?? 0) ?></p>
    </div>
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <p class="text-sm font-medium text-slate-500">Colaboradores desligados</p>
      <p class="mt-2 text-3xl font-bold text-slate-600"><?= (int)($summary['desligados'] ?? 0) ?></p>
    </div>
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <p class="text-sm font-medium text-slate-500">Empresas</p>
      <p class="mt-2 text-3xl font-bold text-sky-600"><?= (int)($summary['empresas'] ?? 0) ?></p>
    </div>
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <p class="text-sm font-medium text-slate-500">Cargos distintos</p>
      <p class="mt-2 text-3xl font-bold text-amber-600"><?= (int)($summary['cargos_distintos'] ?? 0) ?></p>
    </div>
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <p class="text-sm font-medium text-slate-500">Pessoas distintas</p>
      <p class="mt-2 text-3xl font-bold text-violet-600"><?= (int)($summary['pessoas_distintas'] ?? 0) ?></p>
    </div>
  </div>

  <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
    <form class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-5" method="get" action="<?= $queryBase ?>">
      <input type="hidden" name="page" value="1">
      <div class="xl:col-span-2">
        <label class="mb-2 block text-sm font-medium text-slate-700">Busca</label>
        <input type="text" name="q" value="<?= Security::e($q) ?>" placeholder="Nome, cargo, empresa ou setor" class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
      </div>
      <div>
        <label class="mb-2 block text-sm font-medium text-slate-700">Empresa</label>
        <select name="empresa" class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
          <option value="">Todas</option>
          <?php foreach ($empresaOpcoes as $item): ?>
            <?php $codigo = (string)($item['codigo_empresa'] ?? ''); ?>
            <option value="<?= Security::e($codigo) ?>" <?= $empresaSel === $codigo ? 'selected' : '' ?>><?= Security::e((string)($item['empresa'] ?? $codigo)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="mb-2 block text-sm font-medium text-slate-700">Setor</label>
        <select name="setor" class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
          <option value="">Todos</option>
          <?php foreach ($setorOpcoes as $item): ?>
            <option value="<?= Security::e((string)$item) ?>" <?= $setorSel === (string)$item ? 'selected' : '' ?>><?= Security::e((string)$item) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="mb-2 block text-sm font-medium text-slate-700">Cargo</label>
        <select name="cargo" class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
          <option value="">Todos</option>
          <?php foreach ($cargoOpcoes as $item): ?>
            <option value="<?= Security::e((string)$item) ?>" <?= $cargoSel === (string)$item ? 'selected' : '' ?>><?= Security::e((string)$item) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="mb-2 block text-sm font-medium text-slate-700">Situação</label>
        <select name="situacao" class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
          <option value="">Todos</option>
          <option value="ativos" <?= $situacao === 'ativos' ? 'selected' : '' ?>>Ativos</option>
          <option value="desligados" <?= $situacao === 'desligados' ? 'selected' : '' ?>>Desligados</option>
        </select>
      </div>
      <div>
        <label class="mb-2 block text-sm font-medium text-slate-700">Registros por página</label>
        <select name="per_page" class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100" data-colaboradores-per-page="1" onchange="this.form.requestSubmit()">
          <?php foreach ([20, 50, 100] as $perPageOption): ?>
            <option value="<?= $perPageOption ?>" <?= $perPage === $perPageOption ? 'selected' : '' ?>><?= $perPageOption ?> registros</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="md:col-span-2 xl:col-span-5 flex flex-wrap items-end gap-3">
        <button class="inline-flex items-center justify-center rounded-xl bg-blue-900 px-4 py-3 text-sm font-semibold text-white hover:bg-blue-950">Filtrar</button>
        <a href="<?= $queryBase ?>" class="inline-flex items-center justify-center rounded-xl border border-slate-200 px-4 py-3 text-sm font-semibold text-slate-600 hover:bg-slate-50">Limpar</a>
      </div>
    </form>

    <div class="mt-6 flex items-end justify-between gap-4 border-t border-dashed border-slate-200 pt-6">
      <div class="flex flex-wrap items-center gap-3">
        <div class="hidden items-center gap-3 md:flex">
          <a href="<?= $base ?>/admin/empresas" class="<?= $toolbarIconButtonClass ?>" title="Empresas" aria-label="Empresas">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 21h18"/><path d="M6 21V6l6-3 6 3v15"/><path d="M9 9h.01"/><path d="M15 9h.01"/><path d="M9 13h.01"/><path d="M15 13h.01"/></svg>
          </a>
          <a href="<?= $base ?>/admin/setores" class="<?= $toolbarIconButtonClass ?>" title="Setores" aria-label="Setores">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 21V8l8-4 8 4v13"/><path d="M9 21v-7h6v7"/><path d="M9 11h6"/></svg>
          </a>
          <a href="<?= $base ?>/admin/cargos" class="<?= $toolbarIconButtonClass ?>" title="Cargos" aria-label="Cargos">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 7V5a4 4 0 0 1 8 0v2"/><path d="M4 9h16v9a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V9Z"/><path d="M10 13h4"/></svg>
          </a>
          <a href="<?= $base ?>/admin/avaliacoes" class="<?= $toolbarIconButtonClass ?>" title="Avaliações" aria-label="Avaliações">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m8.5 12.5 2.5 2.5 4.5-5"/><path d="M20 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h9"/></svg>
          </a>
        </div>
        <details class="relative md:hidden">
          <summary class="<?= $toolbarIconButtonClass ?> list-none cursor-pointer" title="Mais ações" aria-label="Mais ações">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg>
          </summary>
          <div class="absolute left-0 z-10 mt-2 w-56 rounded-2xl border border-slate-200 bg-white p-2 shadow-lg">
            <a href="<?= $base ?>/admin/empresas" class="<?= $toolbarMenuLinkClass ?>">
              <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 21h18"/><path d="M6 21V6l6-3 6 3v15"/></svg>
              <span>Empresas</span>
            </a>
            <a href="<?= $base ?>/admin/setores" class="<?= $toolbarMenuLinkClass ?>">
              <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 21V8l8-4 8 4v13"/><path d="M9 21v-7h6v7"/></svg>
              <span>Setores</span>
            </a>
            <a href="<?= $base ?>/admin/cargos" class="<?= $toolbarMenuLinkClass ?>">
              <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 7V5a4 4 0 0 1 8 0v2"/><path d="M4 9h16v9a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V9Z"/></svg>
              <span>Cargos</span>
            </a>
            <a href="<?= $base ?>/admin/avaliacoes" class="<?= $toolbarMenuLinkClass ?>">
              <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m8.5 12.5 2.5 2.5 4.5-5"/><path d="M20 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h9"/></svg>
              <span>Avaliações</span>
            </a>
          </div>
        </details>
      </div>
    </div>
  </div>

  <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
    <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
      <div>
        <h2 class="text-lg font-semibold text-slate-900">Lista de Colaboradores</h2>
        <p class="text-sm text-slate-500">Base cadastral oficial sincronizada com o sistema de RH da Madeplant.</p>
      </div>
      <div class="flex flex-wrap items-center gap-2 text-xs font-semibold">
        <span class="rounded-full bg-slate-100 px-3 py-1 text-slate-600"><?= $total ?> contrato(s)</span>
        <span class="rounded-full bg-blue-50 px-3 py-1 text-blue-700">Página <?= $page ?> de <?= $pages ?></span>
        <span class="rounded-full bg-slate-50 px-3 py-1 text-slate-500">Exibindo <?= count($colaboradores) ?> nesta página</span>
      </div>
    </div>

    <div class="responsive-table-wrap">
      <table class="mobile-table-desktop min-w-full text-sm">
        <thead class="bg-slate-50">
          <tr class="border-b border-slate-200">
            <th class="p-3 text-left font-medium text-slate-500">Nome</th>
            <th class="p-3 text-left font-medium text-slate-500">Cargo</th>
            <th class="p-3 text-left font-medium text-slate-500">Empresa</th>
            <th class="p-3 text-left font-medium text-slate-500">Setor</th>
            <th class="p-3 text-left font-medium text-slate-500">Contrato</th>
            <th class="p-3 text-left font-medium text-slate-500">Admissão</th>
            <th class="p-3 text-left font-medium text-slate-500">Nascimento</th>
            <th class="p-3 text-left font-medium text-slate-500">Demissão</th>
            <th class="p-3 text-left font-medium text-slate-500">Motivo rescisão</th>
            <th class="p-3 text-left font-medium text-slate-500">CPF</th>
            <th class="p-3 text-left font-medium text-slate-500">Salário</th>
            <th class="p-3 text-left font-medium text-slate-500">Situação</th>
            <th class="p-3 text-right font-medium text-slate-500">Ações</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-200">
          <?php foreach ($colaboradores as $colaborador): ?>
            <?php
              $isActive = (int)($colaborador['ativo'] ?? 0) === 1;
              $localId = ctype_digit((string)($colaborador['local_id'] ?? '')) ? (int)$colaborador['local_id'] : null;
            ?>
            <tr class="hover:bg-slate-50">
              <td class="p-3 font-medium text-slate-900"><?= Security::e($colaborador['nome']) ?></td>
              <td class="p-3 text-slate-700"><?= Security::e($colaborador['cargo'] ?: 'Não informado') ?></td>
              <td class="p-3 text-slate-700"><?= Security::e($colaborador['empresa'] ?: 'Não informada') ?></td>
              <td class="p-3 text-slate-700"><?= Security::e($colaborador['setor'] ?: 'Não informado') ?></td>
              <td class="p-3 text-slate-700"><?= Security::e($colaborador['numero_contrato'] ?: 'Não informado') ?></td>
              <td class="p-3 text-slate-700"><?= Security::e($formatarData($colaborador['admissao'] ?? null)) ?></td>
              <td class="p-3 text-slate-700"><?= Security::e($formatarData($colaborador['nascimento'] ?? null)) ?></td>
              <td class="p-3 text-slate-700"><?= Security::e($formatarData($colaborador['demissao'] ?? null)) ?></td>
              <td class="p-3 text-slate-700"><?= Security::e($colaborador['motivo_rescisao_descricao'] ?: 'Não informado') ?></td>
              <td class="p-3 text-slate-700"><?= Security::e($mascararCpf($colaborador['cpf'] ?? null)) ?></td>
              <td class="p-3 text-slate-700"><?= Security::e($formatarSalario($colaborador['salario_atual'] ?? null)) ?></td>
              <td class="p-3">
                <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= $isActive ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600' ?>">
                  <?= $isActive ? 'Ativo' : 'Desligado' ?>
                </span>
              </td>
              <td class="p-3">
                <div class="flex items-center justify-end gap-2">
                  <?php if ($localId !== null): ?>
                    <a href="<?= $base ?>/admin/colaboradores/<?= $localId ?>/acesso" class="<?= $actionButtonClass ?>" title="Acesso e liderança" aria-label="Acesso e liderança">
                      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6"/><path d="M22 11h-6"/></svg>
                    </a>
                    <a href="<?= $base ?>/admin/colaboradores/rh/editar/<?= $localId ?>" class="<?= $actionButtonClass ?>" title="Editar dados RH" aria-label="Editar dados RH">
                      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
                    </a>
                    <a href="<?= $base ?>/admin/avaliacoes?colaborador_id=<?= $localId ?>" class="<?= $actionButtonClass ?>" title="Ver avaliações" aria-label="Ver avaliações">
                      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                    </a>
                  <?php else: ?>
                    <span class="rounded-full bg-slate-50 px-3 py-1 text-xs font-medium text-slate-400" title="Este contrato ainda não tem extensão local em colaboradores; ações que dependem dela ficam indisponíveis.">Sem extensão local</span>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($colaboradores)): ?>
            <tr>
              <td colspan="13" class="p-6 text-center text-slate-500">Nenhum colaborador encontrado para os filtros informados.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="responsive-card-list mt-4 md:hidden">
      <?php foreach ($colaboradores as $colaborador): ?>
        <?php
          $isActive = (int)($colaborador['ativo'] ?? 0) === 1;
          $localId = ctype_digit((string)($colaborador['local_id'] ?? '')) ? (int)$colaborador['local_id'] : null;
        ?>
        <div class="responsive-card">
          <div class="text-base font-semibold text-slate-900"><?= Security::e($colaborador['nome']) ?></div>
          <div class="mt-2 text-sm text-slate-600">Cargo: <?= Security::e($colaborador['cargo'] ?: 'Não informado') ?></div>
          <div class="mt-1 text-sm text-slate-600">Empresa: <?= Security::e($colaborador['empresa'] ?: 'Não informada') ?></div>
          <div class="mt-1 text-sm text-slate-600">Setor: <?= Security::e($colaborador['setor'] ?: 'Não informado') ?></div>
          <div class="mt-1 text-sm text-slate-600">Contrato: <?= Security::e($colaborador['numero_contrato'] ?: 'Não informado') ?></div>
          <div class="mt-1 text-sm text-slate-600">Admissão: <?= Security::e($formatarData($colaborador['admissao'] ?? null)) ?></div>
          <div class="mt-1 text-sm text-slate-600">Nascimento: <?= Security::e($formatarData($colaborador['nascimento'] ?? null)) ?></div>
          <div class="mt-1 text-sm text-slate-600">Demissão: <?= Security::e($formatarData($colaborador['demissao'] ?? null)) ?></div>
          <div class="mt-1 text-sm text-slate-600">Motivo rescisão: <?= Security::e($colaborador['motivo_rescisao_descricao'] ?: 'Não informado') ?></div>
          <div class="mt-1 text-sm text-slate-600">CPF: <?= Security::e($mascararCpf($colaborador['cpf'] ?? null)) ?></div>
          <div class="mt-1 text-sm text-slate-600">Salário: <?= Security::e($formatarSalario($colaborador['salario_atual'] ?? null)) ?></div>
          <div class="mt-3">
            <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= $isActive ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600' ?>">
              <?= $isActive ? 'Ativo' : 'Desligado' ?>
            </span>
          </div>
          <div class="mt-4 flex gap-2">
            <?php if ($localId !== null): ?>
              <a href="<?= $base ?>/admin/colaboradores/<?= $localId ?>/acesso" class="<?= $actionButtonClass ?>" title="Acesso e liderança" aria-label="Acesso e liderança">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6"/><path d="M22 11h-6"/></svg>
              </a>
              <a href="<?= $base ?>/admin/colaboradores/rh/editar/<?= $localId ?>" class="<?= $actionButtonClass ?>" title="Editar dados RH" aria-label="Editar dados RH">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
              </a>
              <a href="<?= $base ?>/admin/avaliacoes?colaborador_id=<?= $localId ?>" class="<?= $actionButtonClass ?>" title="Ver avaliações" aria-label="Ver avaliações">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
              </a>
            <?php else: ?>
              <span class="rounded-full bg-slate-50 px-3 py-1 text-xs font-medium text-slate-400">Sem extensão local</span>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($pages > 1): ?>
      <?php
      $prevPage = max(1, $page - 1);
      $nextPage = min($pages, $page + 1);
      $prevParams = array_merge($filterParams, ['page' => $prevPage]);
      $nextParams = array_merge($filterParams, ['page' => $nextPage]);
      ?>
      <div class="mt-6 flex flex-col gap-3 border-t border-dashed border-slate-200 pt-4 md:flex-row md:items-center md:justify-between">
        <div class="text-sm text-slate-500">
          Exibindo página <?= $page ?> de <?= $pages ?> com <?= $perPage ?> registro(s) por página.
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <a href="<?= $queryBase . '?' . http_build_query($prevParams) ?>" class="inline-flex min-h-[42px] items-center justify-center rounded-xl border border-slate-200 px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50 <?= $page <= 1 ? 'pointer-events-none opacity-50' : '' ?>">Anterior</a>
          <?php for ($pageNumber = $pageWindowStart; $pageNumber <= $pageWindowEnd; $pageNumber++): ?>
            <?php $numberParams = array_merge($filterParams, ['page' => $pageNumber]); ?>
            <a href="<?= $queryBase . '?' . http_build_query($numberParams) ?>" class="inline-flex min-h-[42px] min-w-[42px] items-center justify-center rounded-xl border px-3 py-2 text-sm font-semibold transition <?= $pageNumber === $page ? 'border-blue-900 bg-blue-900 text-white' : 'border-slate-200 text-slate-600 hover:bg-slate-50' ?>">
              <?= $pageNumber ?>
            </a>
          <?php endfor; ?>
          <a href="<?= $queryBase . '?' . http_build_query($nextParams) ?>" class="inline-flex min-h-[42px] items-center justify-center rounded-xl border border-slate-200 px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50 <?= $page >= $pages ? 'pointer-events-none opacity-50' : '' ?>">Próxima</a>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>
