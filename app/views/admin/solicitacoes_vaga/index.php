<?php
require_once APP_PATH . '/views/partials/modulo-topo.php';
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
        'pendente_lider', 'pendente_rh' => 'bg-warning/10 text-warning',
        'aprovada', 'concluida' => 'bg-success/10 text-success',
        default => 'bg-danger/10 text-danger',
    };
};
?>
<div class="space-y-4">
  <?= ui_modulo_topo($base, 'recrutamento', 'solicitacoes', [
      'titulo' => 'Solicitações de vaga',
      'descricao' => 'Fluxo integrado com cargos, setores, centros de custo, gestores e controle interno do RH.',
      'acao' => !empty($podeCriarSolicitacao) ? ['label' => 'Nova solicitação', 'href' => $base . '/admin/solicitacoes-vaga/nova'] : null,
  ]) ?>
  <?php if (!empty($vePodeKanban)): ?>
  <div class="flex flex-wrap gap-2">
    <a href="<?= $base ?>/admin/solicitacoes-vaga/kanban" class="<?= ui_btn('secundario') ?>">Kanban de solicitações</a>
  </div>
  <?php endif; ?>
  <?php if (!empty($flashError)): ?>
    <div class="mt-4 rounded-lg border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger"><?= Security::e($flashError) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashSuccess)): ?>
    <div class="mt-4 rounded-lg border border-success/30 bg-success/10 px-4 py-3 text-sm text-success"><?= Security::e($flashSuccess) ?></div>
  <?php endif; ?>

  <div class="responsive-table-wrap mt-4">
    <table class="hidden min-w-full text-sm md:table">
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
            <td colspan="8" class="p-6 text-center text-sm text-text-secondary">Nenhuma solicitação de vaga cadastrada até o momento.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($items as $item): ?>
            <tr class="border-b hover:bg-surface-secondary">
              <td class="p-3 font-medium text-text-primary"><?= Security::e($item['setor_nome']) ?></td>
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
                <a href="<?= $base ?>/admin/solicitacoes-vaga/<?= (int)$item['id'] ?>" class="text-text-primary hover:text-primary-700">Abrir</a>
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
        <div class="text-base font-semibold text-text-primary"><?= Security::e($item['cargo_nome']) ?></div>
        <div class="mt-2 text-sm text-text-secondary">Área: <?= Security::e($item['setor_nome']) ?></div>
        <div class="mt-1 text-sm text-text-secondary">Gestor: <?= Security::e($item['gestor_nome']) ?></div>
        <div class="mt-1 text-sm text-text-secondary">Qtd.: <?= (int)$item['quantidade_vagas'] ?></div>
        <div class="mt-3">
          <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= $statusClass((string)$item['status_fluxo']) ?>">
            <?= Security::e($statusLabels[$item['status_fluxo']] ?? $item['status_fluxo']) ?>
          </span>
        </div>
        <div class="responsive-card-actions mt-4">
          <a href="<?= $base ?>/admin/solicitacoes-vaga/<?= (int)$item['id'] ?>" class="text-text-primary hover:text-primary-700">Abrir</a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
