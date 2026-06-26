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
</div>
