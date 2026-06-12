<?php
$isShow = ($mode ?? 'create') === 'show';
$currentRole = strtolower((string)($currentRole ?? 'viewer'));
$isSupervisor = !empty($isSupervisor);
$canEditRh = !empty($canEditRh);
$gestores = $dependencies['gestores'] ?? [];
$setores = $dependencies['setores'] ?? [];
$cargos = $dependencies['cargos'] ?? [];
$colaboradores = $dependencies['colaboradores'] ?? [];
$currentAccess = $dependencies['current_access'] ?? null;

$tipoLabels = [
    'merito' => 'Mérito (aumento salarial)',
    'promocao' => 'Promoção',
    'transferencia' => 'Transferência',
    'alteracao_funcao' => 'Alteração de função',
];
$orcamentoLabels = [
    'sim' => 'Sim',
    'nao' => 'Não',
    'em_validacao' => 'Em validação',
];
$posicaoLabels = [
    'extinta' => 'Extinta',
    'substituida' => 'Substituída',
];
$simNao = ['1' => 'Sim', '0' => 'Não'];
$statusLabels = [
    'rascunho' => 'Rascunho',
    'pendente_rh' => 'Pendente RH',
    'aprovada' => 'Aprovada',
];

$selectedGestorUserId = (int)($form['gestor_solicitante_usuario_id'] ?? ($currentAccess['usuario_id'] ?? 0));
$selectedColaboradorId = (int)($form['colaborador_id'] ?? 0);
$recordStatus = (string)($record['status_fluxo'] ?? ($form['status_fluxo'] ?? 'rascunho'));
$selectedManagerCanSign = $selectedGestorUserId > 0 && ($selectedGestorUserId === (int)$currentUserId || $isSupervisor);
$canEditDraft = !$isShow || ($recordStatus === 'rascunho' && ($selectedManagerCanSign || $canEditRh));
$canSignManager = $canEditDraft && $selectedManagerCanSign;
$canSignRh = $isShow && $recordStatus === 'pendente_rh' && $canEditRh;

$payload = [
    'gestores' => $gestores,
    'setores' => $setores,
    'cargos' => $cargos,
    'colaboradores' => $colaboradores,
];
?>
<div class="responsive-panel max-w-6xl" data-movimentacao-pessoal-form="1">
  <div class="responsive-header">
    <div>
      <h2 class="text-xl font-semibold text-ctpblue">
        <?= $isShow ? 'Movimentação de pessoal #' . (int)($record['id'] ?? 0) : 'Nova movimentação de pessoal' ?>
      </h2>
      <p class="mt-1 text-sm text-gray-500">Fluxo com rascunho, cálculo automático, assinatura do gestor solicitante e aprovação do RH.</p>
    </div>
    <a href="<?= $base ?>/admin/movimentacoes-pessoal" class="inline-flex items-center justify-center rounded-lg border border-gray-300 px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-50">Voltar</a>
  </div>

  <?php if (!empty($error)): ?>
    <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= Security::e($error) ?></div>
  <?php endif; ?>
  <?php if (!empty($success)): ?>
    <div class="mt-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700"><?= Security::e($success) ?></div>
  <?php endif; ?>

  <?php if ($isShow): ?>
    <div class="mt-4 grid gap-4 lg:grid-cols-4">
      <div class="rounded-xl border bg-white p-4 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Status</div>
        <div class="mt-2 text-lg font-semibold text-ctpblue"><?= Security::e($statusLabels[$recordStatus] ?? $recordStatus) ?></div>
      </div>
      <div class="rounded-xl border bg-white p-4 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Gestor solicitante</div>
        <div class="mt-2 text-sm font-semibold text-ctpblue"><?= Security::e($record['gestor_colaborador_nome'] ?? $record['gestor_usuario_nome'] ?? '-') ?></div>
      </div>
      <div class="rounded-xl border bg-white p-4 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Colaborador</div>
        <div class="mt-2 text-sm font-semibold text-ctpblue"><?= Security::e($record['colaborador_nome'] ?? '-') ?></div>
      </div>
      <div class="rounded-xl border bg-white p-4 shadow-sm">
        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Data da decisão</div>
        <div class="mt-2 text-sm font-semibold text-ctpblue"><?= Security::e($record['data_decisao_br'] ?? '-') ?></div>
      </div>
    </div>
  <?php endif; ?>

  <form class="mt-6 space-y-8" action="<?= $isShow ? $base . '/admin/movimentacoes-pessoal/' . (int)$record['id'] . '/editar' : $base . '/admin/movimentacoes-pessoal/nova' ?>" method="post" novalidate>
    <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
    <script type="application/json" data-movimentacao-payload="1"><?= json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>

    <section class="space-y-4">
      <div class="border-b pb-2">
        <h3 class="text-lg font-semibold text-ctpblue">1. Identificação da solicitação</h3>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Tipo de movimentação *</label>
        <div class="form-choice-group is-inline mt-2">
          <?php foreach ($tipoLabels as $value => $label): ?>
            <label class="form-choice-card text-sm <?= $canEditDraft ? '' : 'is-disabled' ?>">
              <input type="radio" name="tipo_movimentacao" value="<?= $value ?>" <?= ($form['tipo_movimentacao'] ?? '') === $value ? 'checked' : '' ?> <?= $canEditDraft ? 'required' : 'disabled' ?> data-movimentacao-tipo="1">
              <span><?= Security::e($label) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <div>
          <label class="block text-sm font-medium text-gray-700">Data da solicitação *</label>
          <input type="text" name="data_solicitacao" value="<?= Security::e($form['data_solicitacao_br'] ?? $form['data_solicitacao'] ?? date('d/m/Y')) ?>" class="mt-1 w-full rounded border px-3 py-2" placeholder="DD/MM/AAAA" data-mask-date="1" <?= $canEditDraft ? '' : 'disabled' ?>>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Gestor solicitante *</label>
          <select name="gestor_solicitante_usuario_id" class="mt-1 w-full rounded border px-3 py-2" data-movimentacao-gestor="1" <?= $canEditDraft ? '' : 'disabled' ?>>
            <option value="">Selecione</option>
            <?php foreach ($gestores as $gestor): ?>
              <option value="<?= (int)$gestor['usuario_id'] ?>" <?= (int)$selectedGestorUserId === (int)$gestor['usuario_id'] ? 'selected' : '' ?>>
                <?= Security::e($gestor['nome'] . ' - ' . $gestor['cargo_nome']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Área / Unidade *</label>
          <select name="setor_id" class="mt-1 w-full rounded border px-3 py-2" data-movimentacao-setor="1" <?= $canEditDraft ? '' : 'disabled' ?>>
            <option value="">Selecione</option>
            <?php foreach ($setores as $setor): ?>
              <option value="<?= (int)$setor['id'] ?>" <?= (int)($form['setor_id'] ?? 0) === (int)$setor['id'] ? 'selected' : '' ?>><?= Security::e($setor['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </section>

    <section class="space-y-4">
      <div class="border-b pb-2">
        <h3 class="text-lg font-semibold text-ctpblue">2. Dados do colaborador</h3>
      </div>
      <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <div class="md:col-span-2 xl:col-span-3">
          <label class="block text-sm font-medium text-gray-700">Nome *</label>
          <select name="colaborador_id" class="mt-1 w-full rounded border px-3 py-2" data-movimentacao-colaborador="1" <?= $canEditDraft ? '' : 'disabled' ?>>
            <option value="">Selecione</option>
            <?php foreach ($colaboradores as $colaborador): ?>
              <option value="<?= (int)$colaborador['id'] ?>" <?= (int)$selectedColaboradorId === (int)$colaborador['id'] ? 'selected' : '' ?>>
                <?= Security::e($colaborador['nome'] . ' - ' . $colaborador['cargo_nome']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Matrícula</label>
          <input type="text" name="matricula_snapshot" value="<?= Security::e((string)($form['matricula_snapshot'] ?? '')) ?>" class="mt-1 w-full rounded border bg-gray-50 px-3 py-2" readonly data-movimentacao-matricula="1">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Cargo atual</label>
          <input type="text" value="<?= Security::e((string)($record['cargo_atual_nome'] ?? '')) ?>" class="mt-1 w-full rounded border bg-gray-50 px-3 py-2" readonly data-movimentacao-cargo-atual-label="1">
          <input type="hidden" name="cargo_atual_id" value="<?= Security::e((string)($form['cargo_atual_id'] ?? '')) ?>" data-movimentacao-cargo-atual-id="1">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Tempo no cargo</label>
          <input type="text" value="<?= Security::e((string)($form['tempo_cargo_label'] ?? '')) ?>" class="mt-1 w-full rounded border bg-gray-50 px-3 py-2" readonly data-movimentacao-tempo-cargo="1">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Tempo de empresa</label>
          <input type="text" value="<?= Security::e((string)($form['tempo_empresa_label'] ?? '')) ?>" class="mt-1 w-full rounded border bg-gray-50 px-3 py-2" readonly data-movimentacao-tempo-empresa="1">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Salário atual</label>
          <input type="text" value="<?= !empty($form['salario_atual_snapshot']) ? 'R$ ' . number_format((float)$form['salario_atual_snapshot'], 2, ',', '.') : '' ?>" class="mt-1 w-full rounded border bg-gray-50 px-3 py-2" readonly data-movimentacao-salario-atual-label="1">
          <input type="hidden" name="salario_atual_snapshot" value="<?= Security::e((string)($form['salario_atual_snapshot'] ?? '')) ?>" data-movimentacao-salario-atual="1">
        </div>
      </div>
    </section>

    <section class="space-y-4">
      <div class="border-b pb-2">
        <h3 class="text-lg font-semibold text-ctpblue">3. Dados da movimentação proposta</h3>
      </div>
      <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <div class="hidden" data-movimentacao-novo-cargo-wrap="1">
          <label class="block text-sm font-medium text-gray-700">Novo cargo (se aplicável)</label>
          <select name="novo_cargo_id" class="mt-1 w-full rounded border px-3 py-2" <?= $canEditDraft ? '' : 'disabled' ?> data-movimentacao-novo-cargo="1">
            <option value="">Selecione</option>
            <?php foreach ($cargos as $cargo): ?>
              <option value="<?= (int)$cargo['id'] ?>" <?= (int)($form['novo_cargo_id'] ?? 0) === (int)$cargo['id'] ? 'selected' : '' ?>><?= Security::e($cargo['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="hidden" data-movimentacao-nova-area-wrap="1">
          <label class="block text-sm font-medium text-gray-700">Nova área (se aplicável)</label>
          <select name="nova_area_setor_id" class="mt-1 w-full rounded border px-3 py-2" <?= $canEditDraft ? '' : 'disabled' ?> data-movimentacao-nova-area="1">
            <option value="">Selecione</option>
            <?php foreach ($setores as $setor): ?>
              <option value="<?= (int)$setor['id'] ?>" <?= (int)($form['nova_area_setor_id'] ?? 0) === (int)$setor['id'] ? 'selected' : '' ?>><?= Security::e($setor['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Novo salário</label>
          <input type="text" name="novo_salario" value="<?= Security::e((string)($form['novo_salario'] ?? '')) ?>" class="mt-1 w-full rounded border px-3 py-2" placeholder="R$ 0,00" data-mask-money="1" <?= $canEditDraft ? '' : 'disabled' ?> data-movimentacao-novo-salario="1">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">% de aumento</label>
          <input type="text" value="<?= isset($form['percentual_aumento']) && $form['percentual_aumento'] !== '' ? number_format((float)$form['percentual_aumento'], 2, ',', '.') . '%' : '' ?>" class="mt-1 w-full rounded border bg-gray-50 px-3 py-2" readonly data-movimentacao-percentual="1">
          <input type="hidden" name="percentual_aumento" value="<?= Security::e((string)($form['percentual_aumento'] ?? '')) ?>">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Data prevista da mudança *</label>
          <input type="text" name="data_prevista_mudanca" value="<?= Security::e((string)($form['data_prevista_mudanca_br'] ?? $form['data_prevista_mudanca'] ?? '')) ?>" class="mt-1 w-full rounded border px-3 py-2" placeholder="DD/MM/AAAA" data-mask-date="1" <?= $canEditDraft ? '' : 'disabled' ?>>
        </div>
      </div>
    </section>

    <section class="space-y-4">
      <div class="border-b pb-2">
        <h3 class="text-lg font-semibold text-ctpblue">4. Justificativa da solicitação</h3>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Justificativa *</label>
        <textarea name="justificativa" rows="6" maxlength="2000" class="mt-1 w-full rounded border px-3 py-2" <?= $canEditDraft ? '' : 'disabled' ?>><?= Security::e((string)($form['justificativa'] ?? '')) ?></textarea>
        <p class="mt-1 text-xs text-gray-500">Inclua desempenho, entregas, evolução do colaborador e necessidades do negócio. Limite de 2000 caracteres.</p>
      </div>
    </section>

    <section class="space-y-4">
      <div class="border-b pb-2">
        <h3 class="text-lg font-semibold text-ctpblue">5. Evidências e resultados</h3>
      </div>
      <div class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700">Principais entregas dos últimos 6 meses *</label>
          <textarea name="entregas_ultimos_6_meses" rows="5" class="mt-1 w-full rounded border px-3 py-2" <?= $canEditDraft ? '' : 'disabled' ?>><?= Security::e((string)($form['entregas_ultimos_6_meses'] ?? '')) ?></textarea>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Resultados atingidos (indicadores, metas, projetos) *</label>
          <textarea name="resultados_atingidos" rows="5" class="mt-1 w-full rounded border px-3 py-2" <?= $canEditDraft ? '' : 'disabled' ?>><?= Security::e((string)($form['resultados_atingidos'] ?? '')) ?></textarea>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Avaliação de desempenho mais recente *</label>
          <select name="avaliacao_desempenho_id" class="mt-1 w-full rounded border px-3 py-2" data-movimentacao-avaliacao="1" <?= $canEditDraft ? '' : 'disabled' ?>>
            <option value="">Selecione</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">O colaborador está pronto para o próximo nível? Justifique *</label>
          <textarea name="pronto_proximo_nivel" rows="5" class="mt-1 w-full rounded border px-3 py-2" <?= $canEditDraft ? '' : 'disabled' ?>><?= Security::e((string)($form['pronto_proximo_nivel'] ?? '')) ?></textarea>
        </div>
      </div>
    </section>

    <section class="space-y-4">
      <div class="border-b pb-2">
        <h3 class="text-lg font-semibold text-ctpblue">6. Avaliação de competências</h3>
      </div>
      <div class="grid gap-4 xl:grid-cols-3">
        <div>
          <label class="block text-sm font-medium text-gray-700">Competências técnicas relevantes</label>
          <textarea name="competencias_tecnicas" rows="4" class="mt-1 w-full rounded border px-3 py-2" <?= $canEditDraft ? '' : 'disabled' ?>><?= Security::e((string)($form['competencias_tecnicas'] ?? '')) ?></textarea>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Competências comportamentais destacadas</label>
          <textarea name="competencias_comportamentais" rows="4" class="mt-1 w-full rounded border px-3 py-2" <?= $canEditDraft ? '' : 'disabled' ?>><?= Security::e((string)($form['competencias_comportamentais'] ?? '')) ?></textarea>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Pontos de desenvolvimento</label>
          <textarea name="pontos_desenvolvimento" rows="4" class="mt-1 w-full rounded border px-3 py-2" <?= $canEditDraft ? '' : 'disabled' ?>><?= Security::e((string)($form['pontos_desenvolvimento'] ?? '')) ?></textarea>
        </div>
      </div>
    </section>

    <section class="space-y-4">
      <div class="border-b pb-2">
        <h3 class="text-lg font-semibold text-ctpblue">7. Impacto financeiro</h3>
      </div>
      <div class="grid gap-4 lg:grid-cols-3">
        <div>
          <label class="block text-sm font-medium text-gray-700">Aumento mensal (R$)</label>
          <input type="text" value="<?= isset($form['aumento_mensal']) && $form['aumento_mensal'] !== '' ? 'R$ ' . number_format((float)$form['aumento_mensal'], 2, ',', '.') : '' ?>" class="mt-1 w-full rounded border bg-gray-50 px-3 py-2" readonly data-movimentacao-aumento-mensal="1">
          <input type="hidden" name="aumento_mensal" value="<?= Security::e((string)($form['aumento_mensal'] ?? '')) ?>">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Impacto anual (R$)</label>
          <input type="text" value="<?= isset($form['impacto_anual']) && $form['impacto_anual'] !== '' ? 'R$ ' . number_format((float)$form['impacto_anual'], 2, ',', '.') : '' ?>" class="mt-1 w-full rounded border bg-gray-50 px-3 py-2" readonly data-movimentacao-impacto-anual="1">
          <input type="hidden" name="impacto_anual" value="<?= Security::e((string)($form['impacto_anual'] ?? '')) ?>">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Existe orçamento aprovado? *</label>
          <select name="existe_orcamento_aprovado" class="mt-1 w-full rounded border px-3 py-2" <?= $canEditDraft ? '' : 'disabled' ?>>
            <option value="">Selecione</option>
            <?php foreach ($orcamentoLabels as $value => $label): ?>
              <option value="<?= $value ?>" <?= ($form['existe_orcamento_aprovado'] ?? '') === $value ? 'selected' : '' ?>><?= Security::e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </section>

    <section class="space-y-4">
      <div class="border-b pb-2">
        <h3 class="text-lg font-semibold text-ctpblue">8. Substituição / Estrutura</h3>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">A posição atual será: *</label>
        <div class="form-choice-group is-inline mt-2">
          <?php foreach ($posicaoLabels as $value => $label): ?>
            <label class="form-choice-card text-sm <?= $canEditDraft ? '' : 'is-disabled' ?>">
              <input type="radio" name="posicao_atual_sera" value="<?= $value ?>" <?= ($form['posicao_atual_sera'] ?? '') === $value ? 'checked' : '' ?> <?= $canEditDraft ? '' : 'disabled' ?> data-movimentacao-posicao="1">
              <span><?= Security::e($label) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="hidden grid gap-4 lg:grid-cols-2 rounded-xl border border-amber-200 bg-amber-50 p-4" data-movimentacao-substituida-wrap="1">
        <div>
          <label class="block text-sm font-medium text-gray-700">Já existe candidato interno?</label>
          <select name="existe_candidato_interno" class="mt-1 w-full rounded border px-3 py-2" <?= $canEditDraft ? '' : 'disabled' ?>>
            <option value="">Selecione</option>
            <?php foreach ($simNao as $value => $label): ?>
              <option value="<?= $value ?>" <?= (string)($form['existe_candidato_interno'] ?? '') === $value ? 'selected' : '' ?>><?= Security::e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Necessidade de recrutamento externo?</label>
          <select name="necessita_recrutamento_externo" class="mt-1 w-full rounded border px-3 py-2" <?= $canEditDraft ? '' : 'disabled' ?>>
            <option value="">Selecione</option>
            <?php foreach ($simNao as $value => $label): ?>
              <option value="<?= $value ?>" <?= (string)($form['necessita_recrutamento_externo'] ?? '') === $value ? 'selected' : '' ?>><?= Security::e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </section>

    <section class="space-y-4">
      <div class="border-b pb-2">
        <h3 class="text-lg font-semibold text-ctpblue">9. Riscos e considerações</h3>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Existe risco de perda do colaborador?</label>
        <div class="form-choice-group is-inline mt-2">
          <?php foreach ($simNao as $value => $label): ?>
            <label class="form-choice-card text-sm <?= $canEditDraft ? '' : 'is-disabled' ?>">
              <input type="radio" name="existe_risco_perda" value="<?= $value ?>" <?= (string)($form['existe_risco_perda'] ?? '') === $value ? 'checked' : '' ?> <?= $canEditDraft ? '' : 'disabled' ?> data-movimentacao-risco="1">
              <span><?= Security::e($label) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="hidden" data-movimentacao-impacto-wrap="1">
        <label class="block text-sm font-medium text-gray-700">Impacto caso não seja aprovado</label>
        <textarea name="impacto_nao_aprovado" rows="4" class="mt-1 w-full rounded border px-3 py-2" <?= $canEditDraft ? '' : 'disabled' ?>><?= Security::e((string)($form['impacto_nao_aprovado'] ?? '')) ?></textarea>
      </div>
    </section>

    <section class="space-y-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
      <div class="border-b pb-2">
        <h3 class="text-lg font-semibold text-ctpblue">10. Aprovações</h3>
      </div>
      <div class="grid gap-4 lg:grid-cols-3">
        <div class="rounded-lg border bg-white p-4">
          <div class="text-sm font-semibold text-gray-700">Gestor imediato</div>
          <div class="mt-2 text-sm text-gray-600">Assinatura: <?= !empty($record['gestor_assinado_em']) ? 'Registrada' : 'Pendente' ?></div>
          <div class="mt-1 text-sm text-gray-600">Data: <?= !empty($record['gestor_assinado_em']) ? Security::e(date('d/m/Y H:i', strtotime((string)$record['gestor_assinado_em']))) : 'Pendente' ?></div>
        </div>
        <div class="rounded-lg border bg-white p-4">
          <div class="text-sm font-semibold text-gray-700">RH</div>
          <div class="mt-2 text-sm text-gray-600">Assinatura: <?= !empty($record['rh_assinado_em']) ? 'Registrada' : 'Pendente' ?></div>
          <div class="mt-1 text-sm text-gray-600">Data: <?= !empty($record['rh_assinado_em']) ? Security::e(date('d/m/Y H:i', strtotime((string)$record['rh_assinado_em']))) : 'Pendente' ?></div>
        </div>
        <div class="rounded-lg border bg-white p-4">
          <div class="text-sm font-semibold text-gray-700">Data da decisão</div>
          <div class="mt-2 text-sm text-gray-600"><?= Security::e((string)($record['data_decisao_br'] ?? '-')) ?></div>
        </div>
      </div>
    </section>

    <?php if ($canEditDraft): ?>
      <div class="responsive-form-actions border-t pt-6">
        <button type="submit" name="submit_action" value="save_draft" class="rounded-lg border border-gray-300 px-5 py-3 text-sm font-medium text-gray-700 hover:bg-gray-50">Salvar rascunho</button>
        <?php if ($canSignManager): ?>
          <button type="submit" name="submit_action" value="sign_manager" class="rounded-lg bg-ctgreen px-5 py-3 text-sm font-medium text-white hover:bg-ctdark">Salvar e assinar como gestor</button>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </form>

  <?php if ($canSignRh): ?>
    <form action="<?= $base ?>/admin/movimentacoes-pessoal/<?= (int)$record['id'] ?>/assinar-rh" method="post" class="mt-6 rounded-xl border border-green-200 bg-green-50 p-4">
      <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
      <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h3 class="text-lg font-semibold text-ctpblue">Assinatura do RH</h3>
          <p class="mt-1 text-sm text-gray-600">Após a assinatura do RH, a data da decisão será registrada automaticamente e o fluxo será concluído.</p>
        </div>
        <button type="submit" class="rounded-lg bg-ctgreen px-5 py-3 text-sm font-medium text-white hover:bg-ctdark">Assinar como RH</button>
      </div>
    </form>
  <?php endif; ?>

  <?php if ($isShow): ?>
    <section class="mt-6 rounded-xl border bg-white p-5 shadow-sm">
      <h3 class="text-lg font-semibold text-ctpblue">Auditoria</h3>
      <div class="mt-4 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead>
            <tr class="border-b">
              <th class="p-3 text-left">Data</th>
              <th class="p-3 text-left">Usuário</th>
              <th class="p-3 text-left">Evento</th>
              <th class="p-3 text-left">Campo</th>
              <th class="p-3 text-left">De</th>
              <th class="p-3 text-left">Para</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach (($record['auditoria'] ?? []) as $audit): ?>
              <tr class="border-b">
                <td class="p-3"><?= Security::e(date('d/m/Y H:i', strtotime((string)$audit['created_at']))) ?></td>
                <td class="p-3"><?= Security::e($audit['actor_nome'] ?? 'Sistema') ?></td>
                <td class="p-3"><?= Security::e($audit['event_type']) ?></td>
                <td class="p-3"><?= Security::e($audit['field_name'] ?? '-') ?></td>
                <td class="p-3"><?= Security::e((string)($audit['old_value'] ?? '-')) ?></td>
                <td class="p-3"><?= Security::e((string)($audit['new_value'] ?? '-')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endif; ?>
</div>
