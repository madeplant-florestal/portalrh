<?php
?>
<div class="responsive-panel">
  <div class="responsive-header">
    <div>
      <h2 class="text-xl font-semibold text-ctpblue">Mensagens</h2>
      <p class="mt-1 text-sm text-gray-500">Modelos de mensagem usados no processo seletivo. O Portal é a fonte oficial destes textos.</p>
    </div>
    <?php if (!empty($podeCriar)): ?>
    <a href="<?= $base ?>/admin/mensagens/novo" class="inline-flex items-center justify-center rounded-lg bg-ctgreen px-4 py-3 text-sm font-medium text-white hover:bg-ctdark">Nova mensagem</a>
    <?php endif; ?>
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
        <tr class="text-left text-gray-500 border-b">
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
            <td class="p-3 font-medium text-gray-900"><?= Security::e((string)$m['titulo']) ?></td>
            <td class="p-3"><code class="rounded bg-gray-100 px-2 py-1 text-xs text-gray-700"><?= Security::e((string)$m['codigo']) ?></code></td>
            <td class="p-3">
              <span class="ct-badge <?= (int)($m['ativo'] ?? 0) === 1 ? 'ct-badge-active' : 'ct-badge-inactive' ?>">
                <?= (int)($m['ativo'] ?? 0) === 1 ? 'Ativa' : 'Inativa' ?>
              </span>
            </td>
            <td class="p-3 text-gray-500"><?= !empty($m['updated_at']) ? date('d/m/Y H:i', strtotime((string)$m['updated_at'])) : '-' ?></td>
            <td class="p-3">
              <?php if (!empty($podeEditar)): ?>
                <a href="<?= $base ?>/admin/mensagens/editar/<?= (int)$m['id'] ?>" class="text-ctpblue hover:text-ctgreen">Editar</a>
              <?php else: ?>
                <span class="text-gray-400">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($mensagens)): ?>
          <tr>
            <td colspan="5" class="p-4 text-center text-gray-500">Nenhuma mensagem cadastrada</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="responsive-card-list mt-4 md:hidden">
    <?php foreach (($mensagens ?? []) as $m): ?>
      <div class="responsive-card">
        <div class="text-base font-semibold text-ctpblue"><?= Security::e((string)$m['titulo']) ?></div>
        <div class="mt-1"><code class="rounded bg-gray-100 px-2 py-1 text-xs text-gray-700"><?= Security::e((string)$m['codigo']) ?></code></div>
        <div class="mt-2 text-xs font-medium text-gray-500">
          <?= (int)($m['ativo'] ?? 0) === 1 ? 'Ativa' : 'Inativa' ?>
          · atualizado em <?= !empty($m['updated_at']) ? date('d/m/Y H:i', strtotime((string)$m['updated_at'])) : '-' ?>
        </div>
        <?php if (!empty($podeEditar)): ?>
        <div class="responsive-card-actions mt-4">
          <a href="<?= $base ?>/admin/mensagens/editar/<?= (int)$m['id'] ?>" class="text-ctpblue hover:text-ctgreen">Editar</a>
        </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
