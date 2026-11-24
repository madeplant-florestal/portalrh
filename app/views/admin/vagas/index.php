<?php
require_once APP_PATH . '/views/partials/modulo-topo.php';
?>
<div class="space-y-4">
  <?= ui_modulo_topo($base, 'recrutamento', 'vagas', [
      'titulo' => 'Vagas',
      'descricao' => !empty($somenteLeitura) ? 'Vagas dos setores que você gerencia (consulta).' : 'Vagas do site de recrutamento e rascunhos gerados a partir das solicitações aprovadas.',
  ] + (!empty($somenteLeitura) ? [] : ['acao' => ['label' => 'Nova vaga (exceção)', 'href' => $base . '/admin/vagas/novo']])) ?>
  <a href="https://madeplant.com.br/trabalhe-conosco" target="_blank" rel="noopener"
     class="inline-flex items-center gap-1.5 text-ds-label font-semibold text-primary-700 hover:text-primary-800">
    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5h5v5m0-5L10 14M9 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-3"/></svg>
    Ver página pública de vagas
  </a>
  <div class="responsive-panel">
  <?php if (!empty($flashSuccess)): ?>
    <div class="mt-3 rounded border border-success/30 bg-success/10 px-3 py-2 text-sm text-success"><?= Security::e($flashSuccess) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashError)): ?>
    <div class="mt-3 rounded border border-danger/30 bg-danger/10 px-3 py-2 text-sm text-danger"><?= Security::e($flashError) ?></div>
  <?php endif; ?>
  <?php if (empty($somenteLeitura)): ?>
  <p class="mt-3 rounded border border-border bg-surface-secondary px-3 py-2 text-xs text-text-secondary">
    Fluxo normal: <strong>Solicitação de Vaga → aprovação → rascunho da vaga → publicar</strong>.
    "Nova vaga" é cadastro manual de exceção (admin/RH).
  </p>
  <?php endif; ?>
  <div class="responsive-table-wrap mt-4">
    <table class="hidden min-w-full text-sm md:table">
    <thead>
      <tr class="border-b">
        <th class="text-left p-3">Título</th>
        <th class="text-left p-3">Empresa</th>
        <th class="text-left p-3">Área</th>
        <?php if (!empty($vagas) && array_key_exists('setor_nome', $vagas[0])): ?><th class="text-left p-3">Setor responsável</th><?php endif; ?>
        <th class="text-left p-3">Local</th>
        <th class="text-left p-3">Ativo</th>
        <?php if (empty($somenteLeitura)): ?><th class="text-left p-3">Ações</th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($vagas as $v): ?>
        <?php $rascunho = (int)$v['ativo'] === 0 && empty($v['publicada_em']) && !empty($v['solicitacao_vaga_id']); ?>
        <tr class="border-b hover:bg-surface-secondary">
          <td class="p-3 font-medium text-text-primary">
            <?= Security::e($v['titulo']) ?>
            <?php if (!empty($v['solicitacao_vaga_id']) && empty($somenteLeitura)): ?>
              <a href="<?= $base ?>/admin/solicitacoes-vaga/<?= (int)$v['solicitacao_vaga_id'] ?>" class="ml-1 text-xs font-normal text-text-muted hover:text-primary-700">(solicitação #<?= (int)$v['solicitacao_vaga_id'] ?>)</a>
            <?php endif; ?>
          </td>
          <td class="p-3"><?= Security::e($v['empresa_nome'] ?? 'Padrão') ?></td>
          <td class="p-3"><?= Security::e($v['area']) ?></td>
          <?php if (array_key_exists('setor_nome', $v)): ?>
            <td class="p-3"><?= $v['setor_nome'] !== null ? Security::e($v['setor_nome']) : '<span class="rounded bg-warning/10 px-2 py-1 text-xs font-semibold text-warning">Sem setor</span>' ?></td>
          <?php endif; ?>
          <td class="p-3"><?= Security::e($v['local']) ?></td>
          <td class="p-3">
            <?php if ($rascunho): ?>
              <span class="px-2 py-1 rounded bg-warning/10 text-warning text-xs font-semibold">Rascunho</span>
            <?php else: ?>
              <span class="px-2 py-1 rounded text-white <?= (int)$v['ativo'] === 1 ? 'bg-primary-700' : 'bg-danger' ?>">
                <?= (int)$v['ativo'] === 1 ? 'Sim' : 'Não' ?>
              </span>
            <?php endif; ?>
          </td>
          <?php if (empty($somenteLeitura)): ?>
          <td class="p-3">
            <div class="responsive-card-actions">
              <?php if ((int)$v['ativo'] === 0): ?>
                <form action="<?= $base ?>/admin/vagas/<?= (int)$v['id'] ?>/publicar" method="post" class="inline" data-confirm-message="Publicar esta vaga no site?">
                  <input type="hidden" name="csrf" value="<?= Security::e(Security::csrfToken()) ?>">
                  <button class="font-semibold text-primary-700 hover:text-primary-800">Publicar</button>
                </form>
              <?php endif; ?>
              <a href="<?= $base ?>/admin/vagas/editar/<?= (int)$v['id'] ?>" class="text-text-primary hover:text-primary-700">Editar</a>
              <form action="<?= $base ?>/admin/vagas/excluir/<?= (int)$v['id'] ?>" method="post" class="inline" data-confirm-message="Excluir esta vaga?">
                <input type="hidden" name="csrf" value="<?= Security::e(Security::csrfToken()) ?>">
                <button class="text-danger hover:text-danger">Excluir</button>
              </form>
            </div>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
    </table>
  </div>
  <div class="responsive-card-list mt-4 md:hidden">
    <?php foreach ($vagas as $v): ?>
      <div class="responsive-card">
        <div class="text-base font-semibold text-text-primary"><?= Security::e($v['titulo']) ?></div>
        <div class="mt-2 text-sm text-text-secondary">Empresa: <?= Security::e($v['empresa_nome'] ?? 'Padrão') ?></div>
        <div class="mt-2 text-sm text-text-secondary">Área: <?= Security::e($v['area']) ?></div>
        <?php if (array_key_exists('setor_nome', $v)): ?><div class="mt-1 text-sm text-text-secondary">Setor responsável: <?= $v['setor_nome'] !== null ? Security::e($v['setor_nome']) : 'sem setor' ?></div><?php endif; ?>
        <div class="mt-1 text-sm text-text-secondary">Local: <?= Security::e($v['local']) ?></div>
        <div class="mt-3">
          <span class="rounded px-2 py-1 text-xs font-semibold text-white <?= (int)$v['ativo'] === 1 ? 'bg-primary-700' : 'bg-danger' ?>">
            <?= (int)$v['ativo'] === 1 ? 'Ativa' : 'Inativa' ?>
          </span>
        </div>
        <?php if (empty($somenteLeitura)): ?>
        <div class="responsive-card-actions mt-4">
          <a href="<?= $base ?>/admin/vagas/editar/<?= (int)$v['id'] ?>" class="text-text-primary hover:text-primary-700">Editar</a>
          <form action="<?= $base ?>/admin/vagas/excluir/<?= (int)$v['id'] ?>" method="post" data-confirm-message="Excluir esta vaga?">
            <input type="hidden" name="csrf" value="<?= Security::e(Security::csrfToken()) ?>">
            <button class="text-danger hover:text-danger">Excluir</button>
          </form>
        </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  </div>
</div>
