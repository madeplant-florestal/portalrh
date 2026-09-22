<?php
require_once APP_PATH . '/views/partials/modulo-topo.php';
$isShow = ($mode ?? 'create') === 'show';
$currentRole = strtolower((string)($currentRole ?? 'viewer'));
$isSupervisor = !empty($_SESSION['user_is_supervisor']);
$currentAccess = $dependencies['current_access'] ?? null;
$gestores = $dependencies['gestores'] ?? [];
$colaboradores = $dependencies['colaboradores'] ?? [];
$competencias = $dependencies['competencias'] ?? ['tecnica' => [], 'comportamental' => []];
$beneficiosByCargo = $dependencies['beneficios_by_cargo'] ?? [];
$schoolOptions = $dependencies['escolaridades'] ?? [];

// Matriz oficial Cargo x Setor (`cargo_setores_metadados`, espelho técnico do METADADOS):
// "Setor selecionado -> lista SOMENTE os Cargos oficialmente vinculados àquele Setor." Setor vem
// do CONTEXTO DO SOLICITANTE (usuario_setores), nunca de uma lista global. A tabela legada
// `cargo_setores` NÃO participa deste fluxo.
$podeEscolherSolicitante = !empty($dependencies['pode_escolher_solicitante']);
$elegiveisSolicitantes = $dependencies['elegiveis_solicitantes'] ?? [];
$solicitanteContexto = $dependencies['solicitante_contexto'] ?? [
    'usuario_id' => 0, 'nome' => null, 'cargo_rotulo' => null,
    'setores' => [], 'cargos_por_setor' => [], 'centros_custo_by_setor' => [], 'bloqueado_sem_setor' => true,
];
$setoresSolicitante = $solicitanteContexto['setores'] ?? [];

// Fallback administrativo (correção 2026-09-14, §3): Setor sem NENHUMA relação na matriz oficial
// (METADADOS incompleto — ex.: MANUTENÇÃO) não bloqueia Admin/RH/supervisor master ATOR — eles
// podem escolher qualquer Cargo oficial do catálogo para aquela solicitação específica (nunca cria
// vínculo em cargo_setores_metadados). Usuário comum continua bloqueado. Depende do ATOR
// autenticado (`pode_escolher_solicitante`), nunca do solicitante representado.
$podeFallbackCargoAdministrativo = !empty($dependencies['pode_fallback_cargo_administrativo']);
$cargosFallbackAdministrativo = $dependencies['cargos_fallback_administrativo'] ?? [];

// $tipoVagaLabels/$tipoContratacaoLabels: mapa COMPLETO (todos os valores do ENUM) — usado no modo "show" (linhas abaixo) para
// rotular corretamente qualquer registro já gravado, inclusive com valores retirados de novas seleções. NÃO usar este mapa
// para montar as opções do formulário (ver $tipoVagaOpcoes/$tipoContratacaoOpcoes logo abaixo).
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
// Opções oferecidas no formulário (criação/edição): "Nova posição" e "Terceiro" saem de circulação para NOVAS seleções, sem
// alterar ENUM/banco/histórico. Se o registro em edição já tiver um desses valores, a opção volta a aparecer SÓ para ele
// (selecionada), preservando o contexto histórico sem deixar o campo obrigatório sem opção marcável.
$tipoVagaOpcoes = array_diff_key($tipoVagaLabels, ['nova_posicao' => null]);
if (($form['tipo_vaga'] ?? '') === 'nova_posicao') {
    $tipoVagaOpcoes = ['nova_posicao' => $tipoVagaLabels['nova_posicao']] + $tipoVagaOpcoes;
}
$tipoContratacaoOpcoes = array_diff_key($tipoContratacaoLabels, ['terceiro' => null]);
if (($form['tipo_contratacao'] ?? '') === 'terceiro') {
    $tipoContratacaoOpcoes['terceiro'] = $tipoContratacaoLabels['terceiro'];
}
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
// Sprint 2026-09-09: a autorização e a hierarquia migraram para `usuarios`. Não há mais trava de
// setor pelo gestor. O campo "Gestor solicitante" é opcional e só aparece para RH/Admin (contexto
// legado); usuário comum/PJ nem o vê e a solicitação nasce com gestor NULL.
$mostrarGestor = !$isShow && $canEditRh;
$defaultSetorId = (int)($form['setor_id'] ?? 0);
$defaultGestorId = (int)($form['gestor_solicitante_colaborador_id'] ?? 0);
$aviso = $aviso ?? '';

// Setor "atual" para a renderização inicial do servidor (JS reajusta ao trocar Setor/solicitante):
// 1 Setor -> automático; vários -> só se já veio selecionado (reenvio com erro). Cargo e Centro de
// Custo iniciais dependem deste mesmo Setor.
$setorInicialId = $defaultSetorId ?: (count($setoresSolicitante) === 1 ? (int)$setoresSolicitante[0]['id'] : 0);
$cargosDoSetorInicial = $solicitanteContexto['cargos_por_setor'][$setorInicialId] ?? [];
$usaFallbackCargoInicial = $setorInicialId > 0 && $cargosDoSetorInicial === [] && $podeFallbackCargoAdministrativo;
$cargosInicial = $usaFallbackCargoInicial ? $cargosFallbackAdministrativo : $cargosDoSetorInicial;

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
    'gestores' => $gestores,
    'beneficios_by_cargo' => $beneficiosByCargo,
    'solicitante_contexto' => $solicitanteContexto,
    'pode_fallback_cargo_administrativo' => $podeFallbackCargoAdministrativo,
    'cargos_fallback_administrativo' => $cargosFallbackAdministrativo,
];
?>
<div class="space-y-4" data-solicitacao-vaga-form="1">
  <?php
    $svStatus = (string)($record['status_fluxo'] ?? '');
    $svTom = match ($svStatus) {
        'pendente_lider', 'pendente_rh' => 'warning',
        'aprovada', 'concluida' => 'success',
        default => 'danger',
    };
  ?>
  <?= ui_modulo_topo($base, 'recrutamento', 'solicitacoes', [
      'titulo' => $isShow ? 'Solicitação de vaga #' . (int)($record['id'] ?? 0) : 'Nova solicitação de vaga',
      'descricao' => $isShow ? 'Acompanhe aprovações, rastreabilidade e controle interno do RH.' : 'Preencha o formulário completo com base nos cadastros oficiais da empresa.',
      'badge' => $isShow && $svStatus !== '' ? ['texto' => (string)($statusLabels[$svStatus] ?? $svStatus), 'tom' => $svTom] : null,
  ], [['label' => $isShow ? 'Solicitação #' . (int)($record['id'] ?? 0) : 'Nova solicitação', 'href' => null]]) ?>

  <div class="responsive-panel">
  <?php if (!empty($error)): ?>
    <div class="mt-4 rounded-lg border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger"><?= Security::e($error) ?></div>
  <?php endif; ?>
  <?php if (!empty($success)): ?>
    <div class="mt-4 rounded-lg border border-success/30 bg-success/10 px-4 py-3 text-sm text-success"><?= Security::e($success) ?></div>
  <?php endif; ?>
  <?php if (!empty($aviso)): ?>
    <div class="mt-4 rounded-lg border border-warning/30 bg-warning/10 px-4 py-3 text-sm text-warning"><?= Security::e($aviso) ?></div>
  <?php endif; ?>

  <?php if (!$isShow): ?>
    <form class="mt-6 space-y-8" action="<?= $base ?>/admin/solicitacoes-vaga/nova" method="post" novalidate data-solicitacao-form-element="1">
      <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
      <script type="application/json" data-solicitacao-payload="1"><?= json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>


      <section class="space-y-4">
        <div class="border-b pb-2">
          <h3 class="text-lg font-semibold text-text-primary">1. Identificação da vaga</h3>
        </div>
        <div class="grid gap-4 lg:grid-cols-2">
          <div>
            <label class="block text-sm font-medium text-text-primary">Solicitante <?= $podeEscolherSolicitante ? '*' : '' ?></label>
            <?php if ($podeEscolherSolicitante): ?>
              <select name="solicitante_usuario_id" class="mt-1 w-full rounded border px-3 py-2" data-solicitacao-solicitante="1" required>
                <?php foreach ($elegiveisSolicitantes as $elegivel): ?>
                  <option value="<?= (int)$elegivel['id'] ?>" <?= (int)($form['solicitante_usuario_id'] ?? $currentUserId) === (int)$elegivel['id'] ? 'selected' : '' ?>>
                    <?= Security::e($elegivel['nome']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <p class="mt-1 text-xs text-text-secondary" data-solicitacao-cargo-solicitante="1">
                <?= $solicitanteContexto['cargo_rotulo'] ? Security::e('Cargo do solicitante: ' . $solicitanteContexto['cargo_rotulo']) : '' ?>
              </p>
            <?php else: ?>
              <div class="mt-1 rounded border bg-surface-secondary px-3 py-2 text-sm text-text-primary"><?= Security::e((string)($solicitanteContexto['nome'] ?? '')) ?></div>
              <p class="mt-1 text-xs text-text-secondary">A identidade do solicitante é sempre o seu usuário autenticado.</p>
            <?php endif; ?>
          </div>
          <div>
            <label class="block text-sm font-medium text-text-primary">Área / Departamento (Setor) *</label>
            <div class="<?= $setoresSolicitante === [] ? '' : 'hidden' ?> mt-1 rounded-lg border border-danger/30 bg-danger/10 px-3 py-2 text-sm text-danger" data-solicitacao-setor-bloqueio="1">
              O usuário solicitante ainda não possui Setor de atuação configurado.
              <?php if ($podeEscolherSolicitante): ?>
                <a href="<?= $base ?>/admin/usuarios/<?= (int)($solicitanteContexto['usuario_id'] ?? 0) ?>" class="font-medium underline" target="_blank" rel="noopener">Atualizar contexto organizacional do usuário</a>.
              <?php endif; ?>
            </div>
            <select name="setor_id" class="mt-1 w-full rounded border px-3 py-2" data-solicitacao-setor="1" required>
              <?php if ($setoresSolicitante === []): ?>
                <option value="">Nenhum Setor configurado</option>
              <?php elseif (count($setoresSolicitante) === 1): ?>
                <option value="<?= (int)$setoresSolicitante[0]['id'] ?>" selected><?= Security::e($setoresSolicitante[0]['nome']) ?></option>
              <?php else: ?>
                <option value="">Selecione</option>
                <?php foreach ($setoresSolicitante as $s): ?>
                  <option value="<?= (int)$s['id'] ?>" <?= (int)$defaultSetorId === (int)$s['id'] ? 'selected' : '' ?>>
                    <?= Security::e($s['nome']) ?><?= !empty($s['principal']) ? ' — principal' : '' ?>
                  </option>
                <?php endforeach; ?>
              <?php endif; ?>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-text-primary">Quantidade de vagas *</label>
            <input type="number" min="1" name="quantidade_vagas" value="<?= Security::e((string)($form['quantidade_vagas'] ?? 1)) ?>" required class="mt-1 w-full rounded border px-3 py-2">
          </div>
          <div>
            <label class="block text-sm font-medium text-text-primary">Cargo *</label>
            <div class="<?= $cargosInicial === [] ? '' : 'hidden' ?> mt-1 rounded-lg border border-danger/30 bg-danger/10 px-3 py-2 text-sm text-danger" data-solicitacao-cargo-bloqueio="1">
              Nenhum Cargo oficial está associado a este Setor no METADADOS.
            </div>
            <div class="<?= $usaFallbackCargoInicial ? '' : 'hidden' ?> mt-1 rounded-lg border border-warning/30 bg-warning/10 px-3 py-2 text-sm text-warning" data-solicitacao-cargo-fallback-aviso="1">
              O METADADOS ainda não possui vínculos Cargo × Setor cadastrados para este Setor. Como usuário RH/Administrativo, você pode selecionar um Cargo oficial para a solicitação.
            </div>
            <select name="cargo_id" class="<?= $cargosInicial === [] ? 'hidden' : '' ?> mt-1 w-full rounded border px-3 py-2" data-solicitacao-cargo="1" aria-describedby="solicitacao-cargo-faixa" <?= $cargosInicial === [] ? 'disabled' : 'required' ?>>
              <option value="">Selecione</option>
              <?php foreach ($cargosInicial as $cargo): ?>
                <option value="<?= (int)$cargo['id'] ?>" <?= (int)($form['cargo_id'] ?? 0) === (int)$cargo['id'] ? 'selected' : '' ?>><?= Security::e($cargo['nome']) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="mt-1 text-xs text-text-secondary">Somente Cargos oficialmente vinculados ao Setor selecionado (matriz do METADADOS). O Cargo do solicitante é só contexto — não define o Cargo da vaga.</p>
            <p id="solicitacao-cargo-faixa" class="mt-1 text-xs text-text-secondary" data-solicitacao-faixa-label="1" aria-live="polite"></p>
          </div>
          <?php if ($mostrarGestor): ?>
          <div>
            <label class="block text-sm font-medium text-text-primary">Gestor solicitante <span class="text-text-muted">(opcional — contexto legado)</span></label>
            <select name="gestor_solicitante_colaborador_id" class="mt-1 w-full rounded border px-3 py-2" data-solicitacao-gestor="1">
              <option value="">Não informar</option>
              <?php foreach ($gestores as $gestor): ?>
                <option value="<?= (int)$gestor['colaborador_id'] ?>" <?= (int)$defaultGestorId === (int)$gestor['colaborador_id'] ? 'selected' : '' ?>>
                  <?= Security::e($gestor['nome'] . ' - ' . $gestor['cargo_nome']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <p class="mt-1 text-xs text-text-secondary">A identidade do solicitante é o Usuário selecionado acima. Este campo é apenas contexto opcional (histórico).</p>
          </div>
          <?php endif; ?>
        </div>

        <div class="hidden" data-solicitacao-maquina-wrap="1">
          <label class="block text-sm font-medium text-text-primary">Se operador de Máquinas Florestais descreva qual máquina irá operar</label>
          <input type="text" name="maquina_operada" value="<?= Security::e($form['maquina_operada'] ?? '') ?>" class="mt-1 w-full rounded border px-3 py-2" data-solicitacao-maquina-input="1">
        </div>

        <div>
          <label class="block text-sm font-medium text-text-primary">Tipo de vaga *</label>
          <div class="form-choice-group is-inline mt-2">
            <?php foreach ($tipoVagaOpcoes as $value => $label): ?>
              <label class="form-choice-card text-sm">
                <input type="radio" name="tipo_vaga" value="<?= $value ?>" <?= ($form['tipo_vaga'] ?? '') === $value ? 'checked' : '' ?> required data-solicitacao-tipo-vaga="1">
                <span><?= Security::e($label) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="hidden space-y-4 rounded-xl border border-warning/30 bg-warning/10 p-4" data-solicitacao-substituicao-wrap="1">
          <h4 class="font-medium text-warning">Campos obrigatórios para substituição</h4>
          <div class="grid gap-4 lg:grid-cols-2">
            <div>
              <label class="block text-sm font-medium text-text-primary">Nome do colaborador substituído</label>
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
              <label class="block text-sm font-medium text-text-primary">Data do desligamento</label>
              <input type="text" name="data_desligamento" value="<?= Security::e($form['data_desligamento'] ?? '') ?>" class="mt-1 w-full rounded border px-3 py-2" placeholder="DD/MM/AAAA" data-mask-date="1" data-solicitacao-data-desligamento="1">
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-text-primary">Motivo da saída</label>
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
            <label class="block text-sm font-medium text-text-primary">Complemento do motivo</label>
            <input type="text" name="motivo_saida_outros" value="<?= Security::e($form['motivo_saida_outros'] ?? '') ?>" class="mt-1 w-full rounded border px-3 py-2" data-solicitacao-motivo-outros="1">
          </div>
        </div>
      </section>

      <section class="space-y-4">
        <div class="border-b pb-2">
          <h3 class="text-lg font-semibold text-text-primary">2. Informações contratuais</h3>
        </div>
        <div>
          <label class="block text-sm font-medium text-text-primary">Tipo de contratação *</label>
          <div class="form-choice-group is-inline mt-2">
            <?php foreach ($tipoContratacaoOpcoes as $value => $label): ?>
              <label class="form-choice-card text-sm">
                <input type="radio" name="tipo_contratacao" value="<?= $value ?>" <?= ($form['tipo_contratacao'] ?? '') === $value ? 'checked' : '' ?> required>
                <span><?= Security::e($label) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="form-inline-grid form-inline-grid-3">
          <div>
            <label class="block text-sm font-medium text-text-primary">Salário previsto *</label>
            <input type="text" name="salario_previsto" value="<?= Security::e($form['salario_previsto'] ?? '') ?>" class="mt-1 w-full rounded border px-3 py-2" placeholder="R$ 0,00" required data-mask-money="1" data-solicitacao-salario="1">
          </div>
          <?php $centrosInicial = $solicitanteContexto['centros_custo_by_setor'][$setorInicialId] ?? []; ?>
          <div>
            <label class="block text-sm font-medium text-text-primary" data-solicitacao-centro-label="1">Centro de custo<?= $centrosInicial !== [] ? ' *' : '' ?></label>
            <select name="centro_custo_id" class="mt-1 w-full rounded border px-3 py-2 <?= $centrosInicial === [] ? 'hidden' : '' ?>" data-solicitacao-centro-custo="1" <?= $centrosInicial !== [] ? 'required' : '' ?>>
              <option value="">Selecione</option>
              <?php foreach ($centrosInicial as $centro): ?>
                <option value="<?= (int)$centro['id'] ?>" <?= (int)($form['centro_custo_id'] ?? 0) === (int)$centro['id'] ? 'selected' : '' ?>>
                  <?= Security::e($centro['codigo'] . ' - ' . $centro['nome']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <p class="mt-1 text-xs text-text-secondary" data-solicitacao-centro-vazio="1" <?= $centrosInicial !== [] ? 'hidden' : '' ?>>Nenhum Centro de Custo cadastrado para este Setor.</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-text-primary">Previsto no orçamento anual? *</label>
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
          <label class="block text-sm font-medium text-text-primary">Benefícios aplicáveis</label>
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
          <label class="block text-sm font-medium text-text-primary">Justificativa caso não esteja previsto</label>
          <textarea name="justificativa_orcamento" rows="4" class="mt-1 w-full rounded border px-3 py-2" data-solicitacao-justificativa="1"><?= Security::e($form['justificativa_orcamento'] ?? '') ?></textarea>
        </div>
      </section>

      <section class="space-y-4">
        <div class="border-b pb-2">
          <h3 class="text-lg font-semibold text-text-primary">3. Jornada e escala</h3>
        </div>
        <div class="grid gap-4 lg:grid-cols-2">
          <div>
            <label class="block text-sm font-medium text-text-primary">Jornada de trabalho *</label>
            <input type="text" name="jornada_trabalho" value="<?= Security::e($form['jornada_trabalho'] ?? '') ?>" class="mt-1 w-full rounded border px-3 py-2" placeholder="Ex.: 44h semanais" required>
          </div>
          <div>
            <label class="block text-sm font-medium text-text-primary">Escala (se aplicável)</label>
            <input type="text" name="escala" value="<?= Security::e($form['escala'] ?? '') ?>" class="mt-1 w-full rounded border px-3 py-2" placeholder="Ex.: 5x1, 12x36">
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-text-primary">Turno</label>
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
          <h3 class="text-lg font-semibold text-text-primary">4. Perfil da vaga</h3>
        </div>
        <div class="grid gap-4 lg:grid-cols-2">
          <div>
            <label class="block text-sm font-medium text-text-primary">Escolaridade mínima *</label>
            <select name="escolaridade_minima" class="mt-1 w-full rounded border px-3 py-2" required>
              <option value="">Selecione</option>
              <?php foreach ($schoolOptions as $value => $label): ?>
                <option value="<?= $value ?>" <?= ($form['escolaridade_minima'] ?? '') === $value ? 'selected' : '' ?>><?= Security::e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-text-primary">Formação acadêmica</label>
            <input type="text" name="formacao_academica" value="<?= Security::e($form['formacao_academica'] ?? '') ?>" class="mt-1 w-full rounded border px-3 py-2">
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-text-primary">Experiência necessária</label>
          <textarea name="experiencia_necessaria" rows="4" class="mt-1 w-full rounded border px-3 py-2"><?= Security::e($form['experiencia_necessaria'] ?? '') ?></textarea>
        </div>
        <div>
          <label class="block text-sm font-medium text-text-primary">Entregas esperadas da Função *</label>
          <textarea name="entregas_esperadas" rows="6" minlength="100" class="mt-1 w-full rounded border px-3 py-2" required><?= Security::e($form['entregas_esperadas'] ?? '') ?></textarea>
          <p class="mt-1 text-xs text-text-secondary">Descreva os principais resultados esperados para a função com no mínimo 100 caracteres.</p>
        </div>
        <div class="grid gap-4 xl:grid-cols-2">
          <div>
            <label class="block text-sm font-medium text-text-primary">Competências técnicas</label>
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
            <label class="block text-sm font-medium text-text-primary">Competências comportamentais</label>
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
          <label class="block text-sm font-medium text-text-primary">Nível de responsabilidade *</label>
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
          <h3 class="text-lg font-semibold text-text-primary">5. Prazos e prioridade</h3>
        </div>
        <div class="grid gap-4 lg:grid-cols-2">
          <div>
            <label class="block text-sm font-medium text-text-primary">Data prevista para início *</label>
            <input type="text" name="data_prevista_inicio" value="<?= Security::e($form['data_prevista_inicio'] ?? '') ?>" class="mt-1 w-full rounded border px-3 py-2" placeholder="DD/MM/AAAA" required data-mask-date="1">
          </div>
          <div>
            <label class="block text-sm font-medium text-text-primary">Data limite desejada para fechamento</label>
            <input type="text" name="data_limite_fechamento" value="<?= Security::e($form['data_limite_fechamento'] ?? '') ?>" class="mt-1 w-full rounded border px-3 py-2" placeholder="DD/MM/AAAA" data-mask-date="1">
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-text-primary">Urgência *</label>
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

      <section class="rounded-xl border border-border bg-surface-secondary p-4">
        <h3 class="text-lg font-semibold text-text-primary">6. Aprovações</h3>
        <p class="mt-2 text-sm text-text-secondary">Os registros de Líder Imediato e RH são preenchidos automaticamente conforme o fluxo de aprovação do sistema.</p>
      </section>

      <section class="rounded-xl border border-border bg-surface-secondary p-4">
        <h3 class="text-lg font-semibold text-text-primary">7. Controle interno RH</h3>
        <p class="mt-2 text-sm text-text-secondary">Esta seção ficará disponível apenas para usuários com perfil de RH após as aprovações formais da solicitação.</p>
      </section>

      <div class="responsive-form-actions border-t pt-6">
        <button type="submit" class="rounded-lg bg-primary-700 px-5 py-3 text-sm font-medium text-white hover:bg-primary-800">Enviar solicitação</button>
        <a href="<?= $base ?>/admin/solicitacoes-vaga" class="text-sm font-medium text-text-primary hover:text-primary-700">Cancelar</a>
      </div>
    </form>
  <?php else: ?>
    <div class="mt-6 space-y-8">
      <section class="grid gap-4 xl:grid-cols-3">
        <div class="rounded-xl border bg-white p-4 shadow-sm">
          <div class="text-xs font-semibold uppercase tracking-wide text-text-secondary">Status do fluxo</div>
          <div class="mt-2 text-lg font-semibold text-text-primary"><?= Security::e($statusLabels[$record['status_fluxo']] ?? $record['status_fluxo']) ?></div>
          <div class="mt-2 text-sm text-text-secondary">Enviado em <?= Security::e(date('d/m/Y H:i', strtotime((string)$record['created_at']))) ?></div>
        </div>
        <div class="rounded-xl border bg-white p-4 shadow-sm">
          <div class="text-xs font-semibold uppercase tracking-wide text-text-secondary">Área / Cargo</div>
          <div class="mt-2 text-lg font-semibold text-text-primary"><?= Security::e($record['setor_nome']) ?></div>
          <div class="mt-2 text-sm text-text-secondary"><?= Security::e($record['cargo_nome']) ?></div>
        </div>
        <div class="rounded-xl border bg-white p-4 shadow-sm">
          <div class="text-xs font-semibold uppercase tracking-wide text-text-secondary">Solicitante</div>
          <div class="mt-2 text-lg font-semibold text-text-primary"><?= Security::e($record['solicitante_nome'] ?? $record['gestor_nome']) ?></div>
          <div class="mt-2 text-sm text-text-secondary">Quantidade de vagas: <?= (int)$record['quantidade_vagas'] ?></div>
        </div>
      </section>

      <?php
      $vagaVinculada = $vagaVinculada ?? null;
      $solicitacaoAprovada = in_array((string)($record['status_fluxo'] ?? ''), ['aprovada', 'concluida'], true);
      $podeGerarVaga = $isShow && $canEditRh && $solicitacaoAprovada && $vagaVinculada === null;
      ?>
      <section class="rounded-xl border bg-white p-5 shadow-sm">
        <h3 class="text-lg font-semibold text-text-primary">Vaga pública</h3>
        <?php if ($vagaVinculada !== null): ?>
          <?php $vagaPublicada = (int)($vagaVinculada['ativo'] ?? 0) === 1; ?>
          <p class="mt-2 text-sm text-text-secondary">
            Esta solicitação originou a vaga
            <a href="<?= $base ?>/admin/vagas" class="font-semibold text-primary-700 hover:text-primary-800">#<?= (int)$vagaVinculada['id'] ?></a>,
            atualmente <strong><?= $vagaPublicada ? 'publicada no site' : 'em rascunho (aguardando publicação pelo RH)' ?></strong>.
          </p>
        <?php elseif ($solicitacaoAprovada): ?>
          <p class="mt-2 text-sm text-text-secondary">A vaga ainda não foi gerada.</p>
          <?php if ($podeGerarVaga): ?>
            <form action="<?= $base ?>/admin/solicitacoes-vaga/<?= (int)$record['id'] ?>/gerar-vaga" method="post" class="mt-3">
              <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
              <button class="rounded-lg bg-primary-700 px-4 py-2 text-sm font-medium text-white hover:bg-primary-800">Gerar rascunho da vaga</button>
            </form>
          <?php endif; ?>
        <?php else: ?>
          <p class="mt-2 text-sm text-text-secondary">A vaga em rascunho é gerada automaticamente quando a solicitação for aprovada pelo RH.</p>
        <?php endif; ?>
      </section>

      <section class="grid gap-6 xl:grid-cols-2">
        <div class="rounded-xl border bg-white p-5 shadow-sm">
          <h3 class="text-lg font-semibold text-text-primary">1. Identificação da vaga</h3>
          <dl class="mt-4 space-y-3 text-sm">
            <div><dt class="font-semibold text-text-primary">Área / Departamento</dt><dd class="text-text-secondary"><?= Security::e($record['setor_nome']) ?></dd></div>
            <div><dt class="font-semibold text-text-primary">Cargo</dt><dd class="text-text-secondary"><?= Security::e($record['cargo_nome']) ?></dd></div>
            <div><dt class="font-semibold text-text-primary">Solicitante</dt><dd class="text-text-secondary"><?= Security::e(trim(($record['solicitante_nome'] ?? '') . ' — ' . ($record['solicitante_email'] ?? ''), ' —')) ?></dd></div>
            <?php if (!empty($record['gestor_nome']) && $record['gestor_nome'] !== ($record['solicitante_nome'] ?? null)): ?>
              <div><dt class="font-semibold text-text-primary">Gestor solicitante <span class="font-normal text-text-muted">(contexto legado)</span></dt><dd class="text-text-secondary"><?= Security::e($record['gestor_nome']) ?></dd></div>
            <?php endif; ?>
            <div><dt class="font-semibold text-text-primary">Tipo de vaga</dt><dd class="text-text-secondary"><?= Security::e($tipoVagaLabels[$record['tipo_vaga']] ?? $record['tipo_vaga']) ?></dd></div>
            <?php if (!empty($record['maquina_operada'])): ?><div><dt class="font-semibold text-text-primary">Máquina a operar</dt><dd class="text-text-secondary"><?= Security::e($record['maquina_operada']) ?></dd></div><?php endif; ?>
            <?php if (!empty($record['substituido_nome'])): ?><div><dt class="font-semibold text-text-primary">Colaborador substituído</dt><dd class="text-text-secondary"><?= Security::e($record['substituido_nome']) ?></dd></div><?php endif; ?>
            <?php if (!empty($record['data_desligamento_br'])): ?><div><dt class="font-semibold text-text-primary">Data do desligamento</dt><dd class="text-text-secondary"><?= Security::e($record['data_desligamento_br']) ?></dd></div><?php endif; ?>
          </dl>
        </div>

        <div class="rounded-xl border bg-white p-5 shadow-sm">
          <h3 class="text-lg font-semibold text-text-primary">2. Informações contratuais</h3>
          <dl class="mt-4 space-y-3 text-sm">
            <div><dt class="font-semibold text-text-primary">Tipo de contratação</dt><dd class="text-text-secondary"><?= Security::e($tipoContratacaoLabels[$record['tipo_contratacao']] ?? $record['tipo_contratacao']) ?></dd></div>
            <div><dt class="font-semibold text-text-primary">Salário previsto</dt><dd class="text-text-secondary">R$ <?= Security::e(number_format((float)$record['salario_previsto'], 2, ',', '.')) ?></dd></div>
            <div><dt class="font-semibold text-text-primary">Centro de custo</dt><dd class="text-text-secondary"><?= $record['centro_custo_nome'] ? Security::e($record['centro_custo_codigo'] . ' - ' . $record['centro_custo_nome']) : 'Não informado (nenhum Centro de Custo cadastrado para o Setor no momento da criação)' ?></dd></div>
            <div><dt class="font-semibold text-text-primary">Previsto no orçamento</dt><dd class="text-text-secondary"><?= (int)$record['previsto_orcamento'] === 1 ? 'Sim' : 'Não' ?></dd></div>
            <?php if (!empty($record['justificativa_orcamento'])): ?><div><dt class="font-semibold text-text-primary">Justificativa</dt><dd class="text-text-secondary"><?= nl2br(Security::e($record['justificativa_orcamento'])) ?></dd></div><?php endif; ?>
            <div><dt class="font-semibold text-text-primary">Benefícios aplicáveis</dt><dd class="text-text-secondary"><?= !empty($record['beneficios']) ? Security::e(implode(', ', array_column($record['beneficios'], 'nome'))) : 'Nenhum benefício informado' ?></dd></div>
          </dl>
        </div>
      </section>

      <section class="grid gap-6 xl:grid-cols-2">
        <div class="rounded-xl border bg-white p-5 shadow-sm">
          <h3 class="text-lg font-semibold text-text-primary">3. Jornada e escala</h3>
          <dl class="mt-4 space-y-3 text-sm">
            <div><dt class="font-semibold text-text-primary">Jornada</dt><dd class="text-text-secondary"><?= Security::e($record['jornada_trabalho']) ?></dd></div>
            <div><dt class="font-semibold text-text-primary">Escala</dt><dd class="text-text-secondary"><?= Security::e($record['escala'] ?: 'Não informada') ?></dd></div>
            <div><dt class="font-semibold text-text-primary">Turno</dt><dd class="text-text-secondary"><?= Security::e($turnoLabels[$record['turno']] ?? 'Não informado') ?></dd></div>
          </dl>
        </div>
        <div class="rounded-xl border bg-white p-5 shadow-sm">
          <h3 class="text-lg font-semibold text-text-primary">4. Perfil da vaga</h3>
          <dl class="mt-4 space-y-3 text-sm">
            <div><dt class="font-semibold text-text-primary">Escolaridade mínima</dt><dd class="text-text-secondary"><?= Security::e($schoolOptions[$record['escolaridade_minima']] ?? $record['escolaridade_minima']) ?></dd></div>
            <div><dt class="font-semibold text-text-primary">Formação acadêmica</dt><dd class="text-text-secondary"><?= Security::e($record['formacao_academica'] ?: 'Não informada') ?></dd></div>
            <div><dt class="font-semibold text-text-primary">Experiência necessária</dt><dd class="text-text-secondary"><?= nl2br(Security::e($record['experiencia_necessaria'] ?: 'Não informada')) ?></dd></div>
            <div><dt class="font-semibold text-text-primary">Entregas esperadas</dt><dd class="text-text-secondary"><?= nl2br(Security::e($record['entregas_esperadas'])) ?></dd></div>
            <div><dt class="font-semibold text-text-primary">Competências técnicas</dt><dd class="text-text-secondary"><?= !empty($record['competencias_tecnicas']) ? Security::e(implode(', ', array_column($record['competencias_tecnicas'], 'nome'))) : 'Nenhuma informada' ?></dd></div>
            <div><dt class="font-semibold text-text-primary">Competências comportamentais</dt><dd class="text-text-secondary"><?= !empty($record['competencias_comportamentais']) ? Security::e(implode(', ', array_column($record['competencias_comportamentais'], 'nome'))) : 'Nenhuma informada' ?></dd></div>
            <div><dt class="font-semibold text-text-primary">Nível de responsabilidade</dt><dd class="text-text-secondary"><?= Security::e($nivelLabels[$record['nivel_responsabilidade']] ?? $record['nivel_responsabilidade']) ?></dd></div>
          </dl>
        </div>
      </section>

      <section class="rounded-xl border bg-white p-5 shadow-sm">
        <h3 class="text-lg font-semibold text-text-primary">5. Prazos e prioridade</h3>
        <div class="mt-4 grid gap-4 xl:grid-cols-3">
          <div><span class="text-xs font-semibold uppercase tracking-wide text-text-secondary">Data prevista para início</span><div class="mt-1 text-sm text-text-primary"><?= Security::e($record['data_prevista_inicio_br']) ?></div></div>
          <div><span class="text-xs font-semibold uppercase tracking-wide text-text-secondary">Urgência</span><div class="mt-1 text-sm text-text-primary"><?= Security::e($urgenciaLabels[$record['urgencia']] ?? $record['urgencia']) ?></div></div>
          <div><span class="text-xs font-semibold uppercase tracking-wide text-text-secondary">Data limite desejada para fechamento</span><div class="mt-1 text-sm text-text-primary"><?= Security::e($record['data_limite_fechamento_br'] ?: 'Não informada') ?></div></div>
        </div>
      </section>

      <section class="rounded-xl border bg-white p-5 shadow-sm">
        <h3 class="text-lg font-semibold text-text-primary">6. Aprovações</h3>
        <div class="mt-4 grid gap-4 xl:grid-cols-2">
          <div class="rounded-lg border p-4">
            <div class="text-sm font-semibold text-text-primary">Líder imediato</div>
            <div class="mt-2 text-sm text-text-secondary">Destinatário: <?= Security::e($leaderApproval['destinatario_nome'] ?? $record['lider_nome'] ?? 'Não configurado') ?></div>
            <div class="mt-1 text-sm text-text-secondary">Status: <?= Security::e(ucfirst((string)($leaderApproval['status'] ?? 'pendente'))) ?></div>
            <div class="mt-1 text-sm text-text-secondary">Data: <?= !empty($leaderApproval['aprovado_em']) ? Security::e(date('d/m/Y H:i', strtotime((string)$leaderApproval['aprovado_em']))) : 'Pendente' ?></div>
            <?php if (!empty($leaderApproval['aprovador_nome'])): ?><div class="mt-1 text-sm text-text-secondary">Aprovador: <?= Security::e($leaderApproval['aprovador_nome']) ?></div><?php endif; ?>
            <?php if ($canApproveLeader): ?>
              <form action="<?= $base ?>/admin/solicitacoes-vaga/<?= (int)$record['id'] ?>/aprovar-lider" method="post" class="mt-4 space-y-3">
                <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
                <textarea name="comment" rows="3" class="w-full rounded border px-3 py-2 text-sm" placeholder="Observações da aprovação (opcional)"></textarea>
                <div class="flex flex-wrap gap-2">
                  <button type="submit" name="decision" value="aprovado" class="rounded-lg bg-primary-700 px-4 py-2 text-sm font-medium text-white hover:bg-primary-800">Aprovar</button>
                  <button type="submit" name="decision" value="reprovado" class="rounded-lg bg-danger px-4 py-2 text-sm font-medium text-white hover:bg-danger/90">Reprovar</button>
                </div>
              </form>
            <?php endif; ?>
          </div>

          <div class="rounded-lg border p-4">
            <div class="text-sm font-semibold text-text-primary">Recursos Humanos</div>
            <div class="mt-2 text-sm text-text-secondary">Status: <?= Security::e(ucfirst((string)($rhApproval['status'] ?? 'pendente'))) ?></div>
            <div class="mt-1 text-sm text-text-secondary">Data: <?= !empty($rhApproval['aprovado_em']) ? Security::e(date('d/m/Y H:i', strtotime((string)$rhApproval['aprovado_em']))) : 'Pendente' ?></div>
            <?php if (!empty($rhApproval['aprovador_nome'])): ?><div class="mt-1 text-sm text-text-secondary">Aprovador: <?= Security::e($rhApproval['aprovador_nome']) ?></div><?php endif; ?>
            <?php if ($canApproveRh): ?>
              <form action="<?= $base ?>/admin/solicitacoes-vaga/<?= (int)$record['id'] ?>/aprovar-rh" method="post" class="mt-4 space-y-3">
                <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
                <textarea name="comment" rows="3" class="w-full rounded border px-3 py-2 text-sm" placeholder="Observações da aprovação (opcional)"></textarea>
                <div class="flex flex-wrap gap-2">
                  <button type="submit" name="decision" value="aprovado" class="rounded-lg bg-primary-700 px-4 py-2 text-sm font-medium text-white hover:bg-primary-800">Aprovar</button>
                  <button type="submit" name="decision" value="reprovado" class="rounded-lg bg-danger px-4 py-2 text-sm font-medium text-white hover:bg-danger/90">Reprovar</button>
                </div>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </section>

      <section class="rounded-xl border bg-white p-5 shadow-sm">
        <h3 class="text-lg font-semibold text-text-primary">7. Controle interno RH</h3>
        <form action="<?= $base ?>/admin/solicitacoes-vaga/<?= (int)$record['id'] ?>/controle-rh" method="post" class="mt-4 space-y-4">
          <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
          <div class="grid gap-4 xl:grid-cols-2">
            <div>
              <label class="block text-sm font-medium text-text-primary">Nome do contratado</label>
              <select name="nome_contratado_colaborador_id" class="mt-1 w-full rounded border px-3 py-2" <?= $canEditRhSection ? '' : 'disabled' ?>>
                <option value="">Selecione</option>
                <?php foreach ($colaboradores as $colaborador): ?>
                  <option value="<?= (int)$colaborador['id'] ?>" <?= (int)($record['nome_contratado_colaborador_id'] ?? 0) === (int)$colaborador['id'] ? 'selected' : '' ?>><?= Security::e($colaborador['nome']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-text-primary">Data de admissão</label>
              <input type="text" name="data_admissao" value="<?= Security::e($record['data_admissao_br'] ?? '') ?>" class="mt-1 w-full rounded border px-3 py-2" placeholder="DD/MM/AAAA" data-mask-date="1" <?= $canEditRhSection ? '' : 'disabled' ?>>
            </div>
          </div>
          <div class="grid gap-4 xl:grid-cols-3">
            <div>
              <label class="block text-sm font-medium text-text-primary">Tempo para fechamento da vaga</label>
              <input type="text" value="<?= Security::e((string)($record['tempo_fechamento_dias'] ?? '')) ?><?= !empty($record['tempo_fechamento_dias']) ? ' dias' : '' ?>" class="mt-1 w-full rounded border bg-surface-secondary px-3 py-2" readonly>
            </div>
            <div class="xl:col-span-2">
              <label class="block text-sm font-medium text-text-primary">Avaliação após 90 dias</label>
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
            <label class="block text-sm font-medium text-text-primary">Observações</label>
            <textarea name="observacoes_rh" rows="4" class="mt-1 w-full rounded border px-3 py-2" <?= $canEditRhSection ? '' : 'disabled' ?>><?= Security::e($record['observacoes_rh'] ?? '') ?></textarea>
          </div>
          <?php if ($canEditRhSection): ?>
            <div class="responsive-form-actions pt-2">
              <button type="submit" class="rounded-lg bg-primary-700 px-4 py-3 text-sm font-medium text-white hover:bg-primary-800">Salvar controle RH</button>
            </div>
          <?php else: ?>
            <p class="text-sm text-text-secondary">Somente usuários com perfil RH podem editar esta seção após a aprovação formal da solicitação.</p>
          <?php endif; ?>
        </form>
      </section>

      <section class="rounded-xl border bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h3 class="text-lg font-semibold text-text-primary">Situação operacional (Kanban de Solicitações de Vaga)</h3>
          <a href="<?= $base ?>/admin/solicitacoes-vaga/kanban" class="text-sm font-medium text-primary-700 hover:underline">Abrir Kanban</a>
        </div>
        <p class="mt-1 text-xs text-text-secondary">Independente do fluxo de aprovação líder/RH acima — reflete só o andamento operacional da vaga.</p>
        <?php $svStage = $record['situacao_kanban'] ?? null; ?>
        <div class="mt-3">
          <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold text-white" style="background-color: <?= Security::e($svStage['cor'] ?? '#6b7280') ?>">
            <?= Security::e($svStage['nome'] ?? 'Não definida') ?>
          </span>
          <?php if (!empty($record['cancelada_em_br'])): ?>
            <p class="mt-2 text-sm text-text-secondary">Cancelada em <?= Security::e($record['cancelada_em_br']) ?><?= !empty($record['motivo_cancelamento']) ? ' — Motivo: ' . Security::e($record['motivo_cancelamento']) : '' ?></p>
          <?php endif; ?>
          <?php if (!empty($record['fechada_em_br'])): ?>
            <p class="mt-2 text-sm text-text-secondary">Fechada em <?= Security::e($record['fechada_em_br']) ?></p>
          <?php endif; ?>
        </div>

        <form method="POST" action="<?= $base ?>/admin/solicitacoes-vaga/<?= (int)$record['id'] ?>/anotacao" class="mt-4">
          <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
          <label class="block text-sm font-medium text-text-primary">Anotações / Observações do RH</label>
          <textarea name="texto" rows="3" class="mt-1 w-full rounded border px-3 py-2" placeholder="Registre uma observação sobre o andamento desta vaga" required></textarea>
          <div class="responsive-form-actions pt-2">
            <button type="submit" class="rounded-lg bg-primary-700 px-4 py-3 text-sm font-medium text-white hover:bg-primary-800">Adicionar anotação</button>
          </div>
        </form>

        <h4 class="mt-6 text-sm font-semibold text-text-primary">Histórico</h4>
        <div class="mt-2 space-y-3">
          <?php if (empty($record['kanban_historico'])): ?>
            <p class="text-sm text-text-secondary">Nenhum evento registrado ainda.</p>
          <?php endif; ?>
          <?php foreach (($record['kanban_historico'] ?? []) as $evento): ?>
            <?php $isNotaAvulsa = ($evento['situacao_anterior'] ?? null) === ($evento['situacao_nova'] ?? null); ?>
            <div class="rounded-lg border border-border bg-surface-secondary p-3 text-sm">
              <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-text-secondary">
                <span><?= Security::e(date('d/m/Y H:i', strtotime((string)$evento['created_at']))) ?> — <?= Security::e($evento['usuario_nome'] ?? 'Sistema') ?></span>
                <span class="font-medium <?= $isNotaAvulsa ? 'text-text-primary' : 'text-primary-700' ?>">
                  <?= $isNotaAvulsa ? 'Anotação' : 'Movimentação: ' . Security::e((string)($evento['situacao_anterior'] ?? '—')) . ' → ' . Security::e((string)$evento['situacao_nova']) ?>
                </span>
              </div>
              <?php if (!empty($evento['observacao'])): ?>
                <p class="mt-1 text-text-primary"><?= nl2br(Security::e($evento['observacao'])) ?></p>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="rounded-xl border bg-white p-5 shadow-sm">
        <h3 class="text-lg font-semibold text-text-primary">Auditoria</h3>
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
</div>
