<?php
?>
<div class="responsive-panel">
  <div class="responsive-header">
      <h2 class="text-xl font-semibold text-ctpblue">Candidaturas</h2>
      <a href="<?= $base ?>/admin/pipeline" class="inline-flex items-center justify-center rounded-lg bg-ctlight px-4 py-3 text-sm font-medium text-white hover:bg-ctgreen">Ver Kanban</a>
  </div>
  
  <form class="responsive-form-grid-4 mt-4" method="get">
    <div>
      <label class="block text-sm font-medium text-gray-700">Vaga</label>
      <select name="vaga_id" class="mt-1 w-full border rounded px-3 py-2 text-sm">
        <option value="">Todas</option>
        <?php foreach ($vagas as $v): ?>
          <option value="<?= (int)$v['id'] ?>" <?= ($filters['vaga_id'] ?? '') == $v['id'] ? 'selected' : '' ?>><?= Security::e($v['titulo']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700">Etapa (Pipeline)</label>
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
      <label class="block text-sm font-medium text-gray-700">De</label>
      <input type="date" name="data_de" value="<?= Security::e($filters['data_de'] ?? '') ?>" class="mt-1 w-full border rounded px-3 py-2 text-sm" />
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700">Até</label>
      <div class="mt-1 flex flex-col gap-2 sm:flex-row">
          <input type="date" name="data_ate" value="<?= Security::e($filters['data_ate'] ?? '') ?>" class="w-full flex-1 border rounded px-3 py-2 text-sm" />
          <button class="inline-flex items-center justify-center rounded-lg bg-ctgreen px-4 py-3 text-sm font-medium text-white hover:bg-ctdark">Filtrar</button>
      </div>
    </div>
  </form>

  <div class="responsive-table-wrap mt-6">
    <table class="mobile-table-desktop min-w-full text-sm">
      <thead class="bg-gray-50">
        <tr class="border-b">
          <th class="text-left p-3 font-medium text-gray-500">#</th>
          <th class="text-left p-3 font-medium text-gray-500">Vaga</th>
          <th class="text-left p-3 font-medium text-gray-500">Nome</th>
          <th class="text-left p-3 font-medium text-gray-500">E-mail</th>
          <th class="text-left p-3 font-medium text-gray-500">Telefone</th>
          <th class="text-left p-3 font-medium text-gray-500">Etapa</th>
          <th class="text-left p-3 font-medium text-gray-500">Data</th>
          <th class="text-left p-3 font-medium text-gray-500">Ações</th>
          <th class="text-left p-3 font-medium text-gray-500">Indicação</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-200">
        <?php foreach ($candidaturas as $c): ?>
          <tr class="hover:bg-gray-50">
            <td class="p-3 text-gray-500"><?= (int)$c['id'] ?></td>
            <td class="p-3 font-medium text-gray-900"><?= Security::e($c['vaga_titulo'] ?? '-') ?></td>
            <td class="p-3 text-gray-900"><?= Security::e($c['nome']) ?></td>
            <td class="p-3 text-gray-500"><?= Security::e($c['email']) ?></td>
            <td class="p-3 text-gray-500"><?= Security::e(Phone::format($c['telefone'] ?? '')) ?></td>
            <td class="p-3">
                <?php 
                $stageName = $c['stage_nome'] ?? 'Novo';
                $stageColor = $c['stage_cor'] ?? '#cccccc';
                $stageColorNormalized = strtolower(trim((string)$stageColor));
                if (in_array($stageColorNormalized, ['#10b981', '#059669', '#10e36b', '#057038', '#166534', '#14532d'], true)) {
                    $stageColor = '#1d2d44';
                }
                ?>
                <span class="px-2 py-1 rounded text-xs font-semibold text-white" style="background-color: <?= $stageColor ?>;">
                    <?= Security::e($stageName) ?>
                </span>
            </td>
            <td class="p-3 text-gray-500"><?= date('d/m/Y H:i', strtotime($c['created_at'])) ?></td>
            <td class="p-3">
              <div class="responsive-card-actions">
                <a href="<?= $base ?>/admin/candidaturas/<?= (int)$c['id'] ?>" class="text-ctgreen hover:text-ctdark font-medium">Detalhes</a>
                <a href="<?= $base ?>/admin/candidaturas/<?= (int)$c['id'] ?>/download" class="text-gray-600 hover:text-gray-900">PDF</a>
              </div>
            </td>
            <td class="p-3">
              <?php $canToggleIndicacao = in_array((string)Auth::role(), ['admin', 'rh'], true); ?>
              <form action="<?= $base ?>/admin/candidaturas/<?= (int)$c['id'] ?>/indicacao" method="post" class="flex items-center justify-center gap-2" data-indicacao-form="<?= (int)$c['id'] ?>">
                <input type="hidden" name="csrf" value="<?= Security::e($csrf ?? '') ?>">
                <input type="hidden" name="indicacao_colaborador" value="0">
                <input type="hidden" name="indicacao_colaborador_nome" value="<?= Security::e($c['indicacao_colaborador_nome'] ?? '') ?>" data-indicacao-nome-hidden="<?= (int)$c['id'] ?>">
                <label class="inline-flex items-center gap-2 cursor-pointer text-xs text-gray-600">
                  <input type="checkbox" name="indicacao_colaborador" value="1" <?= (int)($c['indicacao_colaborador'] ?? 0) === 1 ? 'checked' : '' ?> <?= $canToggleIndicacao ? '' : 'disabled' ?> class="rounded border-gray-300 text-ctgreen focus:ring-ctgreen h-4 w-4" data-indicacao-check="<?= (int)$c['id'] ?>">
                  <span><?= (int)($c['indicacao_colaborador'] ?? 0) === 1 ? 'Indic.' : 'Não' ?></span>
                </label>
              </form>
              <?php if ((int)($c['indicacao_colaborador'] ?? 0) === 1 && !empty($c['indicacao_colaborador_nome'])): ?>
                <div class="mt-1 max-w-full text-center text-xs text-gray-500" title="<?= Security::e($c['indicacao_colaborador_nome']) ?>">
                  <?= Security::e($c['indicacao_colaborador_nome']) ?>
                </div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($candidaturas)): ?>
            <tr>
                <td colspan="9" class="p-6 text-center text-gray-500">Nenhuma candidatura encontrada.</td>
            </tr>
        <?php endif; ?>
      </tbody>
    </table>
    <div class="responsive-card-list md:hidden">
      <?php foreach ($candidaturas as $c): ?>
        <div class="responsive-card">
          <div class="responsive-card-row items-start">
            <div>
              <div class="text-sm font-semibold text-gray-900"><?= Security::e($c['nome']) ?></div>
              <div class="text-xs text-gray-500"><?= Security::e($c['email']) ?></div>
              <div class="text-xs text-gray-500"><?= Security::e(Phone::format($c['telefone'] ?? '')) ?></div>
              <div class="text-xs text-gray-500 mt-1"><?= Security::e($c['vaga_titulo'] ?? '-') ?></div>
            </div>
            <span class="px-2 py-1 rounded text-[11px] font-semibold text-white" style="background-color: <?= $c['stage_cor'] ?? '#cccccc' ?>;">
              <?= Security::e($c['stage_nome'] ?? 'Novo') ?>
            </span>
          </div>
          <div class="responsive-card-row mt-3">
            <div class="text-xs text-gray-500"><?= date('d/m/Y H:i', strtotime($c['created_at'])) ?></div>
            <div class="responsive-card-actions text-xs">
              <a href="<?= $base ?>/admin/candidaturas/<?= (int)$c['id'] ?>" class="text-ctgreen font-medium">Detalhes</a>
              <a href="<?= $base ?>/admin/candidaturas/<?= (int)$c['id'] ?>/download" class="text-gray-600">PDF</a>
            </div>
          </div>
          <div class="mt-2">
            <?php $canToggleIndicacao = in_array((string)Auth::role(), ['admin', 'rh'], true); ?>
            <form action="<?= $base ?>/admin/candidaturas/<?= (int)$c['id'] ?>/indicacao" method="post" class="responsive-card-row items-center" data-indicacao-form="<?= (int)$c['id'] ?>">
              <input type="hidden" name="csrf" value="<?= Security::e($csrf ?? '') ?>">
              <input type="hidden" name="indicacao_colaborador" value="0">
              <input type="hidden" name="indicacao_colaborador_nome" value="<?= Security::e($c['indicacao_colaborador_nome'] ?? '') ?>" data-indicacao-nome-hidden="<?= (int)$c['id'] ?>">
              <label class="inline-flex items-center gap-2 cursor-pointer text-xs text-gray-600">
                <input type="checkbox" name="indicacao_colaborador" value="1" <?= (int)($c['indicacao_colaborador'] ?? 0) === 1 ? 'checked' : '' ?> <?= $canToggleIndicacao ? '' : 'disabled' ?> class="rounded border-gray-300 text-ctgreen focus:ring-ctgreen h-4 w-4" data-indicacao-check="<?= (int)$c['id'] ?>">
                <span><?= (int)($c['indicacao_colaborador'] ?? 0) === 1 ? 'Indic.' : 'Não' ?></span>
              </label>
              <a href="<?= $base ?>/admin/candidaturas/<?= (int)$c['id'] ?>" class="text-xs text-ctgreen font-medium">Abrir</a>
            </form>
            <?php if ((int)($c['indicacao_colaborador'] ?? 0) === 1 && !empty($c['indicacao_colaborador_nome'])): ?>
              <div class="text-xs text-gray-500 mt-1 truncate" title="<?= Security::e($c['indicacao_colaborador_nome']) ?>">
                <?= Security::e($c['indicacao_colaborador_nome']) ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<div id="indicacao-modal" class="fixed inset-0 bg-black bg-opacity-40 hidden items-center justify-center z-50">
  <div class="bg-white rounded-lg shadow-lg w-full max-w-md p-5">
    <h3 class="text-lg font-semibold text-ctpblue">Registrar indicação</h3>
    <p class="text-sm text-gray-600 mt-1">Informe o nome do colaborador que indicou o candidato.</p>
    <div class="mt-3">
      <label class="block text-sm font-medium text-gray-700">Nome do colaborador</label>
      <input id="indicacao-colaborador-input" type="text" class="mt-1 w-full border rounded px-3 py-2 text-sm" placeholder="Ex.: João da Silva">
      <p id="indicacao-colaborador-erro" class="text-red-600 text-xs mt-1 hidden">Informe o nome do colaborador.</p>
    </div>
    <div class="responsive-form-actions mt-4 justify-end">
      <button type="button" id="indicacao-cancelar" class="px-4 py-2 border rounded text-sm text-gray-600 hover:bg-gray-50">Cancelar</button>
      <button type="button" id="indicacao-confirmar" class="px-4 py-2 rounded text-sm text-white bg-ctgreen hover:bg-ctdark">Salvar</button>
    </div>
  </div>
</div>
<script>
(() => {
  const modal = document.getElementById('indicacao-modal');
  const input = document.getElementById('indicacao-colaborador-input');
  const erro = document.getElementById('indicacao-colaborador-erro');
  const btnCancelar = document.getElementById('indicacao-cancelar');
  const btnConfirmar = document.getElementById('indicacao-confirmar');
  let activeForm = null;
  let activeCheck = null;

  const openModal = (form, check) => {
    activeForm = form;
    activeCheck = check;
    const hidden = form.querySelector('[data-indicacao-nome-hidden]');
    input.value = hidden && hidden.value ? hidden.value : '';
    erro.classList.add('hidden');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    setTimeout(() => input.focus(), 10);
  };

  const closeModal = (restoreCheck) => {
    if (restoreCheck && activeCheck) activeCheck.checked = false;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    activeForm = null;
    activeCheck = null;
  };

  document.querySelectorAll('[data-indicacao-check]').forEach((check) => {
    check.addEventListener('change', () => {
      const form = check.closest('form');
      if (!form) return;
      if (!check.checked) {
        const hidden = form.querySelector('[data-indicacao-nome-hidden]');
        if (hidden) hidden.value = '';
        form.submit();
        return;
      }
      openModal(form, check);
    });
  });

  btnCancelar.addEventListener('click', () => closeModal(true));
  modal.addEventListener('click', (e) => {
    if (e.target === modal) closeModal(true);
  });

  btnConfirmar.addEventListener('click', () => {
    if (!activeForm) return;
    const formRef = activeForm;
    const nome = (input.value || '').trim();
    if (!nome) {
      erro.classList.remove('hidden');
      input.focus();
      return;
    }
    const hidden = formRef.querySelector('[data-indicacao-nome-hidden]');
    if (hidden) hidden.value = nome;
    closeModal(false);
    formRef.submit();
  });
})();
</script>
