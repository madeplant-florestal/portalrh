<?php
require_once __DIR__ . '/partials/chart-helpers.php';
require_once APP_PATH . '/views/partials/modulo-topo.php';

$dimensaoSelecionada = Security::sanitizeString($_GET['dimensao'] ?? 'setor');
$dimensoesValidas = [
    'empresa' => 'Empresa',
    'unidade' => 'Unidade',
    'cargo' => 'Cargo',
    'setor' => 'Setor',
    'centro_custo' => 'Centro de custo',
];
if (!array_key_exists($dimensaoSelecionada, $dimensoesValidas)) {
    $dimensaoSelecionada = 'setor';
}

// Cores decorativas da série (atributos SVG do helper): tokens do Design System (primary-700 / primary-100).
$corPrincipal = '#3B4822';
$corSuave = '#E4E9D6';
// Classes do botão de sincronização declaradas AQUI (o Tailwind só varre as views): o JS externo alterna entre elas por data-attribute.
$classeSyncBase = ui_btn('primario');
$classeSyncOk = 'inline-flex h-10 items-center justify-center gap-2 whitespace-nowrap rounded-ds-md bg-success px-4 text-ds-button text-white';
ui_script_pagina('indicadores-rh.js');
?>
<div class="space-y-4">
  <?= ui_modulo_topo($base, 'indicadores', 'indicadores-rh', [
      'titulo' => 'Indicadores de RH',
      'descricao' => 'Quadro, movimentação e turnover — alimentado pelos dados oficiais sincronizados do METADADOS',
  ]) ?>
  <div class="flex flex-wrap items-center justify-between gap-3">
    <div>
      <p class="text-ds-caption text-text-secondary" data-sync-ultima>Última atualização: <strong class="text-text-primary"><?= !empty($ultimaSincronizacao) ? Security::e($ultimaSincronizacao) : '—' ?></strong></p>
      <p class="mt-1 hidden text-ds-caption" data-sync-feedback role="status" aria-live="polite"></p>
    </div>
    <?php if (!empty($podeSincronizar)): ?>
      <button type="button"
              class="<?= $classeSyncBase ?>"
              data-sync-btn
              data-classe-base="<?= Security::e($classeSyncBase) ?>"
              data-classe-ok="<?= Security::e($classeSyncOk) ?>"
              data-endpoint="<?= $base ?>/admin/indicadores-rh/sincronizar"
              data-status-endpoint="<?= $base ?>/admin/indicadores-rh/sincronizar/status"
              <?= empty($orquestradorConfigurado) ? 'disabled title="Sincronização sob demanda ainda não configurada neste ambiente."' : '' ?>>
        <span data-sync-label>Atualizar dados</span>
      </button>
    <?php endif; ?>
  </div>
  <section class="rounded-ds-lg border border-border bg-surface p-3 shadow-resting">
    <form method="get" class="flex flex-wrap gap-2">
      <select name="periodo" data-autosubmit="1" class="rounded-ds-md border border-border bg-surface px-3 py-2 text-sm font-medium text-text-primary shadow-sm outline-none focus:border-focus focus:ring-2 focus:ring-primary-100">
        <?php foreach ($periodos as $chave => $label): ?>
          <option value="<?= Security::e($chave) ?>" <?= $periodoSelecionado === $chave ? 'selected' : '' ?>><?= Security::e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="empresa" data-autosubmit="1" class="rounded-ds-md border border-border bg-surface px-3 py-2 text-sm font-medium text-text-primary shadow-sm outline-none focus:border-focus focus:ring-2 focus:ring-primary-100">
        <option value="">Todas as empresas</option>
        <?php foreach ($opcoesFiltro['empresas'] as $empresa): ?>
          <option value="<?= Security::e($empresa['codigo_empresa']) ?>" <?= $filtrosSelecionados['empresa'] === $empresa['codigo_empresa'] ? 'selected' : '' ?>><?= Security::e($empresa['empresa'] ?? $empresa['codigo_empresa']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="unidade" data-autosubmit="1" class="rounded-ds-md border border-border bg-surface px-3 py-2 text-sm font-medium text-text-primary shadow-sm outline-none focus:border-focus focus:ring-2 focus:ring-primary-100">
        <option value="">Todas as unidades</option>
        <?php foreach ($opcoesFiltro['unidades'] as $unidade): ?>
          <option value="<?= Security::e($unidade['codigo_unidade']) ?>" <?= $filtrosSelecionados['unidade'] === $unidade['codigo_unidade'] ? 'selected' : '' ?>><?= Security::e($unidade['unidade'] ?? $unidade['codigo_unidade']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="cargo" data-autosubmit="1" class="rounded-ds-md border border-border bg-surface px-3 py-2 text-sm font-medium text-text-primary shadow-sm outline-none focus:border-focus focus:ring-2 focus:ring-primary-100">
        <option value="">Todos os cargos</option>
        <?php foreach ($opcoesFiltro['cargos'] as $cargo): ?>
          <option value="<?= Security::e($cargo) ?>" <?= $filtrosSelecionados['cargo'] === $cargo ? 'selected' : '' ?>><?= Security::e($cargo) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="setor" data-autosubmit="1" class="rounded-ds-md border border-border bg-surface px-3 py-2 text-sm font-medium text-text-primary shadow-sm outline-none focus:border-focus focus:ring-2 focus:ring-primary-100">
        <option value="">Todos os setores</option>
        <?php foreach ($opcoesFiltro['setores'] as $setor): ?>
          <option value="<?= Security::e($setor) ?>" <?= $filtrosSelecionados['setor'] === $setor ? 'selected' : '' ?>><?= Security::e($setor) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="centro_custo" data-autosubmit="1" class="rounded-ds-md border border-border bg-surface px-3 py-2 text-sm font-medium text-text-primary shadow-sm outline-none focus:border-focus focus:ring-2 focus:ring-primary-100">
        <option value="">Todos os centros de custo</option>
        <?php foreach ($opcoesFiltro['centrosCusto'] as $centroCusto): ?>
          <option value="<?= Security::e($centroCusto) ?>" <?= $filtrosSelecionados['centro_custo'] === $centroCusto ? 'selected' : '' ?>><?= Security::e($centroCusto) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </section>

  <?php if ($erro !== null): ?>
    <section class="rounded-ds-lg border border-danger/30 bg-danger/10 p-4">
      <p class="text-sm font-semibold text-danger"><?= Security::e($erro) ?></p>
    </section>
  <?php elseif ($painel === null || $painel['total_contratos'] === 0): ?>
    <section class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
      <p class="px-4 py-8 text-center text-sm text-text-secondary">Nenhum contrato encontrado para os filtros selecionados. Ajuste os filtros acima.</p>
    </section>
  <?php else: ?>
    <section class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
      <div class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
        <p class="text-xs font-semibold uppercase tracking-wide text-text-secondary">Headcount atual</p>
        <p class="mt-1 text-[2rem] font-bold leading-none text-text-primary"><?= number_format($painel['headcount_atual'], 0, ',', '.') ?></p>
        <p class="mt-1 text-xs text-text-secondary">contratos ativos hoje</p>
      </div>
      <div class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
        <p class="text-xs font-semibold uppercase tracking-wide text-text-secondary">Admissões no período</p>
        <p class="mt-1 text-[2rem] font-bold leading-none text-text-primary"><?= number_format($painel['admissoes_periodo'], 0, ',', '.') ?></p>
        <p class="mt-1 text-xs text-text-secondary"><?= Security::e($periodoInicio->format('d/m/Y')) ?> a <?= Security::e($periodoFim->format('d/m/Y')) ?></p>
      </div>
      <div class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
        <p class="text-xs font-semibold uppercase tracking-wide text-text-secondary">Desligamentos no período</p>
        <p class="mt-1 text-[2rem] font-bold leading-none text-text-primary"><?= number_format($painel['desligamentos_periodo'], 0, ',', '.') ?></p>
        <p class="mt-1 text-xs text-text-secondary"><?= Security::e($periodoInicio->format('d/m/Y')) ?> a <?= Security::e($periodoFim->format('d/m/Y')) ?></p>
      </div>
      <div class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
        <p class="text-xs font-semibold uppercase tracking-wide text-text-secondary">Turnover no período</p>
        <p class="mt-1 text-[2rem] font-bold leading-none text-text-primary"><?= number_format($painel['turnover_periodo'], 1, ',', '.') ?>%</p>
        <p class="mt-1 text-xs text-text-secondary">desligamentos ÷ headcount médio do período</p>
      </div>
      <div class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
        <p class="text-xs font-semibold uppercase tracking-wide text-text-secondary">Turnover precoce</p>
        <p class="mt-1 text-[2rem] font-bold leading-none text-text-primary"><?= number_format($painel['turnover_precoce']['percentual_precoce'], 1, ',', '.') ?>%</p>
        <p class="mt-1 text-xs text-text-secondary">desligados com até 90 dias de casa</p>
      </div>
    </section>

    <section class="grid grid-cols-1 gap-4 xl:grid-cols-12">
      <article class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting xl:col-span-7">
        <div class="mb-3">
          <h2 class="text-base font-bold text-text-primary">Turnover — evolução mensal</h2>
          <p class="mt-0.5 text-xs text-text-secondary">Taxa mensal (desligamentos ÷ headcount médio do mês) no período selecionado</p>
        </div>
        <?php if (count($painel['turnover_mensal']['valores']) >= 2): ?>
          <?= dashboard_line_chart($painel['turnover_mensal']['labels'], $painel['turnover_mensal']['valores'], $corPrincipal, $corSuave) ?>
        <?php else: ?>
          <p class="px-4 py-8 text-center text-sm text-text-secondary">Período curto demais para exibir evolução mensal.</p>
        <?php endif; ?>
      </article>

      <article class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting xl:col-span-5">
        <div class="mb-3">
          <h2 class="text-base font-bold text-text-primary">Turnover precoce por faixa de permanência</h2>
          <p class="mt-0.5 text-xs text-text-secondary">Desligamentos do período, por tempo entre admissão e saída</p>
        </div>
        <?php if ($painel['turnover_precoce']['total_desligamentos'] > 0): ?>
          <div class="space-y-3">
            <?php foreach ($painel['turnover_precoce']['faixas'] as $faixa): ?>
              <?= dashboard_bar_row($faixa['label'], $faixa['quantidade'], $painel['turnover_precoce']['total_desligamentos'], $faixa['quantidade'] . ' (' . number_format($faixa['percentual'], 1, ',', '.') . '%)', 'bg-primary-400') ?>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="px-4 py-8 text-center text-sm text-text-secondary">Nenhum desligamento no período selecionado.</p>
        <?php endif; ?>
      </article>
    </section>

    <section class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
      <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 class="text-base font-bold text-text-primary">Quadro atual e turnover por dimensão</h2>
          <p class="mt-0.5 text-xs text-text-secondary">Distribuição do headcount atual e taxa de turnover no período, agrupados pela dimensão selecionada</p>
        </div>
        <form method="get" class="flex items-center gap-2">
          <?php foreach (['periodo', 'empresa', 'unidade', 'cargo', 'setor', 'centro_custo'] as $campoOculto): ?>
            <?php $valorOculto = $campoOculto === 'periodo' ? $periodoSelecionado : ($filtrosSelecionados[$campoOculto] ?? ''); ?>
            <input type="hidden" name="<?= Security::e($campoOculto) ?>" value="<?= Security::e($valorOculto) ?>">
          <?php endforeach; ?>
          <select name="dimensao" data-autosubmit="1" class="rounded-ds-md border border-border bg-surface px-3 py-2 text-sm font-medium text-text-primary shadow-sm outline-none focus:border-focus focus:ring-2 focus:ring-primary-100">
            <?php foreach ($dimensoesValidas as $chave => $label): ?>
              <option value="<?= Security::e($chave) ?>" <?= $dimensaoSelecionada === $chave ? 'selected' : '' ?>><?= Security::e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>
      <?php
      $distribuicaoChave = 'distribuicao_' . $dimensaoSelecionada;
      $turnoverChave = 'turnover_por_' . $dimensaoSelecionada;
      $distribuicao = $painel[$distribuicaoChave] ?? [];
      $turnoverPorDimensao = $painel[$turnoverChave] ?? [];
      ?>
      <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div>
          <h3 class="mb-3 text-xs font-semibold uppercase tracking-wide text-text-secondary">Headcount atual por <?= Security::e(mb_strtolower($dimensoesValidas[$dimensaoSelecionada])) ?></h3>
          <?php if ($distribuicao === []): ?>
            <p class="px-4 py-8 text-center text-sm text-text-secondary">Sem dados para esta dimensão.</p>
          <?php else: ?>
            <?php $maxDist = max(array_column($distribuicao, 'quantidade')); ?>
            <div class="space-y-3">
              <?php foreach (array_slice($distribuicao, 0, 10) as $item): ?>
                <?= dashboard_bar_row($item['label'], $item['quantidade'], $maxDist, $item['quantidade'] . ' (' . number_format($item['percentual'], 1, ',', '.') . '%)', 'bg-primary-400') ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
        <div>
          <h3 class="mb-3 text-xs font-semibold uppercase tracking-wide text-text-secondary">Turnover no período por <?= Security::e(mb_strtolower($dimensoesValidas[$dimensaoSelecionada])) ?> (taxa)</h3>
          <?php
          $comMovimento = array_values(array_filter($turnoverPorDimensao, static fn(array $i) => $i['headcount_medio'] > 0));
          ?>
          <?php if ($comMovimento === []): ?>
            <p class="px-4 py-8 text-center text-sm text-text-secondary">Sem headcount suficiente nesta dimensão no período.</p>
          <?php else: ?>
            <?php $maxTaxa = max(array_column($comMovimento, 'taxa')) ?: 1; ?>
            <div class="space-y-3">
              <?php foreach (array_slice($comMovimento, 0, 10) as $item): ?>
                <?= dashboard_bar_row($item['label'], $item['taxa'], $maxTaxa, number_format($item['taxa'], 1, ',', '.') . '% (' . $item['desligamentos'] . ' desligamento(s))', 'bg-primary-600') ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <section class="grid grid-cols-1 gap-4 xl:grid-cols-12">
      <article class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting xl:col-span-6">
        <div class="mb-3">
          <h2 class="text-base font-bold text-text-primary">Motivos de rescisão</h2>
          <p class="mt-0.5 text-xs text-text-secondary">Ranking oficial do METADADOS — descrições nunca renomeadas</p>
        </div>
        <?php if ($painel['motivos_rescisao'] === []): ?>
          <p class="px-4 py-8 text-center text-sm text-text-secondary">Nenhum desligamento registrado no espelho.</p>
        <?php else: ?>
          <?php $maxMotivo = max(array_column($painel['motivos_rescisao'], 'quantidade')); ?>
          <div class="space-y-3">
            <?php foreach (array_slice($painel['motivos_rescisao'], 0, 8) as $motivo): ?>
              <?= dashboard_bar_row($motivo['motivo'], $motivo['quantidade'], $maxMotivo, $motivo['quantidade'] . ' (' . number_format($motivo['percentual'], 1, ',', '.') . '%)', 'bg-primary-400') ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </article>

      <article class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting xl:col-span-6">
        <div class="mb-3">
          <h2 class="text-base font-bold text-text-primary">Tempo de permanência (colaboradores ativos)</h2>
          <p class="mt-0.5 text-xs text-text-secondary">Mediana ao lado da média — poucos contratos muito antigos distorceriam a média sozinha</p>
        </div>
        <div class="mb-4 flex gap-6">
          <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-text-secondary">Média</p>
            <p class="text-2xl font-bold text-text-primary"><?= number_format($painel['tempo_permanencia']['media_dias'] / 30, 1, ',', '.') ?> meses</p>
          </div>
          <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-text-secondary">Mediana</p>
            <p class="text-2xl font-bold text-text-primary"><?= number_format($painel['tempo_permanencia']['mediana_dias'] / 30, 1, ',', '.') ?> meses</p>
          </div>
        </div>
        <?php if ($painel['tempo_permanencia']['faixas'] === []): ?>
          <p class="px-4 py-8 text-center text-sm text-text-secondary">Sem colaboradores ativos para calcular tempo de permanência.</p>
        <?php else: ?>
          <?php $maxFaixaTempo = max(array_column($painel['tempo_permanencia']['faixas'], 'quantidade')) ?: 1; ?>
          <div class="space-y-3">
            <?php foreach ($painel['tempo_permanencia']['faixas'] as $faixa): ?>
              <?= dashboard_bar_row($faixa['label'], $faixa['quantidade'], $maxFaixaTempo, $faixa['quantidade'] . ' (' . number_format($faixa['percentual'], 1, ',', '.') . '%)', 'bg-primary-400') ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </article>
    </section>
  <?php endif; ?>
</div>
