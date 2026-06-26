<?php
$isShow = ($mode ?? 'create') === 'show';
$currentRole = strtolower((string)($currentRole ?? 'viewer'));
$isSupervisor = !empty($_SESSION['user_is_supervisor']);
$currentAccess = $dependencies['current_access'] ?? null;
$setores = $dependencies['setores'] ?? [];
$cargos = $dependencies['cargos'] ?? [];
$gestores = $dependencies['gestores'] ?? [];
$centros = $dependencies['centros_custo'] ?? [];
$colaboradores = $dependencies['colaboradores'] ?? [];
$competencias = $dependencies['competencias'] ?? ['tecnica' => [], 'comportamental' => []];
$beneficiosByCargo = $dependencies['beneficios_by_cargo'] ?? [];
$schoolOptions = $dependencies['escolaridades'] ?? [];

$tipoVagaLabels = [
    'nova_posicao' => 'Nova posição',
    'substituicao' => 'Substituição',
    'aumento_quadro' => 'Aumento de quadro',
    'projeto_temporario' => 'Projeto temporário',
];
$tipoContratacaoLabels = [
    'clt' => 'CLT',
    'temporario' => 'Temporário',
    'terceiro' => 'Terceiro',
    'pj' => 'PJ',
];
$motivoSaidaLabels = [
    'desligamento' => 'Desligamento',
    'promocao' => 'Promoção',
    'transferencia' => 'Transferência',
    'outros' => 'Outros',
];
$turnoLabels = [
    'diurno' => 'Diurno',
    'noturno' => 'Noturno',
    'misto' => 'Misto',
];
$nivelLabels = [
    'operacional' => 'Operacional',
    'tecnico' => 'Técnico',
    'analitico' => 'Analítico',
    'estrategico' => 'Estratégico',
];
$urgenciaLabels = [
    'baixa' => 'Baixa',
    'media' => 'Média',
    'alta' => 'Alta',
    'critica' => 'Crítica',
];
$avaliacaoLabels = [
    'atendeu_plenamente' => 'Atendeu plenamente',
    'atendeu_parcialmente' => 'Atendeu parcialmente',
    'nao_atendeu' => 'Não atendeu',
];
$statusLabels = [
    'pendente_lider' => 'Pendente de líder imediato',
    'pendente_rh' => 'Pendente de RH',
    'aprovada' => 'Aprovada',
    'reprovada_lider' => 'Reprovada pelo líder',
    'reprovada_rh' => 'Reprovada pelo RH',
    'concluida' => 'Concluída',
];

$selectedBenefitIds = array_map('intval', $form['beneficio_ids'] ?? []);
$selectedTecnicaIds = array_map('intval', $form['competencia_tecnica_ids'] ?? []);
$selectedComportamentalIds = array_map('intval', $form['competencia_comportamental_ids'] ?? []);
$gestorLocked = !$isShow && !$canEditRh && is_array($currentAccess) && (int)($currentAccess['is_gestor'] ?? 0) === 1;
$defaultSetorId = $gestorLocked ? (int)($currentAccess['setor_id'] ?? 0) : (int)($form['setor_id'] ?? 0);
$defaultGestorId = $gestorLocked ? (int)($currentAccess['colaborador_id'] ?? 0) : (int)($form['gestor_solicitante_colaborador_id'] ?? 0);

$benefitCatalog = [];
foreach ($beneficiosByCargo as $benefitItems) {
    foreach ($benefitItems as $item) {
        $benefitCatalog[(int)$item['id']] = $item['nome'];
    }
}
asort($benefitCatalog);

$approvalMap = [];
foreach (($record['aprovacoes'] ?? []) as $approval) {
    $approvalMap[$approval['etapa']] = $approval;
}
$leaderApproval = $approvalMap['lider_imediato'] ?? null;
$rhApproval = $approvalMap['rh'] ?? null;
$canApproveLeader = $isShow
    && $leaderApproval
    && ($leaderApproval['status'] ?? '') === 'pendente'
    && ($isSupervisor || (int)($leaderApproval['destinatario_usuario_id'] ?? 0) === (int)$currentUserId);
$canApproveRh = $isShow
    && $rhApproval
    && ($rhApproval['status'] ?? '') === 'pendente'
    && ($leaderApproval['status'] ?? '') === 'aprovado'
    && ($isSupervisor || in_array($currentRole, ['admin', 'rh'], true));
$canEditRhSection = $isShow && $canEditRh && in_array((string)($record['status_fluxo'] ?? ''), ['aprovada', 'concluida'], true);

$payload = [
    'setores' => $setores,
    'cargos' => $cargos,
    'gestores' => $gestores,
    'centros_custo' => $centros,
    'beneficios_by_cargo' => $beneficiosByCargo,
];
?>
<div class="responsive-panel max-w-6xl" data-solicitacao-vaga-form="1">
  <div class="responsive-header">
    <div>
      <h2 class="text-xl font-semibold text-ctpblue">
        <?= $isShow ? 'Solicitação de vaga #' . (int)($record['id'] ?? 0) : 'Nova solicitação de vaga' ?>
      </h2>
      <p class="mt-1 text-sm text-gray-500">
        <?= $isShow ? 'Acompanhe aprovações, rastreabilidade e controle interno do RH.' : 'Preencha o formulário completo com base nos cadastros oficiais da empresa.' ?>
      </p>
    </div>
    <div class="flex flex-wrap gap-2">
      <a href="<?= $base ?>/admin/solicitacoes-vaga" class="inline-flex items-center justify-center rounded-lg border border-gray-300 px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-50">Voltar</a>
    </div>
  </div>

  <?php if (!empty($error)): ?>
    <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= Security::e($error) ?></div>
  <?php endif; ?>
  <?php if (!empty($success)): ?>
    <div class="mt-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700"><?= Security::e($success) ?></div>
  <?php endif; ?>

  <?php if (!$isShow): ?>
    <form class="mt-6 space-y-8" action="<?= $base ?>/admin/solicitacoes-vaga/nova" method="post" novalidate data-solicitacao-form-element="1">
      <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
      <script type="application/json" data-solicitacao-payload="1"><?= json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>

      <?php if ($gestorLocked): ?>
        <input type="hidden" name="setor_id" value="<?= (int)$defaultSetorId ?>">
        <input type="hidden" name="gestor_solicitante_colaborador_id" value="<?= (int)$defaultGestorId ?>">
      <?php endif; ?>

      <section class="space-y-4">
        <div class="border-b pb-2">
          <h3 class="text-lg font-semibold text-ctpblue">1. Identificação da vaga</h3>
        </div>
        <div class="grid gap-4 lg:grid-cols-2">
          <div>
            <label class="block text-sm font-medium text-gray-700">Área / Departamento *</label>
            <select name="setor_id" class="mt-1 w-full rounded border px-3 py-2" data-solicitacao-setor="1" <?= $gestorLocked ? 'disabled' : 'required' ?>>
              <option value="">Selecione</option>
              <?php foreach ($setores as $setor): ?>
                <option value="<?= (int)$setor['id'] ?>" <?= (int)$defaultSetorId === (int)$setor['id'] ? 'selected' : '' ?>><?= Security::e($setor['nome']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Quantidade de vagas *</label>
            <input type="number" min="1" name="quantidade_vagas" value="<?= Security::e((string)($form['quantidade_vagas'] ?? 1)) ?>" required class="mt-1 w-full rounded border px-3 py-2">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Cargo *</label>
            <select name="cargo_id" class="mt-1 w-full rounded border px-3 py-2" data-solicitacao-cargo="1" aria-describedby="solicitacao-cargo-feedback solicitacao-cargo-faixa" required>
              <option value="">Selecione</option>
              <?php foreach ($cargos as $cargo): ?>
                <option value="<?= (int)$cargo['id'] ?>" <?= (int)($form['cargo_id'] ?? 0) === (int)$cargo['id'] ? 'selected' : '' ?>><?= Security::e($cargo['nome']) ?></option>
              <?php endforeach; ?>
            </select>
            <p id="solicitacao-cargo-feedback" class="mt-1 text-xs text-gray-500" data-solicitacao-cargo-feedback="1" aria-live="polite"></p>
            <p id="solicitacao-cargo-faixa" class="mt-1 text-xs text-gray-500" data-solicitacao-faixa-label="1" aria-live="polite"></p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Gestor solicitante *</label>
            <select name="gestor_solicitante_colaborador_id" class="mt-1 w-full rounded border px-3 py-2" data-solicitacao-gestor="1" <?= $gestorLocked ? 'disabled' : 'required' ?>>
              <option value="">Selecione</option>
              <?php foreach ($gestores as $gestor): ?>
                <option value="<?= (int)$gestor['colaborador_id'] ?>" <?= (int)$defaultGestorId === (int)$gestor['colaborador_id'] ? 'selected' : '' ?>>
                  <?= Security::e($gestor['nome'] . ' - ' . $gestor['cargo_nome']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="hidden" data-solicitacao-maquina-wrap="1">
          <label class="block text-sm font-medium text-gray-700">Se operador de Máquinas Florestais descreva qual máquina irá operar</label>
          <input type="text" name="maquina_operada" value="<?= Security::e($form['maquina_operada'] ?? '') ?>" class="mt-1 w-full rounded border px-3 py-2" data-solicitacao-maquina-input="1">
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700">Tipo de vaga *</label>
          <div class="form-choice-group is-inline mt-2">
            <?php foreach ($tipoVagaLabels as $value => $label): ?>
              <label class="form-choice-card text-sm">
                <input type="radio" name="tipo_vaga" value="<?= $value ?>" <?= ($form['tipo_vaga'] ?? '') === $value ? 'checked' : '' ?> required data-solicitacao-tipo-vaga="1">
                <span><?= Security::e($label) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="hidden space-y-4 rounded-xl border border-amber-200 bg-amber-50 p-4" data-solicitacao-substituicao-wrap="1">
          <h4 class="font-medium text-amber-900">Campos obrigatórios para substituição</h4>
          <div class="grid gap-4 lg:grid-cols-2">
            <div>
              <label class="block text-sm font-medium text-gray-700">Nome do colaborador substituído</label>
              <select name="colaborador_substituido_id" class="mt-1 w-full rounded border px-3 py-2" data-solicitacao-colaborador-substituido="1">
                <option value="">Selecione</option>
                <?php foreach ($colaboradores as $colaborador): ?>
                  <option value="<?= (int)$colaborador['id'] ?>" <?= (int)($form['colaborador_substituido_id'] ?? 0) === (int)$colaborador['id'] ? 'selected' : '' ?>>
                    <?= Security::e($colaborador['nome'] . ' - ' . $colaborador['cargo_nome']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Data do desligamento</label>
              <input type="text" name="data_desligamento" value="<?= Security::e($form['data_desligamento'] ?? '') ?>" class="mt-1 w-full rounded border px-3 py-2" placeholder="DD/MM/AAAA" data-mask-date="1" data-solicitacao-data-desligamento="1">
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Motivo da saída</label>
            <div class="form-choice-group is-inline is-compact mt-2">
              <?php foreach ($motivoSaidaLabels as $value => $label): ?>
                <label class="form-choice-card text-sm">
                  <input type="radio" name="motivo_saida" value="<?= $value ?>" <?= ($form['motivo_saida'] ?? '') === $value ? 'checked' : '' ?> data-solicitacao-motivo-saida="1">
                  <span><?= Security::e($label) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="hidden" data-solicitacao-motivo-outros-wrap="1">
            <label class="block text-sm font-medium text-gray-700">Complemento do motivo</label>
            <input type="text" name="motivo_saida_outros" value="<?= Security::e($form['motivo_saida_outros'] ?? '') ?>" class="mt-1 w-full rounded border px-3 py-2" data-solicitacao-motivo-outros="1">
          </div>
        </div>
      </section>

      <section class="space-y-4">
        <div class="border-b pb-2">
          <h3 class="text-lg font-semibold text-ctpblue">2. Informações contratuais</h3>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Tipo de contratação *</label>
          <div class="form-choice-group is-inline mt-2">
            <?php foreach ($tipoContratacaoLabels as $value => $label): ?>
              <label class="form-choice-card text-sm">
                <input type="radio" name="tipo_contratacao" value="<?= $value ?>" <?= ($form['tipo_contratacao'] ?? '') === $value ? 'checked' : '' ?> required>
                <span><?= Security::e($label) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="form-inline-grid form-inline-grid-3">
          <div>
            <label class="block text-sm font-medium text-gray-700">Salário previsto *</label>
            <input type="text" name="salario_previsto" value="<?= Security::e($form['salario_previsto'] ?? '') ?>" class="mt-1 w-full rounded border px-3 py-2" placeholder="R$ 0,00" required data-mask-money="1" data-solicitacao-salario="1">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Centro de custo *</label>
            <select name="centro_custo_id" class="mt-1 w-full rounded border px-3 py-2" data-solicitacao-centro-custo="1" required>
              <option value="">Selecione</option>
              <?php foreach ($centros as $centro): ?>
                <option value="<?= (int)$centro['id'] ?>" <?= (int)($form['centro_custo_id'] ?? 0) === (int)$centro['id'] ? 'selected' : '' ?>>
                  <?= Security::e($centro['codigo'] . ' - ' . $centro['nome']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Previsto no orçamento anual? *</label>
            <div class="form-choice-group is-inline mt-2">
              <label class="form-choice-card text-sm">
                <input type="radio" name="previsto_orcamento" value="1" <?= (string)($form['previsto_orcamento'] ?? '') === '1' ? 'checked' : '' ?> required data-solicitacao-orcamento="1">
                <span>Sim</span>
              </label>
              <label class="form-choice-card text-sm">
                <input type="radio" name="previsto_orcamento" value="0" <?= (string)($form['previsto_orcamento'] ?? '') === '0' ? 'checked' : '' ?> required data-solicitacao-orcamento="1">
                <span>Não</span>
              </label>
            </div>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700">Benefícios aplicáveis</label>
          <div class="mt-2 grid gap-3 md:grid-cols-2 xl:grid-cols-3" data-solicitacao-beneficios-wrap="1">
            <?php foreach ($benefitCatalog as $benefitId => $benefitName): ?>
              <label class="flex items-start gap-2 rounded border px-3 py-2 text-sm" data-beneficio-item="1">
                <input type="checkbox" name="beneficio_ids[]" value="<?= (int)$benefitId ?>" <?= in_array((int)$benefitId, $selectedBenefitIds, true) ? 'checked' : '' ?> data-beneficio-id="<?= (int)$benefitId ?>">
                <span><?= Security::e($benefitName) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="hidden" data-solicitacao-justificativa-wrap="1">
          <label class="block text-sm font-medium text-gray-700">Justificativa caso não esteja previsto</label>
          <textarea name="justificativa_orcamento" rows="4" class="mt-1 w-full rounded border px-3 py-2" data-solicitacao-justificativa="1"><?= Security::e($form['justificativa_orcamento'] ?? '') ?></textarea>
        </div>
      </section>

      <section class="space-y-4">
        <div class="border-b pb-2">
          <h3 class="text-lg font-semibold text-ctpblue">3. Jornada e escala</h3>
        </div>
        <div class="grid gap-4 lg:grid-cols-2">
          <div>
            <label class="block text-sm font-medium text-gray-700">Jornada de trabalho *</label>
            <input type="text" name="jornada_trabalho" value="<?= Security::e($form['jornada_trabalho'] ?? '') ?>" class="mt-1 w-full rounded border px-3 py-2" placeholder="Ex.: 44h semanais" required>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Escala (se aplicável)</label>
            <input type="text" name="escala" value="<?= Security::e($form['escala'] ?? '') ?>" class="mt-1 w-full rounded border px-3 py-2" placeholder="Ex.: 5x1, 12x36">
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Turno</label>
          <div class="form-choice-group is-inline mt-2">
            <?php foreach ($turnoLabels as $value => $label): ?>
              <label class="form-choice-card text-sm">
                <input type="radio" name="turno" value="<?= $value ?>" <?= ($form['turno'] ?? '') === $value ? 'checked' : '' ?>>
                <span><?= Security::e($label) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      </section>

      <section class="space-y-4">
        <div class="border-b pb-2">
          <h3 class="text-lg font-semibold text-ctpblue">4. Perfil da vaga</h3>
        </div>
        <div class="grid gap-4 lg:grid-cols-2">
          <div>
            <label class="block text-sm font-medium text-gray-700">Escolaridade mínima *</label>
            <select name="escolaridade_minima" class="mt-1 w-full rounded border px-3 py-2" required>
              <option value="">Selecione</option>
              <?php foreach ($schoolOptions as $value => $label): ?>
                <option value="<?= $value ?>" <?= ($form['escolaridade_minima'] ?? '') === $value ? 'selected' : '' ?>><?= Security::e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Formação acadêmica</label>
            <input type="text" name="formacao_academica" value="<?= Security::e($form['formacao_academica'] ?? '') ?>" class="mt-1 w-full rounded border px-3 py-2">
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Experiência necessária</label>
          <textarea name="experiencia_necessaria" rows="4" class="mt-1 w-full rounded border px-3 py-2"><?= Security::e($form['experiencia_necessaria'] ?? '') ?></textarea>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Entregas esperadas da Função *</label>
          <textarea name="entregas_esperadas" rows="6" minlength="100" class="mt-1 w-full rounded border px-3 py-2" required><?= Security::e($form['entregas_esperadas'] ?? '') ?></textarea>
          <p class="mt-1 text-xs text-gray-500">Descreva os principais resultados esperados para a função com no mínimo 100 caracteres.</p>
        </div>
        <div class="grid gap-4 xl:grid-cols-2">
          <div>
            <label class="block text-sm font-medium text-gray-700">Competências técnicas</label>
            <div class="mt-2 grid gap-3">
              <?php foreach ($competencias['tecnica'] as $competencia): ?>
                <label class="flex items-start gap-2 rounded border px-3 py-2 text-sm">
                  <input type="checkbox" name="competencia_tecnica_ids[]" value="<?= (int)$competencia['id'] ?>" <?= in_array((int)$competencia['id'], $selectedTecnicaIds, true) ? 'checked' : '' ?>>
                  <span><?= Security::e($competencia['nome']) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Competências comportamentais</label>
            <div class="mt-2 grid gap-3">
              <?php foreach ($competencias['comportamental'] as $competencia): ?>
                <label class="flex items-start gap-2 rounded border px-3 py-2 text-sm">
                  <input type="checkbox" name="competencia_comportamental_ids[]" value="<?= (int)$competencia['id'] ?>" <?= in_array((int)$competencia['id'], $selectedComportamentalIds, true) ? 'checked' : '' ?>>
                  <span><?= Security::e($competencia['nome']) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Nível de responsabilidade *</label>
          <div class="form-choice-group is-inline mt-2">
            <?php foreach ($nivelLabels as $value => $label): ?>
              <label class="form-choice-card text-sm">
                <input type="radio" name="nivel_responsabilidade" value="<?= $value ?>" <?= ($form['nivel_responsabilidade'] ?? '') === $value ? 'checked' : '' ?> required>
                <span><?= Security::e($label) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      </section>

      <section class="space-y-4">
        <div class="border-b pb-2">
          <h3 class="text-lg font-semibold text-ctpblue">5. Prazos e prioridade</h3>
        </div>
        <div class="grid gap-4 lg:grid-cols-2">
          <div>
            <label class="block text-sm font-medium text-gray-700">Data prevista para início *</label>
            <input type="text" name="data_prevista_inicio" value="<?= Security::e($form['data_prevista_inicio'] ?? '') ?>" class="mt-1 w-full rounded border px-3 py-2" placeholder="DD/MM/AAAA" required data-mask-date="1">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Data limite desejada para fechamento</label>
            <input type="text" name="data_limite_fechamento" value="<?= Security::e($form['data_limite_fechamento'] ?? '') ?>" class="mt-1 w-full rounded border px-3 py-2" placeholder="DD/MM/AAAA" data-mask-date="1">
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Urgência *</label>
          <div class="form-choice-group is-inline mt-2">
            <?php foreach ($urgenciaLabels as $value => $label): ?>
              <label class="form-choice-card text-sm">
                <input type="radio" name="urgencia" value="<?= $value ?>" <?= ($form['urgencia'] ?? '') === $value ? 'checked' : '' ?> required>
                <span><?= Security::e($label) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      </section>

      <section class="rounded-xl border border-slate-200 bg-slate-50 p-4">
        <h3 class="text-lg font-semibold text-ctpblue">6. Aprovações</h3>
        <p class="mt-2 text-sm text-gray-600">Os registros de Líder Imediato e RH são preenchidos automaticamente conforme o fluxo de aprovação do sistema.</p>
      </section>

      <section class="rounded-xl border border-slate-200 bg-slate-50 p-4">
        <h3 class="text-lg font-semibold text-ctpblue">7. Controle interno RH</h3>
        <p class="mt-2 text-sm text-gray-600">Esta seção ficará disponível apenas para usuários com perfil de RH após as aprovações formais da solicitação.</p>
      </section>

      <div class="responsive-form-actions border-t pt-6">
        <button type="submit" class="rounded-lg bg-ctgreen px-5 py-3 text-sm font-medium text-white hover:bg-ctdark">Enviar solicitação</button>
        <a href="<?= $base ?>/admin/solicitacoes-vaga" class="text-sm font-medium text-ctpblue hover:text-ctgreen">Cancelar</a>
      </div>
    </form>
  <?php else: ?>
    <div class="mt-6 space-y-8">
      <section class="grid gap-4 xl:grid-cols-3">
        <div class="rounded-xl border bg-white p-4 shadow-sm">
          <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Status do fluxo</div>
          <div class="mt-2 text-lg font-semibold text-ctpblue"><?= Security::e($statusLabels[$record['status_fluxo']] ?? $record['status_fluxo']) ?></div>
          <div class="mt-2 text-sm text-gray-500">Enviado em <?= Security::e(date('d/m/Y H:i', strtotime((string)$record['created_at']))) ?></div>
        </div>
        <div class="rounded-xl border bg-white p-4 shadow-sm">
          <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Área / Cargo</div>
          <div class="mt-2 text-lg font-semibold text-ctpblue"><?= Security::e($record['setor_nome']) ?></div>
          <div class="mt-2 text-sm text-gray-600"><?= Security::e($record['cargo_nome']) ?></div>
        </div>
        <div class="rounded-xl border bg-white p-4 shadow-sm">
          <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Gestor solicitante</div>
          <div class="mt-2 text-lg font-semibold text-ctpblue"><?= Security::e($record['gestor_nome']) ?></div>
          <div class="mt-2 text-sm text-gray-600">Quantidade de vagas: <?= (int)$record['quantidade_vagas'] ?></div>
        </div>
      </section>

      <section class="grid gap-6 xl:grid-cols-2">
        <div class="rounded-xl border bg-white p-5 shadow-sm">
          <h3 class="text-lg font-semibold text-ctpblue">1. Identificação da vaga</h3>
          <dl class="mt-4 space-y-3 text-sm">
            <div><dt class="font-semibold text-gray-700">Área / Departamento</dt><dd class="text-gray-600"><?= Security::e($record['setor_nome']) ?></dd></div>
            <div><dt class="font-semibold text-gray-700">Cargo</dt><dd class="text-gray-600"><?= Security::e($record['cargo_nome']) ?></dd></div>
            <div><dt class="font-semibold text-gray-700">Gestor solicitante</dt><dd class="text-gray-600"><?= Security::e($record['gestor_nome']) ?></dd></div>
            <div><dt class="font-semibold text-gray-700">Tipo de vaga</dt><dd class="text-gray-600"><?= Security::e($tipoVagaLabels[$record['tipo_vaga']] ?? $record['tipo_vaga']) ?></dd></div>
            <?php if (!empty($record['maquina_operada'])): ?><div><dt class="font-semibold text-gray-700">Máquina a operar</dt><dd class="text-gray-600"><?= Security::e($record['maquina_operada']) ?></dd></div><?php endif; ?>
            <?php if (!empty($record['substituido_nome'])): ?><div><dt class="font-semibold text-gray-700">Colaborador substituído</dt><dd class="text-gray-600"><?= Security::e($record['substituido_nome']) ?></dd></div><?php endif; ?>
            <?php if (!empty($record['data_desligamento_br'])): ?><div><dt class="font-semibold text-gray-700">Data do desligamento</dt><dd class="text-gray-600"><?= Security::e($record['data_desligamento_br']) ?></dd></div><?php endif; ?>
          </dl>
        </div>

        <div class="rounded-xl border bg-white p-5 shadow-sm">
          <h3 class="text-lg font-semibold text-ctpblue">2. Informações contratuais</h3>
          <dl class="mt-4 space-y-3 text-sm">
            <div><dt class="font-semibold text-gray-700">Tipo de contratação</dt><dd class="text-gray-600"><?= Security::e($tipoContratacaoLabels[$record['tipo_contratacao']] ?? $record['tipo_contratacao']) ?></dd></div>
            <div><dt class="font-semibold text-gray-700">Salário previsto</dt><dd class="text-gray-600">R$ <?= Security::e(number_format((float)$record['salario_previsto'], 2, ',', '.')) ?></dd></div>
            <div><dt class="font-semibold text-gray-700">Centro de custo</dt><dd class="text-gray-600"><?= Security::e($record['centro_custo_codigo'] . ' - ' . $record['centro_custo_nome']) ?></dd></div>
            <div><dt class="font-semibold text-gray-700">Previsto no orçamento</dt><dd class="text-gray-600"><?= (int)$record['previsto_orcamento'] === 1 ? 'Sim' : 'Não' ?></dd></div>
            <?php if (!empty($record['justificativa_orcamento'])): ?><div><dt class="font-semibold text-gray-700">Justificativa</dt><dd class="text-gray-600"><?= nl2br(Security::e($record['justificativa_orcamento'])) ?></dd></div><?php endif; ?>
            <div><dt class="font-semibold text-gray-700">Benefícios aplicáveis</dt><dd class="text-gray-600"><?= !empty($record['beneficios']) ? Security::e(implode(', ', array_column($record['beneficios'], 'nome'))) : 'Nenhum benefício informado' ?></dd></div>
          </dl>
        </div>
      </section>

      <section class="grid gap-6 xl:grid-cols-2">
        <div class="rounded-xl border bg-white p-5 shadow-sm">
          <h3 class="text-lg font-semibold text-ctpblue">3. Jornada e escala</h3>
          <dl class="mt-4 space-y-3 text-sm">
            <div><dt class="font-semibold text-gray-700">Jornada</dt><dd class="text-gray-600"><?= Security::e($record['jornada_trabalho']) ?></dd></div>
            <div><dt class="font-semibold text-gray-700">Escala</dt><dd class="text-gray-600"><?= Security::e($record['escala'] ?: 'Não informada') ?></dd></div>
            <div><dt class="font-semibold text-gray-700">Turno</dt><dd class="text-gray-600"><?= Security::e($turnoLabels[$record['turno']] ?? 'Não informado') ?></dd></div>
          </dl>
        </div>
        <div class="rounded-xl border bg-white p-5 shadow-sm">
          <h3 class="text-lg font-semibold text-ctpblue">4. Perfil da vaga</h3>
          <dl class="mt-4 space-y-3 text-sm">
            <div><dt class="font-semibold text-gray-700">Escolaridade mínima</dt><dd class="text-gray-600"><?= Security::e($schoolOptions[$record['escolaridade_minima']] ?? $record['escolaridade_minima']) ?></dd></div>
            <div><dt class="font-semibold text-gray-700">Formação acadêmica</dt><dd class="text-gray-600"><?= Security::e($record['formacao_academica'] ?: 'Não informada') ?></dd></div>
            <div><dt class="font-semibold text-gray-700">Experiência necessária</dt><dd class="text-gray-600"><?= nl2br(Security::e($record['experiencia_necessaria'] ?: 'Não informada')) ?></dd></div>
            <div><dt class="font-semibold text-gray-700">Entregas esperadas</dt><dd class="text-gray-600"><?= nl2br(Security::e($record['entregas_esperadas'])) ?></dd></div>
            <div><dt class="font-semibold text-gray-700">Competências técnicas</dt><dd class="text-gray-600"><?= !empty($record['competencias_tecnicas']) ? Security::e(implode(', ', array_column($record['competencias_tecnicas'], 'nome'))) : 'Nenhuma informada' ?></dd></div>
            <div><dt class="font-semibold text-gray-700">Competências comportamentais</dt><dd class="text-gray-600"><?= !empty($record['competencias_comportamentais']) ? Security::e(implode(', ', array_column($record['competencias_comportamentais'], 'nome'))) : 'Nenhuma informada' ?></dd></div>
            <div><dt class="font-semibold text-gray-700">Nível de responsabilidade</dt><dd class="text-gray-600"><?= Security::e($nivelLabels[$record['nivel_responsabilidade']] ?? $record['nivel_responsabilidade']) ?></dd></div>
          </dl>
        </div>
      </section>

      <section class="rounded-xl border bg-white p-5 shadow-sm">
        <h3 class="text-lg font-semibold text-ctpblue">5. Prazos e prioridade</h3>
        <div class="mt-4 grid gap-4 xl:grid-cols-3">
          <div><span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Data prevista para início</span><div class="mt-1 text-sm text-gray-700"><?= Security::e($record['data_prevista_inicio_br']) ?></div></div>
          <div><span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Urgência</span><div class="mt-1 text-sm text-gray-700"><?= Security::e($urgenciaLabels[$record['urgencia']] ?? $record['urgencia']) ?></div></div>
          <div><span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Data limite desejada para fechamento</span><div class="mt-1 text-sm text-gray-700"><?= Security::e($record['data_limite_fechamento_br'] ?: 'Não informada') ?></div></div>
        </div>
      </section>

      <section class="rounded-xl border bg-white p-5 shadow-sm">
        <h3 class="text-lg font-semibold text-ctpblue">6. Aprovações</h3>
        <div class="mt-4 grid gap-4 xl:grid-cols-2">
          <div class="rounded-lg border p-4">
            <div class="text-sm font-semibold text-gray-700">Líder imediato</div>
            <div class="mt-2 text-sm text-gray-600">Destinatário: <?= Security::e($leaderApproval['destinatario_nome'] ?? $record['lider_nome'] ?? 'Não configurado') ?></div>
            <div class="mt-1 text-sm text-gray-600">Status: <?= Security::e(ucfirst((string)($leaderApproval['status'] ?? 'pendente'))) ?></div>
            <div class="mt-1 text-sm text-gray-600">Data: <?= !empty($leaderApproval['aprovado_em']) ? Security::e(date('d/m/Y H:i', strtotime((string)$leaderApproval['aprovado_em']))) : 'Pendente' ?></div>
            <?php if (!empty($leaderApproval['aprovador_nome'])): ?><div class="mt-1 text-sm text-gray-600">Aprovador: <?= Security::e($leaderApproval['aprovador_nome']) ?></div><?php endif; ?>
            <?php if ($canApproveLeader): ?>
              <form action="<?= $base ?>/admin/solicitacoes-vaga/<?= (int)$record['id'] ?>/aprovar-lider" method="post" class="mt-4 space-y-3">
                <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
                <textarea name="comment" rows="3" class="w-full rounded border px-3 py-2 text-sm" placeholder="Observações da aprovação (opcional)"></textarea>
                <div class="flex flex-wrap gap-2">
                  <button type="submit" name="decision" value="aprovado" class="rounded-lg bg-ctgreen px-4 py-2 text-sm font-medium text-white hover:bg-ctdark">Aprovar</button>
                  <button type="submit" name="decision" value="reprovado" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">Reprovar</button>
                </div>
              </form>
            <?php endif; ?>
          </div>

          <div class="rounded-lg border p-4">
            <div class="text-sm font-semibold text-gray-700">Recursos Humanos</div>
            <div class="mt-2 text-sm text-gray-600">Status: <?= Security::e(ucfirst((string)($rhApproval['status'] ?? 'pendente'))) ?></div>
            <div class="mt-1 text-sm text-gray-600">Data: <?= !empty($rhApproval['aprovado_em']) ? Security::e(date('d/m/Y H:i', strtotime((string)$rhApproval['aprovado_em']))) : 'Pendente' ?></div>
            <?php if (!empty($rhApproval['aprovador_nome'])): ?><div class="mt-1 text-sm text-gray-600">Aprovador: <?= Security::e($rhApproval['aprovador_nome']) ?></div><?php endif; ?>
            <?php if ($canApproveRh): ?>
              <form action="<?= $base ?>/admin/solicitacoes-vaga/<?= (int)$record['id'] ?>/aprovar-rh" method="post" class="mt-4 space-y-3">
                <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
                <textarea name="comment" rows="3" class="w-full rounded border px-3 py-2 text-sm" placeholder="Observações da aprovação (opcional)"></textarea>
                <div class="flex flex-wrap gap-2">
                  <button type="submit" name="decision" value="aprovado" class="rounded-lg bg-ctgreen px-4 py-2 text-sm font-medium text-white hover:bg-ctdark">Aprovar</button>
                  <button type="submit" name="decision" value="reprovado" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">Reprovar</button>
                </div>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </section>

      <section class="rounded-xl border bg-white p-5 shadow-sm">
        <h3 class="text-lg font-semibold text-ctpblue">7. Controle interno RH</h3>
        <form action="<?= $base ?>/admin/solicitacoes-vaga/<?= (int)$record['id'] ?>/controle-rh" method="post" class="mt-4 space-y-4">
          <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
          <div class="grid gap-4 xl:grid-cols-2">
            <div>
              <label class="block text-sm font-medium text-gray-700">Nome do contratado</label>
              <select name="nome_contratado_colaborador_id" class="mt-1 w-full rounded border px-3 py-2" <?= $canEditRhSection ? '' : 'disabled' ?>>
                <option value="">Selecione</option>
                <?php foreach ($colaboradores as $colaborador): ?>
                  <option value="<?= (int)$colaborador['id'] ?>" <?= (int)($record['nome_contratado_colaborador_id'] ?? 0) === (int)$colaborador['id'] ? 'selected' : '' ?>><?= Security::e($colaborador['nome']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Data de admissão</label>
              <input type="text" name="data_admissao" value="<?= Security::e($record['data_admissao_br'] ?? '') ?>" class="mt-1 w-full rounded border px-3 py-2" placeholder="DD/MM/AAAA" data-mask-date="1" <?= $canEditRhSection ? '' : 'disabled' ?>>
            </div>
          </div>
          <div class="grid gap-4 xl:grid-cols-3">
            <div>
              <label class="block text-sm font-medium text-gray-700">Tempo para fechamento da vaga</label>
              <input type="text" value="<?= Security::e((string)($record['tempo_fechamento_dias'] ?? '')) ?><?= !empty($record['tempo_fechamento_dias']) ? ' dias' : '' ?>" class="mt-1 w-full rounded border bg-gray-50 px-3 py-2" readonly>
            </div>
            <div class="xl:col-span-2">
              <label class="block text-sm font-medium text-gray-700">Avaliação após 90 dias</label>
              <div class="form-choice-group is-inline is-compact mt-2">
                <?php foreach ($avaliacaoLabels as $value => $label): ?>
                  <label class="form-choice-card text-sm <?= $canEditRhSection ? '' : 'is-disabled' ?>">
                    <input type="radio" name="avaliacao_90_dias" value="<?= $value ?>" <?= ($record['avaliacao_90_dias'] ?? '') === $value ? 'checked' : '' ?> <?= $canEditRhSection ? '' : 'disabled' ?>>
                    <span><?= Security::e($label) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Observações</label>
            <textarea name="observacoes_rh" rows="4" class="mt-1 w-full rounded border px-3 py-2" <?= $canEditRhSection ? '' : 'disabled' ?>><?= Security::e($record['observacoes_rh'] ?? '') ?></textarea>
          </div>
          <?php if ($canEditRhSection): ?>
            <div class="responsive-form-actions pt-2">
              <button type="submit" class="rounded-lg bg-ctgreen px-4 py-3 text-sm font-medium text-white hover:bg-ctdark">Salvar controle RH</button>
            </div>
          <?php else: ?>
            <p class="text-sm text-gray-500">Somente usuários com perfil RH podem editar esta seção após a aprovação formal da solicitação.</p>
          <?php endif; ?>
        </form>
      </section>

      <section class="rounded-xl border bg-white p-5 shadow-sm">
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
    </div>
  <?php endif; ?>
</div>
