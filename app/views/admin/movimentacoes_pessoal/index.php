<?php
require_once APP_PATH . '/views/partials/modulo-topo.php';
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
        'pendente_rh' => 'bg-warning/10 text-warning',
        'aprovada' => 'bg-success/10 text-success',
        default => 'bg-surface-secondary text-text-primary',
    };
};
?>
<div class="space-y-4">
  <?= ui_breadcrumb([['label' => 'Portal RH', 'href' => $base . '/admin'], ['label' => 'Movimentação de Pessoal']]) ?>
  <?= ui_page_header(['titulo' => 'Movimentação de pessoal', 'descricao' => 'Controle de mérito, promoção, transferência e alteração de função com rascunho e assinaturas digitais.', 'acao' => ['label' => 'Nova movimentação', 'href' => $base . '/admin/movimentacoes-pessoal/nova']]) ?>
  <div class="responsive-panel">
  <?php if (!empty($flashError)): ?>
    <div class="mt-4 rounded-ds-md border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger"><?= Security::e($flashError) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashSuccess)): ?>
    <div class="mt-4 rounded-ds-md border border-success/30 bg-success/10 px-4 py-3 text-sm text-success"><?= Security::e($flashSuccess) ?></div>
  <?php endif; ?>

  <div class="responsive-table-wrap mt-4">
    <table class="min-w-full text-sm">
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
          <tr><td colspan="7" class="p-6 text-center text-sm text-text-secondary">Nenhuma movimentação cadastrada até o momento.</td></tr>
        <?php else: ?>
          <?php foreach ($items as $item): ?>
            <tr class="border-b hover:bg-surface-secondary">
              <td class="p-3 font-medium text-primary-700"><?= Security::e($tipoLabels[$item['tipo_movimentacao']] ?? $item['tipo_movimentacao']) ?></td>
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
                <a href="<?= $base ?>/admin/movimentacoes-pessoal/<?= (int)$item['id'] ?>" class="text-primary-700 hover:text-primary-700">Abrir</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</div>
