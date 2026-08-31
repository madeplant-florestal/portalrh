<?php
require_once __DIR__ . '/partials/chart-helpers.php';

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

$corPrincipal = '#1d2d44';
$corSuave = '#c9d2db';
?>
<style>
  .ind-shell { margin: 0 auto; max-width: 1280px; }
  .ind-panel {
    border-radius: 22px;
    background: #fff;
    padding: 1.1rem;
    border: 1px solid rgba(148, 163, 184, 0.16);
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
  }
  .ind-kpi-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
  .ind-grid { display: grid; grid-template-columns: 1fr; gap: 1rem; }
  @media (min-width: 768px) {
    .ind-kpi-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
  }
  @media (min-width: 1280px) {
    .ind-kpi-grid { grid-template-columns: repeat(5, minmax(0, 1fr)); }
    .ind-grid { grid-template-columns: repeat(12, minmax(0, 1fr)); }
    .ind-span-7 { grid-column: span 7 / span 7; }
    .ind-span-5 { grid-column: span 5 / span 5; }
    .ind-span-6 { grid-column: span 6 / span 6; }
  }
  .ind-title { font-size: 1rem; font-weight: 700; color: #0f172a; }
  .ind-subtitle { margin-top: 0.2rem; font-size: 0.75rem; color: #64748b; }
  .ind-empty { padding: 2rem 1rem; text-align: center; color: #64748b; font-size: 0.875rem; }
</style>
<div class="ind-shell space-y-4">
  <section class="ind-panel ring-1 ring-slate-200">
    <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
      <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900 xl:text-[2rem]">Indicadores de RH</h1>
        <p class="mt-1 text-sm text-slate-500">Quadro, movimentação e turnover — alimentado pelos dados oficiais sincronizados do METADADOS</p>
      </div>
      <a href="<?= $base ?>/admin" class="ct-btn ct-btn-muted">Voltar ao Dashboard</a>
    </div>
    <form method="get" class="mt-4 flex flex-wrap gap-2">
      <select name="periodo" data-autosubmit="1" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
        <?php foreach ($periodos as $chave => $label): ?>
          <option value="<?= Security::e($chave) ?>" <?= $periodoSelecionado === $chave ? 'selected' : '' ?>><?= Security::e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="empresa" data-autosubmit="1" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
        <option value="">Todas as empresas</option>
        <?php foreach ($opcoesFiltro['empresas'] as $empresa): ?>
          <option value="<?= Security::e($empresa['codigo_empresa']) ?>" <?= $filtrosSelecionados['empresa'] === $empresa['codigo_empresa'] ? 'selected' : '' ?>><?= Security::e($empresa['empresa'] ?? $empresa['codigo_empresa']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="unidade" data-autosubmit="1" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
        <option value="">Todas as unidades</option>
        <?php foreach ($opcoesFiltro['unidades'] as $unidade): ?>
          <option value="<?= Security::e($unidade['codigo_unidade']) ?>" <?= $filtrosSelecionados['unidade'] === $unidade['codigo_unidade'] ? 'selected' : '' ?>><?= Security::e($unidade['unidade'] ?? $unidade['codigo_unidade']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="cargo" data-autosubmit="1" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
        <option value="">Todos os cargos</option>
        <?php foreach ($opcoesFiltro['cargos'] as $cargo): ?>
          <option value="<?= Security::e($cargo) ?>" <?= $filtrosSelecionados['cargo'] === $cargo ? 'selected' : '' ?>><?= Security::e($cargo) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="setor" data-autosubmit="1" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
        <option value="">Todos os setores</option>
        <?php foreach ($opcoesFiltro['setores'] as $setor): ?>
          <option value="<?= Security::e($setor) ?>" <?= $filtrosSelecionados['setor'] === $setor ? 'selected' : '' ?>><?= Security::e($setor) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="centro_custo" data-autosubmit="1" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
        <option value="">Todos os centros de custo</option>
        <?php foreach ($opcoesFiltro['centrosCusto'] as $centroCusto): ?>
          <option value="<?= Security::e($centroCusto) ?>" <?= $filtrosSelecionados['centro_custo'] === $centroCusto ? 'selected' : '' ?>><?= Security::e($centroCusto) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </section>

  <?php if ($erro !== null): ?>
    <section class="ind-panel ring-1 ring-red-200 bg-red-50">
      <p class="text-sm font-semibold text-red-700"><?= Security::e($erro) ?></p>
    </section>
  <?php elseif ($painel === null || $painel['total_contratos'] === 0): ?>
    <section class="ind-panel ring-1 ring-slate-200">
      <p class="ind-empty">Nenhum contrato encontrado para os filtros selecionados. Ajuste os filtros acima.</p>
    </section>
  <?php else: ?>
    <section class="ind-kpi-grid">
      <div class="ind-panel ring-1 ring-slate-200">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Headcount atual</p>
        <p class="mt-1 text-[2rem] font-bold leading-none text-slate-900"><?= number_format($painel['headcount_atual'], 0, ',', '.') ?></p>
        <p class="mt-1 text-xs text-slate-500">contratos ativos hoje</p>
      </div>
      <div class="ind-panel ring-1 ring-slate-200">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Admissões no período</p>
        <p class="mt-1 text-[2rem] font-bold leading-none text-slate-900"><?= number_format($painel['admissoes_periodo'], 0, ',', '.') ?></p>
        <p class="mt-1 text-xs text-slate-500"><?= Security::e($periodoInicio->format('d/m/Y')) ?> a <?= Security::e($periodoFim->format('d/m/Y')) ?></p>
      </div>
      <div class="ind-panel ring-1 ring-slate-200">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Desligamentos no período</p>
        <p class="mt-1 text-[2rem] font-bold leading-none text-slate-900"><?= number_format($painel['desligamentos_periodo'], 0, ',', '.') ?></p>
        <p class="mt-1 text-xs text-slate-500"><?= Security::e($periodoInicio->format('d/m/Y')) ?> a <?= Security::e($periodoFim->format('d/m/Y')) ?></p>
      </div>
      <div class="ind-panel ring-1 ring-slate-200">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Turnover no período</p>
        <p class="mt-1 text-[2rem] font-bold leading-none text-slate-900"><?= number_format($painel['turnover_periodo'], 1, ',', '.') ?>%</p>
        <p class="mt-1 text-xs text-slate-500">desligamentos ÷ headcount médio do período</p>
      </div>
      <div class="ind-panel ring-1 ring-slate-200">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Turnover precoce</p>
        <p class="mt-1 text-[2rem] font-bold leading-none text-slate-900"><?= number_format($painel['turnover_precoce']['percentual_precoce'], 1, ',', '.') ?>%</p>
        <p class="mt-1 text-xs text-slate-500">desligados com até 90 dias de casa</p>
      </div>
    </section>

    <section class="ind-grid">
      <article class="ind-panel ind-span-7 ring-1 ring-slate-200">
        <div class="mb-3">
          <h2 class="ind-title">Turnover — evolução mensal</h2>
          <p class="ind-subtitle">Taxa mensal (desligamentos ÷ headcount médio do mês) no período selecionado</p>
        </div>
        <?php if (count($painel['turnover_mensal']['valores']) >= 2): ?>
          <?= dashboard_line_chart($painel['turnover_mensal']['labels'], $painel['turnover_mensal']['valores'], $corPrincipal, $corSuave) ?>
        <?php else: ?>
          <p class="ind-empty">Período curto demais para exibir evolução mensal.</p>
        <?php endif; ?>
      </article>

      <article class="ind-panel ind-span-5 ring-1 ring-slate-200">
        <div class="mb-3">
          <h2 class="ind-title">Turnover precoce por faixa de permanência</h2>
          <p class="ind-subtitle">Desligamentos do período, por tempo entre admissão e saída</p>
        </div>
        <?php if ($painel['turnover_precoce']['total_desligamentos'] > 0): ?>
          <div class="space-y-3">
            <?php foreach ($painel['turnover_precoce']['faixas'] as $faixa): ?>
              <?= dashboard_bar_row($faixa['label'], $faixa['quantidade'], $painel['turnover_precoce']['total_desligamentos'], $faixa['quantidade'] . ' (' . number_format($faixa['percentual'], 1, ',', '.') . '%)') ?>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="ind-empty">Nenhum desligamento no período selecionado.</p>
        <?php endif; ?>
      </article>
    </section>

    <section class="ind-panel ring-1 ring-slate-200">
      <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 class="ind-title">Quadro atual e turnover por dimensão</h2>
          <p class="ind-subtitle">Distribuição do headcount atual e taxa de turnover no período, agrupados pela dimensão selecionada</p>
        </div>
        <form method="get" class="flex items-center gap-2">
          <?php foreach (['periodo', 'empresa', 'unidade', 'cargo', 'setor', 'centro_custo'] as $campoOculto): ?>
            <?php $valorOculto = $campoOculto === 'periodo' ? $periodoSelecionado : ($filtrosSelecionados[$campoOculto] ?? ''); ?>
            <input type="hidden" name="<?= Security::e($campoOculto) ?>" value="<?= Security::e($valorOculto) ?>">
          <?php endforeach; ?>
          <select name="dimensao" data-autosubmit="1" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
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
          <h3 class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Headcount atual por <?= Security::e(mb_strtolower($dimensoesValidas[$dimensaoSelecionada])) ?></h3>
          <?php if ($distribuicao === []): ?>
            <p class="ind-empty">Sem dados para esta dimensão.</p>
          <?php else: ?>
            <?php $maxDist = max(array_column($distribuicao, 'quantidade')); ?>
            <div class="space-y-3">
              <?php foreach (array_slice($distribuicao, 0, 10) as $item): ?>
                <?= dashboard_bar_row($item['label'], $item['quantidade'], $maxDist, $item['quantidade'] . ' (' . number_format($item['percentual'], 1, ',', '.') . '%)') ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
        <div>
          <h3 class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Turnover no período por <?= Security::e(mb_strtolower($dimensoesValidas[$dimensaoSelecionada])) ?> (taxa)</h3>
          <?php
          $comMovimento = array_values(array_filter($turnoverPorDimensao, static fn(array $i) => $i['headcount_medio'] > 0));
          ?>
          <?php if ($comMovimento === []): ?>
            <p class="ind-empty">Sem headcount suficiente nesta dimensão no período.</p>
          <?php else: ?>
            <?php $maxTaxa = max(array_column($comMovimento, 'taxa')) ?: 1; ?>
            <div class="space-y-3">
              <?php foreach (array_slice($comMovimento, 0, 10) as $item): ?>
                <?= dashboard_bar_row($item['label'], $item['taxa'], $maxTaxa, number_format($item['taxa'], 1, ',', '.') . '% (' . $item['desligamentos'] . ' desligamento(s))', 'bg-ctgreen') ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <section class="ind-grid">
      <article class="ind-panel ind-span-6 ring-1 ring-slate-200">
        <div class="mb-3">
          <h2 class="ind-title">Motivos de rescisão</h2>
          <p class="ind-subtitle">Ranking oficial do METADADOS — descrições nunca renomeadas</p>
        </div>
        <?php if ($painel['motivos_rescisao'] === []): ?>
          <p class="ind-empty">Nenhum desligamento registrado no espelho.</p>
        <?php else: ?>
          <?php $maxMotivo = max(array_column($painel['motivos_rescisao'], 'quantidade')); ?>
          <div class="space-y-3">
            <?php foreach (array_slice($painel['motivos_rescisao'], 0, 8) as $motivo): ?>
              <?= dashboard_bar_row($motivo['motivo'], $motivo['quantidade'], $maxMotivo, $motivo['quantidade'] . ' (' . number_format($motivo['percentual'], 1, ',', '.') . '%)') ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </article>

      <article class="ind-panel ind-span-6 ring-1 ring-slate-200">
        <div class="mb-3">
          <h2 class="ind-title">Tempo de permanência (colaboradores ativos)</h2>
          <p class="ind-subtitle">Mediana ao lado da média — poucos contratos muito antigos distorceriam a média sozinha</p>
        </div>
        <div class="mb-4 flex gap-6">
          <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Média</p>
            <p class="text-2xl font-bold text-slate-900"><?= number_format($painel['tempo_permanencia']['media_dias'] / 30, 1, ',', '.') ?> meses</p>
          </div>
          <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Mediana</p>
            <p class="text-2xl font-bold text-slate-900"><?= number_format($painel['tempo_permanencia']['mediana_dias'] / 30, 1, ',', '.') ?> meses</p>
          </div>
        </div>
        <?php if ($painel['tempo_permanencia']['faixas'] === []): ?>
          <p class="ind-empty">Sem colaboradores ativos para calcular tempo de permanência.</p>
        <?php else: ?>
          <?php $maxFaixaTempo = max(array_column($painel['tempo_permanencia']['faixas'], 'quantidade')) ?: 1; ?>
          <div class="space-y-3">
            <?php foreach ($painel['tempo_permanencia']['faixas'] as $faixa): ?>
              <?= dashboard_bar_row($faixa['label'], $faixa['quantidade'], $maxFaixaTempo, $faixa['quantidade'] . ' (' . number_format($faixa['percentual'], 1, ',', '.') . '%)', 'bg-ctlight') ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </article>
    </section>
  <?php endif; ?>
</div>
