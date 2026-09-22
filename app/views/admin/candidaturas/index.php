<?php
require_once APP_PATH . '/views/partials/modulo-topo.php';
?>
<div class="space-y-4">
  <?= ui_modulo_topo($base, 'recrutamento', 'candidaturas', [
      'titulo' => 'Candidaturas',
      'descricao' => 'Candidatos que se inscreveram nas vagas publicadas, com etapa do pipeline e indicação.',
      'acao' => ['label' => 'Ver Kanban', 'href' => $base . '/admin/pipeline'],
  ]) ?>
  <div class="responsive-panel">
  <form class="responsive-form-grid-4 mt-4" method="get">
    <div>
      <label class="block text-sm font-medium text-text-primary">Vaga</label>
      <select name="vaga_id" class="mt-1 w-full border rounded px-3 py-2 text-sm">
        <option value="">Todas</option>
        <?php foreach ($vagas as $v): ?>
          <option value="<?= (int)$v['id'] ?>" <?= ($filters['vaga_id'] ?? '') == $v['id'] ? 'selected' : '' ?>><?= Security::e($v['titulo']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="block text-sm font-medium text-text-primary">Etapa (Pipeline)</label>
      <select name="stage_id" class="mt-1 w-full border rounded px-3 py-2 text-sm">
        <option value="">Todas</option>
        <?php foreach ($stages as $st): ?>
          <option value="<?= $st['id'] ?>" <?= ($filters['stage_id'] ?? '') == $st['id'] ? 'selected' : '' ?>>
            <?= Security::e($st['nome']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="block text-sm font-medium text-text-primary">De</label>
      <input type="date" name="data_de" value="<?= Security::e($filters['data_de'] ?? '') ?>" class="mt-1 w-full border rounded px-3 py-2 text-sm" />
    </div>
    <div>
      <label class="block text-sm font-medium text-text-primary">Até</label>
      <div class="mt-1 flex flex-col gap-2 sm:flex-row">
          <input type="date" name="data_ate" value="<?= Security::e($filters['data_ate'] ?? '') ?>" class="w-full flex-1 border rounded px-3 py-2 text-sm" />
          <button class="<?= ui_btn('primario') ?>">Filtrar</button>
      </div>
    </div>
  </form>

  <div class="responsive-table-wrap mt-6">
    <table class="hidden min-w-full text-sm md:table">
      <thead class="bg-surface-secondary">
        <tr class="border-b">
          <th class="text-left p-3 font-medium text-text-secondary">#</th>
          <th class="text-left p-3 font-medium text-text-secondary">Vaga</th>
          <th class="text-left p-3 font-medium text-text-secondary">Nome</th>
          <th class="text-left p-3 font-medium text-text-secondary">E-mail</th>
          <th class="text-left p-3 font-medium text-text-secondary">Telefone</th>
          <th class="text-left p-3 font-medium text-text-secondary">Etapa</th>
          <th class="text-left p-3 font-medium text-text-secondary">Data</th>
          <th class="text-left p-3 font-medium text-text-secondary">Ações</th>
          <th class="text-left p-3 font-medium text-text-secondary">Indicação</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-border">
        <?php foreach ($candidaturas as $c): ?>
          <tr class="hover:bg-surface-secondary">
            <td class="p-3 text-text-secondary"><?= (int)$c['id'] ?></td>
            <td class="p-3 font-medium text-text-primary"><?= Security::e($c['vaga_titulo'] ?? '-') ?></td>
            <td class="p-3 text-text-primary"><?= Security::e($c['nome']) ?></td>
            <td class="p-3 text-text-secondary"><?= Security::e($c['email']) ?></td>
            <td class="p-3 text-text-secondary"><?= Security::e(Phone::format($c['telefone'] ?? '')) ?></td>
            <td class="p-3">
                <?php 
                $stageName = $c['stage_nome'] ?? 'Novo';
                $stageColor = $c['stage_cor'] ?? '#cccccc';
                $stageColorNormalized = strtolower(trim((string)$stageColor));
                if (in_array($stageColorNormalized, ['#10b981', '#059669', '#10e36b', '#057038', '#166534', '#14532d'], true)) {
                    $stageColor = '#3B4822';
                }
                ?>
                <span class="whitespace-nowrap px-2 py-1 rounded-ds-sm text-xs font-semibold text-white" style="background-color: <?= $stageColor ?>;">
                    <?= Security::e($stageName) ?>
                </span>
            </td>
            <td class="p-3 text-text-secondary"><?= date('d/m/Y H:i', strtotime($c['created_at'])) ?></td>
            <td class="p-3">
              <div class="responsive-card-actions">
                <a href="<?= $base ?>/admin/candidaturas/<?= (int)$c['id'] ?>" class="text-primary-700 hover:text-primary-800 font-medium">Detalhes</a>
                <a href="<?= $base ?>/admin/candidaturas/<?= (int)$c['id'] ?>/download" class="text-text-secondary hover:text-text-primary">PDF</a>
              </div>
            </td>
            <td class="p-3">
              <?php $canToggleIndicacao = in_array((string)Auth::role(), ['admin', 'rh'], true); ?>
              <form action="<?= $base ?>/admin/candidaturas/<?= (int)$c['id'] ?>/indicacao" method="post" class="flex items-center justify-center gap-2" data-indicacao-form="<?= (int)$c['id'] ?>">
                <input type="hidden" name="csrf" value="<?= Security::e($csrf ?? '') ?>">
                <input type="hidden" name="indicacao_colaborador" value="0">
                <input type="hidden" name="indicacao_colaborador_nome" value="<?= Security::e($c['indicacao_colaborador_nome'] ?? '') ?>" data-indicacao-nome-hidden="<?= (int)$c['id'] ?>">
                <label class="inline-flex items-center gap-2 cursor-pointer text-xs text-text-secondary">
                  <input type="checkbox" name="indicacao_colaborador" value="1" <?= (int)($c['indicacao_colaborador'] ?? 0) === 1 ? 'checked' : '' ?> <?= $canToggleIndicacao ? '' : 'disabled' ?> class="rounded border-border text-primary-700 focus:ring-primary-100 h-4 w-4" data-indicacao-check="<?= (int)$c['id'] ?>">
                  <span><?= (int)($c['indicacao_colaborador'] ?? 0) === 1 ? 'Indic.' : 'Não' ?></span>
                </label>
              </form>
              <?php if ((int)($c['indicacao_colaborador'] ?? 0) === 1 && !empty($c['indicacao_colaborador_nome'])): ?>
                <div class="mt-1 max-w-full text-center text-xs text-text-secondary" title="<?= Security::e($c['indicacao_colaborador_nome']) ?>">
                  <?= Security::e($c['indicacao_colaborador_nome']) ?>
                </div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($candidaturas)): ?>
            <tr>
                <td colspan="9" class="p-6 text-center text-text-secondary">Nenhuma candidatura encontrada.</td>
            </tr>
        <?php endif; ?>
      </tbody>
    </table>
    <div class="responsive-card-list md:hidden">
      <?php foreach ($candidaturas as $c): ?>
        <div class="responsive-card">
          <div class="responsive-card-row items-start">
            <div>
              <div class="text-sm font-semibold text-text-primary"><?= Security::e($c['nome']) ?></div>
              <div class="text-xs text-text-secondary"><?= Security::e($c['email']) ?></div>
              <div class="text-xs text-text-secondary"><?= Security::e(Phone::format($c['telefone'] ?? '')) ?></div>
              <div class="text-xs text-text-secondary mt-1"><?= Security::e($c['vaga_titulo'] ?? '-') ?></div>
            </div>
            <span class="whitespace-nowrap px-2 py-1 rounded-ds-sm text-[11px] font-semibold text-white" style="background-color: <?= $c['stage_cor'] ?? '#cccccc' ?>;">
              <?= Security::e($c['stage_nome'] ?? 'Novo') ?>
            </span>
          </div>
          <div class="responsive-card-row mt-3">
            <div class="text-xs text-text-secondary"><?= date('d/m/Y H:i', strtotime($c['created_at'])) ?></div>
            <div class="responsive-card-actions text-xs">
              <a href="<?= $base ?>/admin/candidaturas/<?= (int)$c['id'] ?>" class="text-primary-700 font-medium">Detalhes</a>
              <a href="<?= $base ?>/admin/candidaturas/<?= (int)$c['id'] ?>/download" class="text-text-secondary">PDF</a>
            </div>
          </div>
          <div class="mt-2">
            <?php $canToggleIndicacao = in_array((string)Auth::role(), ['admin', 'rh'], true); ?>
            <form action="<?= $base ?>/admin/candidaturas/<?= (int)$c['id'] ?>/indicacao" method="post" class="responsive-card-row items-center" data-indicacao-form="<?= (int)$c['id'] ?>">
              <input type="hidden" name="csrf" value="<?= Security::e($csrf ?? '') ?>">
              <input type="hidden" name="indicacao_colaborador" value="0">
              <input type="hidden" name="indicacao_colaborador_nome" value="<?= Security::e($c['indicacao_colaborador_nome'] ?? '') ?>" data-indicacao-nome-hidden="<?= (int)$c['id'] ?>">
              <label class="inline-flex items-center gap-2 cursor-pointer text-xs text-text-secondary">
                <input type="checkbox" name="indicacao_colaborador" value="1" <?= (int)($c['indicacao_colaborador'] ?? 0) === 1 ? 'checked' : '' ?> <?= $canToggleIndicacao ? '' : 'disabled' ?> class="rounded border-border text-primary-700 focus:ring-primary-100 h-4 w-4" data-indicacao-check="<?= (int)$c['id'] ?>">
                <span><?= (int)($c['indicacao_colaborador'] ?? 0) === 1 ? 'Indic.' : 'Não' ?></span>
              </label>
              <a href="<?= $base ?>/admin/candidaturas/<?= (int)$c['id'] ?>" class="text-xs text-primary-700 font-medium">Abrir</a>
            </form>
            <?php if ((int)($c['indicacao_colaborador'] ?? 0) === 1 && !empty($c['indicacao_colaborador_nome'])): ?>
              <div class="text-xs text-text-secondary mt-1 truncate" title="<?= Security::e($c['indicacao_colaborador_nome']) ?>">
                <?= Security::e($c['indicacao_colaborador_nome']) ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
</div>
<div id="indicacao-modal" class="fixed inset-0 bg-black bg-opacity-40 hidden items-center justify-center z-50">
  <div class="bg-white rounded-lg shadow-lg w-full max-w-md p-5">
    <h3 class="text-lg font-semibold text-text-primary">Registrar indicação</h3>
    <p class="text-sm text-text-secondary mt-1">Informe o nome do colaborador que indicou o candidato.</p>
    <div class="mt-3">
      <label class="block text-sm font-medium text-text-primary">Nome do colaborador</label>
      <input id="indicacao-colaborador-input" type="text" class="mt-1 w-full border rounded px-3 py-2 text-sm" placeholder="Ex.: João da Silva">
      <p id="indicacao-colaborador-erro" class="text-danger text-xs mt-1 hidden">Informe o nome do colaborador.</p>
    </div>
    <div class="responsive-form-actions mt-4 justify-end">
      <button type="button" id="indicacao-cancelar" class="<?= ui_btn('secundario') ?>">Cancelar</button>
      <button type="button" id="indicacao-confirmar" class="<?= ui_btn('primario') ?>">Salvar</button>
    </div>
  </div>
</div>
<?php ui_script_pagina('candidaturas.js'); // JS movido para assets/candidaturas.js (CSP: sem <script> inline) ?>
