<?php
$statusLabels = [
    'pendente_lider' => 'Pendente líder',
    'pendente_rh' => 'Pendente RH',
    'aprovada' => 'Aprovada',
    'reprovada_lider' => 'Reprovada pelo líder',
    'reprovada_rh' => 'Reprovada pelo RH',
    'concluida' => 'Concluída',
];
$statusClass = static function (string $status): string {
    return match ($status) {
        'pendente_lider', 'pendente_rh' => 'bg-amber-100 text-amber-800',
        'aprovada', 'concluida' => 'bg-green-100 text-green-800',
        default => 'bg-red-100 text-red-800',
    };
};
?>
<div class="responsive-panel">
  <div class="responsive-header">
    <div>
      <h2 class="text-xl font-semibold text-ctpblue">Solicitações de vaga</h2>
      <p class="mt-1 text-sm text-gray-500">Fluxo integrado com cargos, setores, centros de custo, gestores e controle interno do RH.</p>
    </div>
    <div class="flex flex-wrap gap-2">
      <a href="<?= $base ?>/admin/vagas" class="inline-flex items-center justify-center rounded-lg border border-gray-300 px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-50">Voltar para vagas</a>
      <a href="<?= $base ?>/admin/solicitacoes-vaga/kanban" class="inline-flex items-center justify-center rounded-lg border border-ctgreen px-4 py-3 text-sm font-medium text-ctgreen hover:bg-ctgreen hover:text-white">Kanban de solicitações</a>
      <a href="<?= $base ?>/admin/solicitacoes-vaga/nova" class="inline-flex items-center justify-center rounded-lg bg-ctgreen px-4 py-3 text-sm font-medium text-white hover:bg-ctdark">Nova solicitação</a>
    </div>
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
          <th class="p-3 text-left">Área</th>
          <th class="p-3 text-left">Cargo</th>
          <th class="p-3 text-left">Gestor</th>
          <th class="p-3 text-left">Qtd.</th>
          <th class="p-3 text-left">Tipo</th>
          <th class="p-3 text-left">Prazo</th>
          <th class="p-3 text-left">Status</th>
          <th class="p-3 text-left">Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($items)): ?>
          <tr>
            <td colspan="8" class="p-6 text-center text-sm text-gray-500">Nenhuma solicitação de vaga cadastrada até o momento.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($items as $item): ?>
            <tr class="border-b hover:bg-gray-50">
              <td class="p-3 font-medium text-ctpblue"><?= Security::e($item['setor_nome']) ?></td>
              <td class="p-3"><?= Security::e($item['cargo_nome']) ?></td>
              <td class="p-3"><?= Security::e($item['gestor_nome']) ?></td>
              <td class="p-3"><?= (int)$item['quantidade_vagas'] ?></td>
              <td class="p-3"><?= Security::e(ucwords(str_replace('_', ' ', $item['tipo_vaga']))) ?></td>
              <td class="p-3"><?= Security::e($item['data_limite_fechamento'] ?: $item['data_prevista_inicio']) ?></td>
              <td class="p-3">
                <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= $statusClass((string)$item['status_fluxo']) ?>">
                  <?= Security::e($statusLabels[$item['status_fluxo']] ?? $item['status_fluxo']) ?>
                </span>
              </td>
              <td class="p-3">
                <a href="<?= $base ?>/admin/solicitacoes-vaga/<?= (int)$item['id'] ?>" class="text-ctpblue hover:text-ctgreen">Abrir</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="responsive-card-list mt-4 md:hidden">
    <?php foreach ($items as $item): ?>
      <div class="responsive-card">
        <div class="text-base font-semibold text-ctpblue"><?= Security::e($item['cargo_nome']) ?></div>
        <div class="mt-2 text-sm text-gray-600">Área: <?= Security::e($item['setor_nome']) ?></div>
        <div class="mt-1 text-sm text-gray-600">Gestor: <?= Security::e($item['gestor_nome']) ?></div>
        <div class="mt-1 text-sm text-gray-600">Qtd.: <?= (int)$item['quantidade_vagas'] ?></div>
        <div class="mt-3">
          <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= $statusClass((string)$item['status_fluxo']) ?>">
            <?= Security::e($statusLabels[$item['status_fluxo']] ?? $item['status_fluxo']) ?>
          </span>
        </div>
        <div class="responsive-card-actions mt-4">
          <a href="<?= $base ?>/admin/solicitacoes-vaga/<?= (int)$item['id'] ?>" class="text-ctpblue hover:text-ctgreen">Abrir</a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
