<?php
$queryBase = $base . '/admin/avaliacoes';
$q = (string)($filters['q'] ?? '');
$colaboradorId = (int)($filters['colaborador_id'] ?? 0);
?>
<div class="responsive-panel">
  <div class="responsive-header">
    <div>
      <h2 class="text-xl font-semibold text-ctpblue">Avaliações de desempenho</h2>
      <p class="mt-1 text-sm text-gray-500">Cadastre e mantenha as avaliações que alimentam os fluxos de movimentação de pessoal.</p>
    </div>
    <a href="<?= $base ?>/admin/avaliacoes/novo" class="inline-flex items-center justify-center rounded-lg bg-ctgreen px-4 py-3 text-sm font-medium text-white hover:bg-ctdark">Nova avaliação</a>
  </div>

  <?php if (!empty($flashError)): ?>
    <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= Security::e($flashError) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashSuccess)): ?>
    <div class="mt-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700"><?= Security::e($flashSuccess) ?></div>
  <?php endif; ?>

  <div class="mt-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
    <form class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4" method="get" action="<?= $queryBase ?>">
      <div class="xl:col-span-2">
        <label class="mb-2 block text-sm font-medium text-slate-700">Busca</label>
        <input type="text" name="q" value="<?= Security::e($q) ?>" placeholder="Colaborador, título ou período" class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
      </div>
      <div>
        <label class="mb-2 block text-sm font-medium text-slate-700">Colaborador</label>
        <select name="colaborador_id" class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
          <option value="">Todos</option>
          <?php foreach ($colaboradorOptions as $item): ?>
            <option value="<?= (int)$item['id'] ?>" <?= $colaboradorId === (int)$item['id'] ? 'selected' : '' ?>>
              <?= Security::e($item['nome'] . (!empty($item['matricula']) ? ' - ' . $item['matricula'] : '')) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="flex items-end gap-3">
        <button class="inline-flex items-center justify-center rounded-xl bg-blue-900 px-4 py-3 text-sm font-semibold text-white hover:bg-blue-950">Filtrar</button>
        <a href="<?= $queryBase ?>" class="inline-flex items-center justify-center rounded-xl border border-slate-200 px-4 py-3 text-sm font-semibold text-slate-600 hover:bg-slate-50">Limpar</a>
      </div>
    </form>
  </div>

  <div class="responsive-table-wrap mt-4">
    <table class="mobile-table-desktop min-w-full text-sm">
      <thead>
        <tr class="border-b text-left text-gray-500">
          <th class="p-3">Colaborador</th>
          <th class="p-3">Cargo</th>
          <th class="p-3">Título</th>
          <th class="p-3">Período</th>
          <th class="p-3">Nota</th>
          <th class="p-3">Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach (($avaliacoes ?? []) as $avaliacao): ?>
          <tr class="border-b">
            <td class="p-3 font-medium text-slate-900"><?= Security::e($avaliacao['colaborador_nome']) ?></td>
            <td class="p-3"><?= Security::e($avaliacao['cargo_nome']) ?></td>
            <td class="p-3"><?= Security::e($avaliacao['titulo']) ?></td>
            <td class="p-3"><?= Security::e($avaliacao['periodo_referencia'] ?? '') ?></td>
            <td class="p-3"><?= $avaliacao['nota'] !== null ? Security::e(number_format((float)$avaliacao['nota'], 2, ',', '.')) : '—' ?></td>
            <td class="p-3">
              <div class="responsive-card-actions">
                <a href="<?= $base ?>/admin/avaliacoes/editar/<?= (int)$avaliacao['id'] ?>" class="text-ctpblue hover:text-ctgreen">Editar</a>
                <form action="<?= $base ?>/admin/avaliacoes/excluir/<?= (int)$avaliacao['id'] ?>" method="post" class="inline" data-confirm-message="Excluir esta avaliação?">
                  <input type="hidden" name="csrf" value="<?= Security::csrfToken() ?>">
                  <button type="submit" class="text-red-600 hover:text-red-800">Excluir</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($avaliacoes)): ?>
          <tr>
            <td colspan="6" class="p-6 text-center text-sm text-slate-500">Nenhuma avaliação encontrada para os filtros informados.</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
