<?php
$statusLabels = [
    'rascunho' => 'Rascunho',
    'pendente_rh' => 'Pendente RH',
    'aprovada' => 'Aprovada',
];
$tipoLabels = [
    'merito' => 'Mérito',
    'promocao' => 'Promoção',
    'transferencia' => 'Transferência',
    'alteracao_funcao' => 'Alteração de função',
];
$statusClass = static function (string $status): string {
    return match ($status) {
        'pendente_rh' => 'bg-amber-100 text-amber-800',
        'aprovada' => 'bg-green-100 text-green-800',
        default => 'bg-slate-100 text-slate-700',
    };
};
?>
<div class="responsive-panel">
  <div class="responsive-header">
    <div>
      <h2 class="text-xl font-semibold text-ctpblue">Movimentação de pessoal</h2>
      <p class="mt-1 text-sm text-gray-500">Controle de mérito, promoção, transferência e alteração de função com rascunho e assinaturas digitais.</p>
    </div>
    <a href="<?= $base ?>/admin/movimentacoes-pessoal/nova" class="inline-flex items-center justify-center rounded-lg bg-ctgreen px-4 py-3 text-sm font-medium text-white hover:bg-ctdark">Nova movimentação</a>
  </div>

  <?php if (!empty($flashError)): ?>
    <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= Security::e($flashError) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashSuccess)): ?>
    <div class="mt-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700"><?= Security::e($flashSuccess) ?></div>
  <?php endif; ?>

  <div class="responsive-table-wrap mt-4">
    <table class="mobile-table-desktop min-w-full text-sm">
      <thead>
        <tr class="border-b">
          <th class="p-3 text-left">Tipo</th>
          <th class="p-3 text-left">Colaborador</th>
          <th class="p-3 text-left">Gestor</th>
          <th class="p-3 text-left">Área</th>
          <th class="p-3 text-left">Mudança</th>
          <th class="p-3 text-left">Status</th>
          <th class="p-3 text-left">Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($items)): ?>
          <tr><td colspan="7" class="p-6 text-center text-sm text-gray-500">Nenhuma movimentação cadastrada até o momento.</td></tr>
        <?php else: ?>
          <?php foreach ($items as $item): ?>
            <tr class="border-b hover:bg-gray-50">
              <td class="p-3 font-medium text-ctpblue"><?= Security::e($tipoLabels[$item['tipo_movimentacao']] ?? $item['tipo_movimentacao']) ?></td>
              <td class="p-3"><?= Security::e($item['colaborador_nome']) ?></td>
              <td class="p-3"><?= Security::e($item['gestor_nome'] ?: '-') ?></td>
              <td class="p-3"><?= Security::e($item['setor_nome']) ?></td>
              <td class="p-3">
                <?= Security::e($item['novo_cargo_nome'] ?: ($item['nova_area_nome'] ?: 'Sem alteração estrutural')) ?>
              </td>
              <td class="p-3">
                <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= $statusClass((string)$item['status_fluxo']) ?>">
                  <?= Security::e($statusLabels[$item['status_fluxo']] ?? $item['status_fluxo']) ?>
                </span>
              </td>
              <td class="p-3">
                <a href="<?= $base ?>/admin/movimentacoes-pessoal/<?= (int)$item['id'] ?>" class="text-ctpblue hover:text-ctgreen">Abrir</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
