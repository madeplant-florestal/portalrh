<?php
$dataAdmissao = '';
if (!empty($colaborador['data_admissao'])) {
    $dataAdmissao = DateHelper::formatBrazilianDate((string)$colaborador['data_admissao']);
}
if (!empty($colaborador['data_admissao']) && preg_match('/^\d{2}\/\d{2}\/\d{4}$/', (string)$colaborador['data_admissao'])) {
    $dataAdmissao = (string)$colaborador['data_admissao'];
}
$dataInicioCargo = '';
if (!empty($colaborador['data_inicio_cargo'])) {
    $dataInicioCargo = DateHelper::formatBrazilianDate((string)$colaborador['data_inicio_cargo']);
}
if (!empty($colaborador['data_inicio_cargo']) && preg_match('/^\d{2}\/\d{2}\/\d{4}$/', (string)$colaborador['data_inicio_cargo'])) {
    $dataInicioCargo = (string)$colaborador['data_inicio_cargo'];
}
$dataNascimento = '';
if (!empty($colaborador['data_nascimento'])) {
    $dataNascimento = DateHelper::formatBrazilianDate((string)$colaborador['data_nascimento']);
}
if (!empty($colaborador['data_nascimento']) && preg_match('/^\d{2}\/\d{2}\/\d{4}$/', (string)$colaborador['data_nascimento'])) {
    $dataNascimento = (string)$colaborador['data_nascimento'];
}
$dataDemissao = '';
if (!empty($colaborador['data_demissao'])) {
    $dataDemissao = DateHelper::formatBrazilianDate((string)$colaborador['data_demissao']);
}
if (!empty($colaborador['data_demissao']) && preg_match('/^\d{2}\/\d{2}\/\d{4}$/', (string)$colaborador['data_demissao'])) {
    $dataDemissao = (string)$colaborador['data_demissao'];
}
?>
<div class="responsive-panel max-w-3xl">
  <div class="responsive-header">
    <div>
      <h2 class="text-xl font-semibold text-ctpblue">Dados RH do colaborador</h2>
      <p class="mt-1 text-sm text-gray-500">Atualize matrícula, salário e datas de referência usadas nos formulários internos de RH.</p>
    </div>
    <a href="<?= $base ?>/admin/colaboradores" class="inline-flex items-center justify-center rounded-lg border border-gray-300 px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-50">Voltar</a>
  </div>

  <?php if (!empty($error)): ?>
    <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= Security::e($error) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashError)): ?>
    <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= Security::e($flashError) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashSuccess)): ?>
    <div class="mt-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700"><?= Security::e($flashSuccess) ?></div>
  <?php endif; ?>

  <div class="mt-6 rounded-xl border border-slate-200 bg-slate-50 p-4">
    <div class="text-lg font-semibold text-slate-900"><?= Security::e($colaborador['nome']) ?></div>
    <div class="mt-1 text-sm text-slate-600"><?= Security::e($colaborador['cargo_nome'] ?? '') ?><?= !empty($colaborador['setor_nome']) ? ' - ' . Security::e($colaborador['setor_nome']) : '' ?></div>
  </div>

  <form action="<?= $base ?>/admin/colaboradores/rh/editar/<?= (int)$colaborador['id'] ?>" method="post" class="mt-6 space-y-4">
    <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">

    <div class="grid gap-4 md:grid-cols-2">
      <div>
        <label class="block text-sm font-medium text-gray-700">Código *</label>
        <input type="text" name="codigo" value="<?= Security::e((string)($colaborador['codigo'] ?? '')) ?>" class="mt-1 w-full rounded border px-3 py-2" required>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Matrícula *</label>
        <input type="text" name="matricula" value="<?= Security::e((string)($colaborador['matricula'] ?? '')) ?>" class="mt-1 w-full rounded border px-3 py-2" required>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">CPF</label>
        <input type="text" name="cpf" value="<?= Security::e((string)($colaborador['cpf'] ?? '')) ?>" class="mt-1 w-full rounded border px-3 py-2" inputmode="numeric" maxlength="14" placeholder="Somente números ou CPF formatado">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Salário atual *</label>
        <input type="text" name="salario_atual" value="<?= !empty($colaborador['salario_atual']) ? 'R$ ' . number_format((float)$colaborador['salario_atual'], 2, ',', '.') : '' ?>" class="mt-1 w-full rounded border px-3 py-2" data-mask-money="1" required>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Data de admissão *</label>
        <input type="text" name="data_admissao" value="<?= Security::e($dataAdmissao) ?>" class="mt-1 w-full rounded border px-3 py-2" placeholder="DD/MM/AAAA" data-mask-date="1" required>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Data de início no cargo *</label>
        <input type="text" name="data_inicio_cargo" value="<?= Security::e($dataInicioCargo) ?>" class="mt-1 w-full rounded border px-3 py-2" placeholder="DD/MM/AAAA" data-mask-date="1" required>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Data de nascimento</label>
        <input type="text" name="data_nascimento" value="<?= Security::e($dataNascimento) ?>" class="mt-1 w-full rounded border px-3 py-2" placeholder="DD/MM/AAAA" data-mask-date="1">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Data de demissão</label>
        <input type="text" name="data_demissao" value="<?= Security::e($dataDemissao) ?>" class="mt-1 w-full rounded border px-3 py-2" placeholder="DD/MM/AAAA" data-mask-date="1">
      </div>
      <div class="md:col-span-2">
        <label class="block text-sm font-medium text-gray-700">Motivo da rescisão</label>
        <textarea name="motivo_rescisao" class="mt-1 w-full rounded border px-3 py-2" rows="3" placeholder="Informe o motivo quando houver demissão registrada"><?= Security::e((string)($colaborador['motivo_rescisao'] ?? '')) ?></textarea>
      </div>
    </div>

    <div class="grid gap-4 md:grid-cols-2">
      <div>
        <label class="block text-sm font-medium text-gray-700">Tempo de empresa</label>
        <input type="text" value="<?= Security::e((string)($colaborador['tempo_empresa_label'] ?? '')) ?>" class="mt-1 w-full rounded border bg-gray-50 px-3 py-2" readonly>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Tempo no cargo</label>
        <input type="text" value="<?= Security::e((string)($colaborador['tempo_cargo_label'] ?? '')) ?>" class="mt-1 w-full rounded border bg-gray-50 px-3 py-2" readonly>
      </div>
    </div>

    <div class="responsive-form-actions pt-2">
      <button type="submit" class="rounded-lg bg-ctgreen px-4 py-3 text-sm font-medium text-white hover:bg-ctdark">Salvar dados RH</button>
      <a href="<?= $base ?>/admin/colaboradores" class="text-sm font-medium text-ctpblue hover:text-ctgreen">Cancelar</a>
    </div>
  </form>

  <?php if (!empty($podeVerIntegracao)): ?>
  <?php
    $integracaoStatus = strtolower((string)($colaborador['integracao_status'] ?? 'pendente'));
    $integracaoData = '';
    if (!empty($colaborador['integracao_data'])) {
        $integracaoData = DateHelper::formatBrazilianDate((string)$colaborador['integracao_data']);
    }
  ?>
  <div class="mt-8 rounded-xl border border-gray-200 bg-gray-50 p-5">
    <h3 class="text-lg font-semibold text-ctpblue">Integração</h3>
    <p class="mt-1 text-sm text-gray-500">Controle operacional do onboarding — informação do Portal, não faz parte do METADADOS.</p>

    <?php if (!empty($podeEditarIntegracao)): ?>
    <form action="<?= $base ?>/admin/colaboradores/<?= (int)$colaborador['id'] ?>/integracao" method="post" class="mt-4 space-y-4" data-integracao-form="1">
      <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
      <div>
        <label class="block text-sm font-medium text-gray-700">Status</label>
        <select name="integracao_status" class="mt-1 w-full rounded border px-3 py-2 text-sm" data-integracao-status="1">
          <option value="pendente" <?= $integracaoStatus === 'pendente' ? 'selected' : '' ?>>Pendente</option>
          <option value="realizada" <?= $integracaoStatus === 'realizada' ? 'selected' : '' ?>>Realizada</option>
        </select>
      </div>
      <div data-integracao-campos="1" class="grid gap-4 md:grid-cols-2">
        <div>
          <label class="block text-sm font-medium text-gray-700">Data da integração</label>
          <input type="text" name="integracao_data" value="<?= Security::e($integracaoData) ?>" class="mt-1 w-full rounded border px-3 py-2 text-sm" placeholder="DD/MM/AAAA" data-mask-date="1">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Responsável</label>
          <select name="integracao_responsavel_usuario_id" class="mt-1 w-full rounded border px-3 py-2 text-sm">
            <option value="">— Selecione —</option>
            <?php foreach (($usuariosOptions ?? []) as $u): ?>
              <option value="<?= (int)$u['id'] ?>" <?= (int)($colaborador['integracao_responsavel_usuario_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>><?= Security::e($u['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <p class="text-xs text-gray-500">Ao marcar "Realizada", data e responsável são obrigatórios. A data não precisa ser a de hoje — registre a data em que a integração realmente ocorreu.</p>
      <button type="submit" class="rounded-lg bg-ctgreen px-4 py-3 text-sm font-medium text-white hover:bg-ctdark">Salvar integração</button>
    </form>
    <script>
      (() => {
        const select = document.querySelector('[data-integracao-status="1"]');
        const campos = document.querySelector('[data-integracao-campos="1"]');
        if (!select || !campos) return;
        const sync = () => { campos.classList.toggle('hidden', select.value !== 'realizada'); };
        select.addEventListener('change', sync);
        sync();
      })();
    </script>
    <?php else: ?>
      <div class="mt-4 grid gap-3 sm:grid-cols-3 text-sm">
        <div><span class="text-gray-500">Status:</span> <span class="font-medium text-gray-900"><?= $integracaoStatus === 'realizada' ? 'Realizada' : 'Pendente' ?></span></div>
        <div><span class="text-gray-500">Data:</span> <span class="font-medium text-gray-900"><?= Security::e($integracaoData ?: '-') ?></span></div>
        <div><span class="text-gray-500">Responsável:</span> <span class="font-medium text-gray-900"><?= Security::e((string)($colaborador['integracao_responsavel_nome'] ?? '-')) ?></span></div>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
