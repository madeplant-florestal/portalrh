<?php
require_once APP_PATH . '/views/partials/modulo-topo.php';
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

// Contrato oficial vinculado (colaboradores.metadados_id): matrícula/CPF/salário/datas/motivo de
// rescisão vêm do espelho colaboradores_metadados (ver Colaborador::mesclarComEspelhoOficial()) e
// são exibidos somente leitura — nunca editáveis aqui, nunca concorrem com o valor oficial. Só
// "Código" (sem equivalente no METADADOS) continua editável. Sem contrato oficial, o formulário
// completo continua exatamente como antes (colaborador 100% local/manual, ex.: PJ/terceiro).
$temExtensaoOficial = !empty($colaborador['tem_extensao_oficial']);
$readonlyClass = 'mt-1 w-full rounded border bg-surface-secondary px-3 py-2 text-text-primary';
?>
<div class="space-y-4">
  <?= ui_modulo_topo($base, 'pessoas', 'colaboradores', [
      'titulo' => 'Dados RH do colaborador',
      'descricao' => 'Atualize matrícula, salário e datas de referência usadas nos formulários internos de RH.',
  ], [['label' => (string)($colaborador['nome'] ?? 'Colaborador'), 'href' => null], ['label' => 'Dados RH', 'href' => null]]) ?>
  <div class="responsive-panel max-w-4xl">
  <?php if (!empty($error)): ?>
    <div class="mt-4 rounded-lg border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger"><?= Security::e($error) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashError)): ?>
    <div class="mt-4 rounded-lg border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger"><?= Security::e($flashError) ?></div>
  <?php endif; ?>
  <?php if (!empty($flashSuccess)): ?>
    <div class="mt-4 rounded-lg border border-success/30 bg-success/10 px-4 py-3 text-sm text-success"><?= Security::e($flashSuccess) ?></div>
  <?php endif; ?>

  <div class="mt-6 rounded-ds-md border border-border bg-surface-secondary p-4">
    <div class="text-lg font-semibold text-text-primary"><?= Security::e($colaborador['nome']) ?></div>
    <div class="mt-1 text-sm text-text-secondary"><?= Security::e($colaborador['cargo_nome'] ?? '') ?><?= !empty($colaborador['setor_nome']) ? ' - ' . Security::e($colaborador['setor_nome']) : '' ?></div>
  </div>

  <form action="<?= $base ?>/admin/colaboradores/rh/editar/<?= (int)$colaborador['id'] ?>" method="post" class="mt-6 space-y-4">
    <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">

    <div class="grid gap-4 md:grid-cols-2">
      <div>
        <label class="block text-sm font-medium text-text-primary">Código *</label>
        <input type="text" name="codigo" value="<?= Security::e((string)($colaborador['codigo'] ?? '')) ?>" class="mt-1 w-full rounded border px-3 py-2" required>
      </div>
      <div>
        <label class="block text-sm font-medium text-text-primary">Matrícula<?= $temExtensaoOficial ? ' (contrato oficial)' : ' *' ?></label>
        <?php if ($temExtensaoOficial): ?>
          <input type="text" value="<?= Security::e((string)($colaborador['matricula'] ?? '')) ?>" class="<?= $readonlyClass ?>" readonly>
        <?php else: ?>
          <input type="text" name="matricula" value="<?= Security::e((string)($colaborador['matricula'] ?? '')) ?>" class="mt-1 w-full rounded border px-3 py-2" required>
        <?php endif; ?>
      </div>
      <div>
        <label class="block text-sm font-medium text-text-primary">CPF<?= $temExtensaoOficial ? ' (oficial)' : '' ?></label>
        <?php if ($temExtensaoOficial): ?>
          <input type="text" value="<?= Security::e((string)($colaborador['cpf'] ?? '')) ?>" class="<?= $readonlyClass ?>" readonly>
        <?php else: ?>
          <input type="text" name="cpf" value="<?= Security::e((string)($colaborador['cpf'] ?? '')) ?>" class="mt-1 w-full rounded border px-3 py-2" inputmode="numeric" maxlength="14" placeholder="Somente números ou CPF formatado">
        <?php endif; ?>
      </div>
      <div>
        <label class="block text-sm font-medium text-text-primary">Salário atual<?= $temExtensaoOficial ? ' (oficial)' : ' *' ?></label>
        <?php if ($temExtensaoOficial): ?>
          <input type="text" value="<?= !empty($colaborador['salario_atual']) ? 'R$ ' . number_format((float)$colaborador['salario_atual'], 2, ',', '.') : '' ?>" class="<?= $readonlyClass ?>" readonly>
        <?php else: ?>
          <input type="text" name="salario_atual" value="<?= !empty($colaborador['salario_atual']) ? 'R$ ' . number_format((float)$colaborador['salario_atual'], 2, ',', '.') : '' ?>" class="mt-1 w-full rounded border px-3 py-2" data-mask-money="1" required>
        <?php endif; ?>
      </div>
      <div>
        <label class="block text-sm font-medium text-text-primary">Data de admissão<?= $temExtensaoOficial ? ' (oficial)' : ' *' ?></label>
        <?php if ($temExtensaoOficial): ?>
          <input type="text" value="<?= Security::e($dataAdmissao) ?>" class="<?= $readonlyClass ?>" readonly>
        <?php else: ?>
          <input type="text" name="data_admissao" value="<?= Security::e($dataAdmissao) ?>" class="mt-1 w-full rounded border px-3 py-2" placeholder="DD/MM/AAAA" data-mask-date="1" required>
        <?php endif; ?>
      </div>
      <div>
        <label class="block text-sm font-medium text-text-primary">Data de início no cargo<?= $temExtensaoOficial ? ' (oficial)' : ' *' ?></label>
        <?php if ($temExtensaoOficial): ?>
          <input type="text" value="<?= Security::e($dataInicioCargo) ?>" class="<?= $readonlyClass ?>" readonly>
        <?php else: ?>
          <input type="text" name="data_inicio_cargo" value="<?= Security::e($dataInicioCargo) ?>" class="mt-1 w-full rounded border px-3 py-2" placeholder="DD/MM/AAAA" data-mask-date="1" required>
        <?php endif; ?>
      </div>
      <div>
        <label class="block text-sm font-medium text-text-primary">Data de nascimento<?= $temExtensaoOficial ? ' (oficial)' : '' ?></label>
        <?php if ($temExtensaoOficial): ?>
          <input type="text" value="<?= Security::e($dataNascimento) ?>" class="<?= $readonlyClass ?>" readonly>
        <?php else: ?>
          <input type="text" name="data_nascimento" value="<?= Security::e($dataNascimento) ?>" class="mt-1 w-full rounded border px-3 py-2" placeholder="DD/MM/AAAA" data-mask-date="1">
        <?php endif; ?>
      </div>
      <div>
        <label class="block text-sm font-medium text-text-primary">Data de demissão<?= $temExtensaoOficial ? ' (oficial)' : '' ?></label>
        <?php if ($temExtensaoOficial): ?>
          <input type="text" value="<?= Security::e($dataDemissao) ?>" class="<?= $readonlyClass ?>" readonly>
        <?php else: ?>
          <input type="text" name="data_demissao" value="<?= Security::e($dataDemissao) ?>" class="mt-1 w-full rounded border px-3 py-2" placeholder="DD/MM/AAAA" data-mask-date="1">
        <?php endif; ?>
      </div>
      <div class="md:col-span-2">
        <label class="block text-sm font-medium text-text-primary">Motivo da rescisão<?= $temExtensaoOficial ? ' (oficial)' : '' ?></label>
        <?php if ($temExtensaoOficial): ?>
          <textarea class="<?= $readonlyClass ?>" rows="3" readonly><?= Security::e((string)($colaborador['motivo_rescisao'] ?? '')) ?></textarea>
        <?php else: ?>
          <textarea name="motivo_rescisao" class="mt-1 w-full rounded border px-3 py-2" rows="3" placeholder="Informe o motivo quando houver demissão registrada"><?= Security::e((string)($colaborador['motivo_rescisao'] ?? '')) ?></textarea>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($temExtensaoOficial): ?>
      <p class="text-xs text-text-secondary">Matrícula, CPF, salário, datas e motivo da rescisão vêm do contrato oficial sincronizado do METADADOS e não podem ser alterados aqui. Só "Código" é uma informação exclusiva do Portal.</p>
    <?php endif; ?>

    <div class="grid gap-4 md:grid-cols-2">
      <div>
        <label class="block text-sm font-medium text-text-primary">Tempo de empresa</label>
        <input type="text" value="<?= Security::e((string)($colaborador['tempo_empresa_label'] ?? '')) ?>" class="mt-1 w-full rounded border bg-surface-secondary px-3 py-2" readonly>
      </div>
      <div>
        <label class="block text-sm font-medium text-text-primary">Tempo no cargo</label>
        <input type="text" value="<?= Security::e((string)($colaborador['tempo_cargo_label'] ?? '')) ?>" class="mt-1 w-full rounded border bg-surface-secondary px-3 py-2" readonly>
      </div>
    </div>

    <div class="responsive-form-actions pt-2">
      <button type="submit" class="rounded-lg bg-primary-700 px-4 py-3 text-sm font-medium text-white hover:bg-primary-800">Salvar dados RH</button>
      <a href="<?= $base ?>/admin/colaboradores" class="text-sm font-medium text-text-primary hover:text-primary-700">Cancelar</a>
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
  <div class="mt-8 rounded-ds-md border border-border bg-surface-secondary p-5">
    <h3 class="text-lg font-semibold text-text-primary">Integração</h3>
    <p class="mt-1 text-sm text-text-secondary">Controle operacional do onboarding — informação do Portal, não faz parte do METADADOS.</p>

    <?php if (!empty($podeEditarIntegracao)): ?>
    <form action="<?= $base ?>/admin/colaboradores/<?= (int)$colaborador['id'] ?>/integracao" method="post" class="mt-4 space-y-4" data-integracao-form="1">
      <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
      <div>
        <label class="block text-sm font-medium text-text-primary">Status</label>
        <select name="integracao_status" class="mt-1 w-full rounded border px-3 py-2 text-sm" data-integracao-status="1">
          <option value="pendente" <?= $integracaoStatus === 'pendente' ? 'selected' : '' ?>>Pendente</option>
          <option value="realizada" <?= $integracaoStatus === 'realizada' ? 'selected' : '' ?>>Realizada</option>
        </select>
      </div>
      <div data-integracao-campos="1" class="grid gap-4 md:grid-cols-2">
        <div>
          <label class="block text-sm font-medium text-text-primary">Data da integração</label>
          <input type="text" name="integracao_data" value="<?= Security::e($integracaoData) ?>" class="mt-1 w-full rounded border px-3 py-2 text-sm" placeholder="DD/MM/AAAA" data-mask-date="1">
        </div>
        <div>
          <label class="block text-sm font-medium text-text-primary">Responsável</label>
          <select name="integracao_responsavel_usuario_id" class="mt-1 w-full rounded border px-3 py-2 text-sm">
            <option value="">— Selecione —</option>
            <?php foreach (($usuariosOptions ?? []) as $u): ?>
              <option value="<?= (int)$u['id'] ?>" <?= (int)($colaborador['integracao_responsavel_usuario_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>><?= Security::e($u['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <p class="text-xs text-text-secondary">Ao marcar "Realizada", data e responsável são obrigatórios. A data não precisa ser a de hoje — registre a data em que a integração realmente ocorreu.</p>
      <button type="submit" class="<?= ui_btn('primario') ?>">Salvar integração</button>
    </form>
    <?php ui_script_pagina('colaboradores.js'); // JS movido para assets/colaboradores.js (CSP: sem <script> inline) ?>
    <?php else: ?>
      <div class="mt-4 grid gap-3 sm:grid-cols-3 text-sm">
        <div><span class="text-text-secondary">Status:</span> <span class="font-medium text-text-primary"><?= $integracaoStatus === 'realizada' ? 'Realizada' : 'Pendente' ?></span></div>
        <div><span class="text-text-secondary">Data:</span> <span class="font-medium text-text-primary"><?= Security::e($integracaoData ?: '-') ?></span></div>
        <div><span class="text-text-secondary">Responsável:</span> <span class="font-medium text-text-primary"><?= Security::e((string)($colaborador['integracao_responsavel_nome'] ?? '-')) ?></span></div>
      </div>
    <?php endif; ?>

    <?php if ($integracaoStatus === 'realizada'): ?>
      <div class="mt-6 border-t border-border pt-5">
        <h4 class="text-sm font-semibold text-text-primary">Pesquisa de Integração</h4>
        <p class="mt-1 text-xs text-text-secondary">Pesquisa de onboarding respondida pelo colaborador — fonte futura do NPS/Satisfação da Integração. Independente da Pesquisa de Experiência do processo seletivo.</p>

        <?php if (empty($pesquisaIntegracao)): ?>
          <p class="mt-2 text-sm text-text-secondary">Nenhuma pesquisa gerada ainda para esta integração.</p>
          <?php if (!empty($podeEditarIntegracao)): ?>
            <form action="<?= $base ?>/admin/colaboradores/<?= (int)$colaborador['id'] ?>/pesquisa-integracao/gerar" method="post" class="mt-3">
              <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
              <button type="submit" class="rounded-lg bg-primary-700 px-4 py-2 text-sm font-medium text-white hover:bg-primary-800">Gerar pesquisa de integração</button>
            </form>
          <?php endif; ?>
        <?php else: ?>
          <?php if (!empty($linkPesquisaIntegracaoGerado)): ?>
            <div class="mt-3 rounded-lg border border-success/30 bg-success/10 p-3 text-sm text-success">
              <p class="font-medium">Pesquisa gerada. Copie o link agora — por segurança, ele não pode ser recuperado depois desta tela:</p>
              <input type="text" readonly value="<?= Security::e($linkPesquisaIntegracaoGerado) ?>" class="mt-2 w-full rounded border border-success/30 bg-white px-3 py-2 text-xs" data-select-on-click="1">
            </div>
          <?php endif; ?>
          <p class="mt-3 text-sm text-text-primary">
            Status:
            <span class="font-medium">
              <?= !empty($pesquisaIntegracao['respondida_em'])
                ? 'Respondida em ' . Security::e(date('d/m/Y H:i', strtotime((string)$pesquisaIntegracao['respondida_em'])))
                : 'Aguardando resposta' ?>
            </span>
          </p>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  </div>
</div>
