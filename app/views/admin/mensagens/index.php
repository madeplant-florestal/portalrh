<?php
require_once APP_PATH . '/views/partials/modulo-topo.php';
?>
<div class="space-y-4">
  <?= ui_breadcrumb([['label' => 'Portal RH', 'href' => $base . '/admin'], ['label' => 'Mensagens']]) ?>
  <?= ui_page_header(['titulo' => 'Mensagens', 'descricao' => 'Modelos de mensagem usados no processo seletivo. O Portal é a fonte oficial destes textos.', 'acao' => !empty($podeCriar) ? ['label' => 'Nova mensagem', 'href' => $base . '/admin/mensagens/novo'] : null]) ?>
  <div class="responsive-panel">
  <?php if (!empty($flashError)): ?>
    <div class="mt-4 rounded-ds-md border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger"><?= Security::e($flashError) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashSuccess)): ?>
    <div class="mt-4 rounded-ds-md border border-success/30 bg-success/10 px-4 py-3 text-sm text-success"><?= Security::e($flashSuccess) ?></div>
  <?php endif; ?>

  <div class="responsive-table-wrap mt-4">
    <table class="hidden min-w-full text-sm md:table">
      <thead>
        <tr class="text-left text-text-secondary border-b">
          <th class="p-3">Título</th>
          <th class="p-3">Código</th>
          <th class="p-3">Status</th>
          <th class="p-3">Última atualização</th>
          <th class="p-3">Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach (($mensagens ?? []) as $m): ?>
          <tr class="border-b">
            <td class="p-3 font-medium text-text-primary"><?= Security::e((string)$m['titulo']) ?></td>
            <td class="p-3"><code class="rounded bg-surface-secondary px-2 py-1 text-xs text-text-primary"><?= Security::e((string)$m['codigo']) ?></code></td>
            <td class="p-3">
              <span class="ct-badge <?= (int)($m['ativo'] ?? 0) === 1 ? 'ct-badge-active' : 'ct-badge-inactive' ?>">
                <?= (int)($m['ativo'] ?? 0) === 1 ? 'Ativa' : 'Inativa' ?>
              </span>
            </td>
            <td class="p-3 text-text-secondary"><?= !empty($m['updated_at']) ? date('d/m/Y H:i', strtotime((string)$m['updated_at'])) : '-' ?></td>
            <td class="p-3">
              <?php if (!empty($podeEditar)): ?>
                <a href="<?= $base ?>/admin/mensagens/editar/<?= (int)$m['id'] ?>" class="text-primary-700 hover:text-primary-700">Editar</a>
              <?php else: ?>
                <span class="text-text-muted">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($mensagens)): ?>
          <tr>
            <td colspan="5" class="p-4 text-center text-text-secondary">Nenhuma mensagem cadastrada</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="responsive-card-list mt-4 md:hidden">
    <?php foreach (($mensagens ?? []) as $m): ?>
      <div class="responsive-card">
        <div class="text-base font-semibold text-primary-700"><?= Security::e((string)$m['titulo']) ?></div>
        <div class="mt-1"><code class="rounded bg-surface-secondary px-2 py-1 text-xs text-text-primary"><?= Security::e((string)$m['codigo']) ?></code></div>
        <div class="mt-2 text-xs font-medium text-text-secondary">
          <?= (int)($m['ativo'] ?? 0) === 1 ? 'Ativa' : 'Inativa' ?>
          · atualizado em <?= !empty($m['updated_at']) ? date('d/m/Y H:i', strtotime((string)$m['updated_at'])) : '-' ?>
        </div>
        <?php if (!empty($podeEditar)): ?>
        <div class="responsive-card-actions mt-4">
          <a href="<?= $base ?>/admin/mensagens/editar/<?= (int)$m['id'] ?>" class="text-primary-700 hover:text-primary-700">Editar</a>
        </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
</div>
