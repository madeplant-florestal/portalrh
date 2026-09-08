<?php
?>
<div class="responsive-panel">
  <div class="responsive-header">
    <h2 class="text-xl font-semibold text-ctpblue">Vagas</h2>
    <a href="<?= $base ?>/admin/vagas/novo" class="inline-flex items-center justify-center rounded-lg bg-ctgreen px-4 py-3 text-sm font-medium text-white hover:bg-ctdark">Nova vaga (exceção)</a>
  </div>
  <?php if (!empty($flashSuccess)): ?>
    <div class="mt-3 rounded border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-700"><?= Security::e($flashSuccess) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashError)): ?>
    <div class="mt-3 rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700"><?= Security::e($flashError) ?></div>
  <?php endif; ?>
  <p class="mt-3 rounded border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
    Fluxo normal: <strong>Solicitação de Vaga → aprovação → rascunho da vaga → publicar</strong>.
    "Nova vaga" é cadastro manual de exceção (admin/RH).
  </p>
  <div class="responsive-table-wrap mt-4">
    <table class="mobile-table-desktop min-w-full text-sm">
    <thead>
      <tr class="border-b">
        <th class="text-left p-3">Título</th>
        <th class="text-left p-3">Empresa</th>
        <th class="text-left p-3">Área</th>
        <th class="text-left p-3">Local</th>
        <th class="text-left p-3">Ativo</th>
        <th class="text-left p-3">Ações</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($vagas as $v): ?>
        <?php $rascunho = (int)$v['ativo'] === 0 && empty($v['publicada_em']) && !empty($v['solicitacao_vaga_id']); ?>
        <tr class="border-b hover:bg-gray-50">
          <td class="p-3 font-medium text-ctpblue">
            <?= Security::e($v['titulo']) ?>
            <?php if (!empty($v['solicitacao_vaga_id'])): ?>
              <a href="<?= $base ?>/admin/solicitacoes-vaga/<?= (int)$v['solicitacao_vaga_id'] ?>" class="ml-1 text-xs font-normal text-slate-400 hover:text-ctgreen">(solicitação #<?= (int)$v['solicitacao_vaga_id'] ?>)</a>
            <?php endif; ?>
          </td>
          <td class="p-3"><?= Security::e($v['empresa_nome'] ?? 'Padrão') ?></td>
          <td class="p-3"><?= Security::e($v['area']) ?></td>
          <td class="p-3"><?= Security::e($v['local']) ?></td>
          <td class="p-3">
            <?php if ($rascunho): ?>
              <span class="px-2 py-1 rounded bg-amber-100 text-amber-800 text-xs font-semibold">Rascunho</span>
            <?php else: ?>
              <span class="px-2 py-1 rounded text-white <?= (int)$v['ativo'] === 1 ? 'bg-ctgreen' : 'bg-red-400' ?>">
                <?= (int)$v['ativo'] === 1 ? 'Sim' : 'Não' ?>
              </span>
            <?php endif; ?>
          </td>
          <td class="p-3">
            <div class="responsive-card-actions">
              <?php if ((int)$v['ativo'] === 0): ?>
                <form action="<?= $base ?>/admin/vagas/<?= (int)$v['id'] ?>/publicar" method="post" class="inline" data-confirm-message="Publicar esta vaga no site?">
                  <input type="hidden" name="csrf" value="<?= Security::e(Security::csrfToken()) ?>">
                  <button class="font-semibold text-ctgreen hover:text-ctdark">Publicar</button>
                </form>
              <?php endif; ?>
              <a href="<?= $base ?>/admin/vagas/editar/<?= (int)$v['id'] ?>" class="text-ctpblue hover:text-ctgreen">Editar</a>
              <form action="<?= $base ?>/admin/vagas/excluir/<?= (int)$v['id'] ?>" method="post" class="inline" data-confirm-message="Excluir esta vaga?">
                <input type="hidden" name="csrf" value="<?= Security::e(Security::csrfToken()) ?>">
                <button class="text-red-600 hover:text-red-800">Excluir</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    </table>
  </div>
  <div class="responsive-card-list mt-4 md:hidden">
    <?php foreach ($vagas as $v): ?>
      <div class="responsive-card">
        <div class="text-base font-semibold text-ctpblue"><?= Security::e($v['titulo']) ?></div>
        <div class="mt-2 text-sm text-gray-600">Empresa: <?= Security::e($v['empresa_nome'] ?? 'Padrão') ?></div>
        <div class="mt-2 text-sm text-gray-600">Área: <?= Security::e($v['area']) ?></div>
        <div class="mt-1 text-sm text-gray-600">Local: <?= Security::e($v['local']) ?></div>
        <div class="mt-3">
          <span class="rounded px-2 py-1 text-xs font-semibold text-white <?= (int)$v['ativo'] === 1 ? 'bg-ctgreen' : 'bg-red-400' ?>">
            <?= (int)$v['ativo'] === 1 ? 'Ativa' : 'Inativa' ?>
          </span>
        </div>
        <div class="responsive-card-actions mt-4">
          <a href="<?= $base ?>/admin/vagas/editar/<?= (int)$v['id'] ?>" class="text-ctpblue hover:text-ctgreen">Editar</a>
          <form action="<?= $base ?>/admin/vagas/excluir/<?= (int)$v['id'] ?>" method="post" data-confirm-message="Excluir esta vaga?">
            <input type="hidden" name="csrf" value="<?= Security::e(Security::csrfToken()) ?>">
            <button class="text-red-600 hover:text-red-800">Excluir</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
