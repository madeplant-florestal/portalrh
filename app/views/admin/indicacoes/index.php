<?php
$queryBase = $base . '/admin/indicacoes';
$q = $filters['q'] ?? '';
$pagamento = $filters['pagamento'] ?? '';
$experiencia = $filters['experiencia'] ?? '';
$dataDe = $filters['data_de'] ?? '';
$dataAte = $filters['data_ate'] ?? '';
$indicador = $filters['indicador'] ?? '';
$params = [];
if ($q !== '') { $params['q'] = $q; }
if ($pagamento !== '') { $params['pagamento'] = $pagamento; }
if ($experiencia !== '') { $params['experiencia'] = $experiencia; }
if ($dataDe !== '') { $params['data_de'] = $dataDe; }
if ($dataAte !== '') { $params['data_ate'] = $dataAte; }
if ($indicador !== '') { $params['indicador'] = $indicador; }
?>
<div class="responsive-panel">
  <?php if (!empty($flashError)): ?>
    <div class="mb-4 rounded border border-red-200 bg-red-50 text-red-700 px-4 py-2 text-sm"><?= Security::e($flashError) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashSuccess)): ?>
    <div class="mb-4 rounded border border-ctdark bg-ctlight text-white px-4 py-2 text-sm"><?= Security::e($flashSuccess) ?></div>
  <?php endif; ?>
  <div class="responsive-header">
    <h2 class="text-xl font-semibold text-ctpblue">Programa de Indicações</h2>
    <div class="text-sm text-gray-500">Indicados cadastrados: <?= (int)$total ?></div>
  </div>

  <form class="responsive-form-grid-6 mt-4" method="get" action="<?= $queryBase ?>">
    <div class="md:col-span-2">
      <label class="block text-sm font-medium text-gray-700">Busca</label>
      <input type="text" name="q" value="<?= Security::e($q) ?>" placeholder="Nome do candidato ou vaga" class="mt-1 w-full border rounded px-3 py-2 text-sm" />
    </div>
    <div class="md:col-span-2">
      <label class="block text-sm font-medium text-gray-700">Indicador</label>
      <input type="text" name="indicador" value="<?= Security::e($indicador) ?>" placeholder="Nome do colaborador indicador" class="mt-1 w-full border rounded px-3 py-2 text-sm" />
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700">Pagamento</label>
      <select name="pagamento" class="mt-1 w-full border rounded px-3 py-2 text-sm">
        <option value="">Todos</option>
        <option value="pendente" <?= $pagamento === 'pendente' ? 'selected' : '' ?>>Pendente</option>
        <option value="pago" <?= $pagamento === 'pago' ? 'selected' : '' ?>>Pago</option>
        <option value="cancelado" <?= $pagamento === 'cancelado' ? 'selected' : '' ?>>Cancelado</option>
        <option value="em processo" <?= $pagamento === 'em processo' ? 'selected' : '' ?>>Em processo</option>
      </select>
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700">Experiência</label>
      <select name="experiencia" class="mt-1 w-full border rounded px-3 py-2 text-sm">
        <option value="">Todos</option>
        <option value="em_experiencia" <?= $experiencia === 'em_experiencia' ? 'selected' : '' ?>>Em experiência</option>
        <option value="concluida" <?= $experiencia === 'concluida' ? 'selected' : '' ?>>Concluída</option>
        <option value="nao_contratado" <?= $experiencia === 'nao_contratado' ? 'selected' : '' ?>>Não contratado</option>
      </select>
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700">De</label>
      <input type="date" name="data_de" value="<?= Security::e($dataDe) ?>" class="mt-1 w-full border rounded px-3 py-2 text-sm" />
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700">Até</label>
      <input type="date" name="data_ate" value="<?= Security::e($dataAte) ?>" class="mt-1 w-full border rounded px-3 py-2 text-sm" />
    </div>
    <div class="md:col-span-6">
      <div class="responsive-form-actions">
      <button class="bg-ctgreen text-white px-4 py-2 rounded hover:bg-ctdark text-sm">Filtrar</button>
      <a href="<?= $queryBase ?>" class="px-4 py-2 rounded border text-sm text-gray-600 hover:bg-gray-50">Limpar</a>
      <a href="<?= $queryBase . '/export?' . http_build_query(array_merge($params, ['format' => 'excel'])) ?>" class="px-4 py-2 rounded border text-sm text-ctgreen border-ctgreen hover:bg-ctgreen hover:text-white">Exportar Excel</a>
      </div>
    </div>
  </form>

  <div class="mobile-block-desktop mt-4 overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead class="bg-gray-50">
      <tr class="border-b">
        <th class="text-left p-3 font-medium text-gray-500">Nome</th>
        <th class="text-left p-3 font-medium text-gray-500">Colaborador</th>
        <th class="text-left p-3 font-medium text-gray-500">Vaga</th>
        <th class="text-left p-3 font-medium text-gray-500">Candidatura</th>
        <th class="text-left p-3 font-medium text-gray-500">Etapa atual</th>
        <th class="text-left p-3 font-medium text-gray-500">Contratado em</th>
        <th class="text-left p-3 font-medium text-gray-500">Tempo</th>
        <th class="text-left p-3 font-medium text-gray-500">Experiência (90 dias)</th>
        <th class="text-left p-3 font-medium text-gray-500">Pagamento</th>
        <th class="text-left p-3 font-medium text-gray-500">Ações</th>
      </tr>
      </thead>
      <tbody class="divide-y divide-gray-200">
      <?php foreach ($items as $item): ?>
        <?php
        $contratacao = $item['indicacao_data_contratacao'] ?? null;
        $fimExp = $item['indicacao_data_fim_experiencia'] ?? null;
        $dias = isset($item['dias_desde_contratacao']) ? (int)$item['dias_desde_contratacao'] : null;
        $expStatus = 'Não contratado';
        if (!empty($contratacao)) {
            $expStatus = (!empty($fimExp) && strtotime(date('Y-m-d')) > strtotime((string)$fimExp)) ? 'Experiência concluída' : 'Em experiência';
        }
        $pago = (int)($item['indicacao_pagamento_realizado'] ?? 0) === 1;
        $signal = $item['payment_signal'] ?? ['dot' => 'bg-gray-400', 'text' => 'text-gray-700'];
        $stageCor = $item['stage_cor'] ?? '#6b7280';
        $stageCorNormalized = strtolower(trim((string)$stageCor));
        if (in_array($stageCorNormalized, ['#10b981', '#059669', '#10e36b', '#057038', '#166534', '#14532d'], true)) {
            $stageCor = '#1d2d44';
        }
        ?>
        <tr class="hover:bg-gray-50">
          <td class="p-3 font-medium text-gray-900"><?= Security::e($item['nome']) ?></td>
          <td class="p-3 text-gray-700"><?= Security::e($item['indicacao_colaborador_nome'] ?? '-') ?></td>
          <td class="p-3 text-gray-700"><?= Security::e($item['vaga_titulo'] ?? '-') ?></td>
          <td class="p-3 text-gray-500"><?= !empty($item['created_at']) ? date('d/m/Y H:i', strtotime((string)$item['created_at'])) : '-' ?></td>
          <td class="p-3"><span class="px-2 py-1 rounded text-xs font-semibold text-white" style="background-color: <?= Security::e($stageCor) ?>"><?= Security::e($item['stage_nome'] ?? 'Novo') ?></span></td>
          <td class="p-3 text-gray-700"><?= !empty($contratacao) ? date('d/m/Y', strtotime((string)$contratacao)) : '-' ?></td>
          <td class="p-3 text-gray-700"><?= $dias !== null ? $dias . ' dias' : '-' ?></td>
          <td class="p-3 text-gray-700"><?= Security::e($expStatus) ?></td>
          <td class="p-3">
            <span class="inline-flex items-center gap-2 text-xs font-semibold <?= Security::e($signal['text'] ?? 'text-gray-700') ?>">
              <span class="h-2.5 w-2.5 rounded-full <?= Security::e($signal['dot'] ?? 'bg-gray-400') ?>"></span>
              <?= $pago ? 'Pago em ' . date('d/m/Y', strtotime((string)$item['indicacao_data_pagamento'])) : 'Pendente' ?>
            </span>
          </td>
          <td class="p-3 whitespace-nowrap">
            <a href="<?= $base ?>/admin/candidaturas/<?= (int)$item['id'] ?>" class="text-ctgreen hover:text-ctdark font-medium">Detalhes</a>
            <?php if (!$pago): ?>
              <form action="<?= $base ?>/admin/indicacoes/<?= (int)$item['id'] ?>/pagar" method="post" class="inline ml-2" data-pagamento-form="<?= (int)$item['id'] ?>">
                <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
                <input type="hidden" name="payment_date" value="" data-payment-date-hidden="<?= (int)$item['id'] ?>">
                <input type="hidden" name="payment_method" value="" data-payment-method-hidden="<?= (int)$item['id'] ?>">
                <button type="button" class="text-ctgreen hover:text-ctdark font-medium" data-pagamento-open="<?= (int)$item['id'] ?>">Marcar pago</button>
              </form>
            <?php else: ?>
              <form action="<?= $base ?>/admin/indicacoes/<?= (int)$item['id'] ?>/pagar/editar-data" method="post" class="inline ml-2" data-pagamento-edit-form="<?= (int)$item['id'] ?>">
                <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
                <input type="hidden" name="payment_date_edit" value="" data-payment-edit-date-hidden="<?= (int)$item['id'] ?>">
                <input type="hidden" name="payment_edit_reason" value="" data-payment-edit-reason-hidden="<?= (int)$item['id'] ?>">
                <button type="button" class="text-ctgreen hover:text-ctdark font-medium" data-pagamento-edit-open="<?= (int)$item['id'] ?>">Editar data</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($items)): ?>
        <tr>
          <td colspan="10" class="p-6 text-center text-gray-500">Nenhum candidato indicado encontrado.</td>
        </tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="responsive-card-list mt-4 md:hidden">
    <?php foreach ($items as $item): ?>
      <?php
      $contratacao = $item['indicacao_data_contratacao'] ?? null;
      $fimExp = $item['indicacao_data_fim_experiencia'] ?? null;
      $dias = isset($item['dias_desde_contratacao']) ? (int)$item['dias_desde_contratacao'] : null;
      $expStatus = 'Não contratado';
      if (!empty($contratacao)) {
          $expStatus = (!empty($fimExp) && strtotime(date('Y-m-d')) > strtotime((string)$fimExp)) ? 'Experiência concluída' : 'Em experiência';
      }
      $pago = (int)($item['indicacao_pagamento_realizado'] ?? 0) === 1;
      $signal = $item['payment_signal'] ?? ['dot' => 'bg-gray-400', 'text' => 'text-gray-700'];
      ?>
      <div class="responsive-card">
        <div class="font-semibold text-ctpblue"><?= Security::e($item['nome']) ?></div>
        <div class="text-sm text-gray-600">Indicado por: <?= Security::e($item['indicacao_colaborador_nome'] ?? '-') ?></div>
        <div class="text-sm text-gray-600"><?= Security::e($item['vaga_titulo'] ?? '-') ?></div>
        <div class="mt-2 text-xs text-gray-500">Etapa: <?= Security::e($item['stage_nome'] ?? 'Novo') ?></div>
        <div class="mt-1 text-xs text-gray-500">Contratado em: <?= !empty($contratacao) ? date('d/m/Y', strtotime((string)$contratacao)) : '-' ?></div>
        <div class="mt-1 text-xs text-gray-500">Tempo: <?= $dias !== null ? $dias . ' dias' : '-' ?></div>
        <div class="mt-1 text-xs text-gray-500">Experiência: <?= Security::e($expStatus) ?></div>
        <div class="mt-1 text-xs <?= Security::e($signal['text'] ?? 'text-gray-700') ?>">Pagamento: <span class="inline-flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full <?= Security::e($signal['dot'] ?? 'bg-gray-400') ?>"></span><?= $pago ? 'Pago em ' . date('d/m/Y', strtotime((string)$item['indicacao_data_pagamento'])) : 'Pendente' ?></span></div>
        <div class="responsive-card-actions mt-3 text-sm">
          <a href="<?= $base ?>/admin/candidaturas/<?= (int)$item['id'] ?>" class="text-ctgreen hover:text-ctdark font-medium">Detalhes</a>
          <?php if (!$pago): ?>
            <form action="<?= $base ?>/admin/indicacoes/<?= (int)$item['id'] ?>/pagar" method="post" data-pagamento-form="<?= (int)$item['id'] ?>">
              <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
              <input type="hidden" name="payment_date" value="" data-payment-date-hidden="<?= (int)$item['id'] ?>">
              <input type="hidden" name="payment_method" value="" data-payment-method-hidden="<?= (int)$item['id'] ?>">
              <button type="button" class="text-ctgreen hover:text-ctdark font-medium" data-pagamento-open="<?= (int)$item['id'] ?>">Marcar pago</button>
            </form>
          <?php else: ?>
            <form action="<?= $base ?>/admin/indicacoes/<?= (int)$item['id'] ?>/pagar/editar-data" method="post" data-pagamento-edit-form="<?= (int)$item['id'] ?>">
              <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
              <input type="hidden" name="payment_date_edit" value="" data-payment-edit-date-hidden="<?= (int)$item['id'] ?>">
              <input type="hidden" name="payment_edit_reason" value="" data-payment-edit-reason-hidden="<?= (int)$item['id'] ?>">
              <button type="button" class="text-ctgreen hover:text-ctdark font-medium" data-pagamento-edit-open="<?= (int)$item['id'] ?>">Editar data</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if (($pages ?? 1) > 1): ?>
    <div class="responsive-pagination mt-6 text-sm">
      <div class="text-gray-500">Página <?= (int)$page ?> de <?= (int)$pages ?></div>
      <div class="responsive-form-actions">
        <?php
        $prev = max(1, (int)$page - 1);
        $next = min((int)$pages, (int)$page + 1);
        $prevParams = array_merge($params, ['page' => $prev]);
        $nextParams = array_merge($params, ['page' => $next]);
        ?>
        <a href="<?= $queryBase . '?' . http_build_query($prevParams) ?>" class="px-3 py-1 border rounded <?= (int)$page <= 1 ? 'pointer-events-none opacity-50' : 'hover:bg-gray-50' ?>">Anterior</a>
        <a href="<?= $queryBase . '?' . http_build_query($nextParams) ?>" class="px-3 py-1 border rounded <?= (int)$page >= (int)$pages ? 'pointer-events-none opacity-50' : 'hover:bg-gray-50' ?>">Próxima</a>
      </div>
    </div>
  <?php endif; ?>
</div>
<style>
  .ind-modal-overlay { position: fixed; inset: 0; z-index: 50; display: none; align-items: flex-end; justify-content: center; background: rgba(0, 0, 0, 0.45); padding: 4vw; }
  .ind-modal-overlay.is-open { display: flex; }
  .ind-modal-panel { width: 100%; max-width: 42rem; max-height: 88vh; overflow-y: auto; background: #fff; border-radius: 1rem; box-shadow: 0 16px 32px rgba(0, 0, 0, 0.18); padding: 1rem; }
  .ind-modal-title { color: #0d1321; font-size: 1.25rem; line-height: 1.4; font-weight: 700; }
  .ind-modal-text { color: #4b5563; font-size: 0.9375rem; margin-top: 0.25rem; }
  .ind-modal-field { margin-top: 0.75rem; }
  .ind-modal-input { width: 100%; border: 1px solid #d1d5db; border-radius: 0.5rem; padding: 0.75rem; font-size: 1rem; min-height: 2.75rem; }
  .ind-modal-actions { position: sticky; bottom: 0; display: grid; grid-template-columns: 1fr; gap: 0.5rem; background: #fff; padding-top: 0.75rem; margin-top: 0.75rem; }
  .ind-modal-btn { min-height: 2.75rem; padding: 0.625rem 1rem; border-radius: 0.5rem; font-size: 0.9375rem; font-weight: 600; }
  .ind-modal-btn-secondary { border: 1px solid #9ca3af; color: #1f2937; background: #fff; }
  .ind-modal-btn-primary-green { color: #fff; background: #1d2d44; }
  .ind-modal-btn-primary-indigo { color: #fff; background: #1d2d44; }
  @media (min-width: 48rem) {
    .ind-modal-overlay { align-items: center; padding: 2rem; }
    .ind-modal-panel { padding: 1.25rem 1.5rem; max-height: 84vh; }
    .ind-modal-actions { grid-template-columns: 1fr 1fr; }
  }
  @media (min-width: 64rem) {
    .ind-modal-panel { max-width: 44rem; padding: 1.5rem; }
  }
</style>
<div id="pagamento-modal" class="ind-modal-overlay hidden" role="dialog" aria-modal="true" aria-labelledby="payment-modal-title" aria-describedby="payment-modal-desc">
  <div class="ind-modal-panel" tabindex="-1">
    <h3 id="payment-modal-title" class="ind-modal-title">Registrar pagamento</h3>
    <p id="payment-modal-desc" class="ind-modal-text">Informe a data efetiva do pagamento.</p>
    <div class="ind-modal-field">
      <label class="block text-sm font-medium text-gray-700" for="payment-date-input">Data do pagamento</label>
      <input id="payment-date-input" type="text" inputmode="numeric" maxlength="10" class="ind-modal-input" placeholder="DD/MM/AAAA">
      <p id="payment-date-error" class="text-red-700 text-xs mt-1 hidden" aria-live="polite"></p>
    </div>
    <div class="ind-modal-field">
      <label class="block text-sm font-medium text-gray-700" for="payment-method-input">Método de pagamento</label>
      <select id="payment-method-input" class="ind-modal-input">
        <option value="">Selecione</option>
        <option value="PIX">PIX</option>
        <option value="Transferência">Transferência</option>
        <option value="TED">TED</option>
        <option value="DOC">DOC</option>
        <option value="Dinheiro">Dinheiro</option>
        <option value="Outro">Outro</option>
      </select>
    </div>
    <div class="ind-modal-actions">
      <button type="button" id="payment-cancel" class="ind-modal-btn ind-modal-btn-secondary">Cancelar</button>
      <button type="button" id="payment-confirm" class="ind-modal-btn ind-modal-btn-primary-green">Confirmar</button>
    </div>
  </div>
</div>
<div id="pagamento-edit-modal" class="ind-modal-overlay hidden" role="dialog" aria-modal="true" aria-labelledby="payment-edit-modal-title" aria-describedby="payment-edit-modal-desc">
  <div class="ind-modal-panel" tabindex="-1">
    <h3 id="payment-edit-modal-title" class="ind-modal-title">Editar data de pagamento</h3>
    <p id="payment-edit-modal-desc" class="ind-modal-text">Atualize a data e informe o motivo da alteração.</p>
    <div class="ind-modal-field">
      <label class="block text-sm font-medium text-gray-700" for="payment-edit-date-input">Nova data (DD/MM/AAAA)</label>
      <input id="payment-edit-date-input" type="text" inputmode="numeric" maxlength="10" class="ind-modal-input" placeholder="DD/MM/AAAA">
    </div>
    <div class="ind-modal-field">
      <label class="block text-sm font-medium text-gray-700" for="payment-edit-reason-input">Motivo da alteração</label>
      <textarea id="payment-edit-reason-input" rows="3" class="ind-modal-input" placeholder="Descreva o motivo"></textarea>
      <p id="payment-edit-error" class="text-red-700 text-xs mt-1 hidden" aria-live="polite"></p>
    </div>
    <div class="ind-modal-actions">
      <button type="button" id="payment-edit-cancel" class="ind-modal-btn ind-modal-btn-secondary">Cancelar</button>
      <button type="button" id="payment-edit-confirm" class="ind-modal-btn ind-modal-btn-primary-indigo">Salvar alteração</button>
    </div>
  </div>
</div>
<script>
(() => {
  const modal = document.getElementById('pagamento-modal');
  const input = document.getElementById('payment-date-input');
  const error = document.getElementById('payment-date-error');
  const btnCancel = document.getElementById('payment-cancel');
  const btnConfirm = document.getElementById('payment-confirm');
  const paymentMethodInput = document.getElementById('payment-method-input');
  const editModal = document.getElementById('pagamento-edit-modal');
  const editDateInput = document.getElementById('payment-edit-date-input');
  const editReasonInput = document.getElementById('payment-edit-reason-input');
  const editError = document.getElementById('payment-edit-error');
  const editCancel = document.getElementById('payment-edit-cancel');
  const editConfirm = document.getElementById('payment-edit-confirm');
  const modalPanel = modal ? modal.querySelector('.ind-modal-panel') : null;
  const editModalPanel = editModal ? editModal.querySelector('.ind-modal-panel') : null;
  let activeForm = null;
  let activeEditForm = null;
  let lastFocusElement = null;

  const close = () => {
    modal.classList.add('hidden');
    modal.classList.remove('is-open');
    activeForm = null;
    error.classList.add('hidden');
    if (lastFocusElement) lastFocusElement.focus();
  };

  const closeEdit = () => {
    editModal.classList.add('hidden');
    editModal.classList.remove('is-open');
    activeEditForm = null;
    editError.classList.add('hidden');
    if (lastFocusElement) lastFocusElement.focus();
  };

  const open = (form) => {
    activeForm = form;
    lastFocusElement = document.activeElement;
    input.value = '';
    paymentMethodInput.value = '';
    error.classList.add('hidden');
    modal.classList.remove('hidden');
    modal.classList.add('is-open');
    setTimeout(() => (modalPanel || input).focus(), 10);
  };

  const openEdit = (form) => {
    activeEditForm = form;
    lastFocusElement = document.activeElement;
    editDateInput.value = '';
    editReasonInput.value = '';
    editError.classList.add('hidden');
    editModal.classList.remove('hidden');
    editModal.classList.add('is-open');
    setTimeout(() => (editModalPanel || editDateInput).focus(), 10);
  };

  const maskDate = (value) => {
    const only = value.replace(/\D/g, '').slice(0, 8);
    const p1 = only.slice(0, 2);
    const p2 = only.slice(2, 4);
    const p3 = only.slice(4, 8);
    if (only.length <= 2) return p1;
    if (only.length <= 4) return `${p1}/${p2}`;
    return `${p1}/${p2}/${p3}`;
  };

  const validateDate = (br) => {
    if (!/^\d{2}\/\d{2}\/\d{4}$/.test(br)) return 'Informe uma data válida no formato DD/MM/AAAA.';
    const [d, m, y] = br.split('/').map(Number);
    const dt = new Date(y, m - 1, d);
    if (dt.getFullYear() !== y || dt.getMonth() !== m - 1 || dt.getDate() !== d) return 'Data inválida.';
    const today = new Date();
    today.setHours(0,0,0,0);
    dt.setHours(0,0,0,0);
    if (dt > today) return 'A data de pagamento não pode ser futura.';
    const min = new Date(today);
    min.setDate(min.getDate() - 90);
    if (dt < min) return 'A data de pagamento não pode ser superior a 90 dias no passado.';
    return '';
  };

  input.addEventListener('input', () => {
    input.value = maskDate(input.value);
  });
  editDateInput.addEventListener('input', () => {
    editDateInput.value = maskDate(editDateInput.value);
  });

  document.querySelectorAll('[data-pagamento-open]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const id = btn.getAttribute('data-pagamento-open');
      const form = document.querySelector(`form[data-pagamento-form="${id}"]`);
      if (!form) return;
      open(form);
    });
  });
  document.querySelectorAll('[data-pagamento-edit-open]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const id = btn.getAttribute('data-pagamento-edit-open');
      const form = document.querySelector(`form[data-pagamento-edit-form="${id}"]`);
      if (!form) return;
      openEdit(form);
    });
  });

  btnCancel.addEventListener('click', close);
  editCancel.addEventListener('click', closeEdit);
  modal.addEventListener('click', (e) => {
    if (e.target === modal) close();
  });
  editModal.addEventListener('click', (e) => {
    if (e.target === editModal) closeEdit();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      if (modal.classList.contains('is-open')) close();
      if (editModal.classList.contains('is-open')) closeEdit();
    }
  });

  btnConfirm.addEventListener('click', () => {
    if (!activeForm) return;
    const formRef = activeForm;
    const value = (input.value || '').trim();
    const invalid = validateDate(value);
    if (invalid) {
      error.textContent = invalid;
      error.classList.remove('hidden');
      input.focus();
      return;
    }
    const target = formRef.querySelector('[data-payment-date-hidden]');
    const methodTarget = formRef.querySelector('[data-payment-method-hidden]');
    const methodValue = (paymentMethodInput.value || '').trim();
    if (!target || !methodTarget) return;
    if (!methodValue) {
      error.textContent = 'Informe o método de pagamento.';
      error.classList.remove('hidden');
      paymentMethodInput.focus();
      return;
    }
    target.value = value;
    methodTarget.value = methodValue;
    close();
    formRef.submit();
  });
  editConfirm.addEventListener('click', () => {
    if (!activeEditForm) return;
    const formRef = activeEditForm;
    const dateValue = (editDateInput.value || '').trim();
    const reasonValue = (editReasonInput.value || '').trim();
    const invalid = validateDate(dateValue);
    if (invalid) {
      editError.textContent = invalid;
      editError.classList.remove('hidden');
      editDateInput.focus();
      return;
    }
    if (!reasonValue) {
      editError.textContent = 'Informe o motivo da alteração.';
      editError.classList.remove('hidden');
      editReasonInput.focus();
      return;
    }
    const targetDate = formRef.querySelector('[data-payment-edit-date-hidden]');
    const targetReason = formRef.querySelector('[data-payment-edit-reason-hidden]');
    if (!targetDate || !targetReason) return;
    targetDate.value = dateValue;
    targetReason.value = reasonValue;
    closeEdit();
    formRef.submit();
  });
})();
</script>
