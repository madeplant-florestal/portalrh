<?php
/**
 * People Analytics — Dashboard Executivo Central do RH (Etapa 1 da reformulação, 2026-09;
 * refinamento visual 2026-09-25 — só layout/hierarquia/composição, nenhuma regra de negócio
 * mudou nesta rodada). Consolida Headcount/Turnover/Admissões/Desligamentos/Vagas com
 * comparativos de período e os principais gráficos do Dashboard de Turnover, sob a fórmula
 * oficial de Turnover (PeopleAnalyticsService::montarPainel() / RhIndicadoresService::
 * taxaTurnoverPeriodo()) — Turnover = desligados do período ÷ ativos do período × 100. Nenhum
 * cálculo é feito aqui: a view só formata o que o Service já entregou pronto.
 *
 * Narrativa visual (2026-09-25): resumo executivo (KPIs) → protagonista (Turnover Geral) →
 * tendência (evolução/admissões×desligamentos) → visão comparativa (empresa/setor) → visão
 * segmentada (setor/sexo/motivo) → complementares (faixa etária, integrações, experiência,
 * dados pendentes). Divisores de seção são só texto+regra (sem componente novo).
 *
 * Gráficos: SVG/HTML puro via partials/chart-helpers.php — sem biblioteca externa. Sem "Ver
 * valores em tabela" nesta tela (uso executivo/apresentação; análise tabular fica nos módulos
 * especializados). Sem interatividade de clique/filtro nesta etapa (Etapa 2).
 */
require_once __DIR__ . '/partials/chart-helpers.php';
require_once APP_PATH . '/views/partials/modulo-topo.php';

$pa = [
    'brand50' => '#F2F4EC', 'brand100' => '#E4E9D6', 'brand200' => '#A9B885', 'brand300' => '#819158',
    'brand400' => '#566B41', 'brand500' => '#3B4822', 'brand600' => '#2E3919', 'brand700' => '#232B13',
    'ink' => '#2B2E22', 'inkSoft' => '#5B5F4E', 'border' => '#E2DFD0', 'danger' => '#B23B3B',
];

$fmtN = static fn($v): string => number_format((float)$v, 0, ',', '.');
$fmtPct1 = static fn($v): string => number_format((float)$v, 1, ',', '.') . '%';
$fmtData = static fn(DateTimeImmutable $d): string => $d->format('d/m/Y');

// Seta neutra (nunca cor de "bom/ruim" — a variação é sempre mostrada como fato, não como juízo).
$fmtVariacao = static function (int $variacaoAbsoluta, ?float $variacaoPercentual, string $unidade = ''): string {
    $seta = $variacaoAbsoluta > 0 ? '↑' : ($variacaoAbsoluta < 0 ? '↓' : '→');
    $abs = ($variacaoAbsoluta > 0 ? '+' : '') . number_format($variacaoAbsoluta, 0, ',', '.') . $unidade;
    $pct = $variacaoPercentual === null ? '' : ' (' . ($variacaoPercentual > 0 ? '+' : '') . number_format($variacaoPercentual, 1, ',', '.') . '%)';
    return $seta . ' ' . $abs . $pct;
};
$fmtVariacaoPP = static function (float $pp): string {
    $seta = $pp > 0 ? '↑' : ($pp < 0 ? '↓' : '→');
    return $seta . ' ' . ($pp > 0 ? '+' : '') . number_format($pp, 1, ',', '.') . ' p.p.';
};

// Divisor de seção (narrativa visual) — só texto + regra, nenhum componente novo.
$secaoDivisor = static function (string $rotulo) use ($pa): string {
    return '<div class="flex items-center gap-3 pt-1">'
        . '<span class="text-[11px] font-bold uppercase tracking-wider" style="color:' . Security::e($pa['brand600']) . '">' . Security::e($rotulo) . '</span>'
        . '<span class="h-px flex-1 bg-border"></span>'
        . '</div>';
};

// Estado vazio compacto e consistente (reaproveitado em todos os gráficos sem dados) — ícone de
// partials/chart-helpers.php (dashboard_icon), sem nenhum componente/dependência nova.
$estadoVazio = static function (string $mensagem, string $icone = 'users'): string {
    return '<div class="mt-3 flex flex-col items-center justify-center gap-2 rounded-ds-md border border-dashed border-border bg-background py-7 text-center">'
        . '<svg class="h-7 w-7 text-text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor">' . dashboard_icon($icone) . '</svg>'
        . '<p class="max-w-[220px] text-[12px] text-text-secondary">' . Security::e($mensagem) . '</p>'
        . '</div>';
};
?>
<div class="space-y-5">

  <?= ui_modulo_topo($base, 'indicadores', 'people-analytics', [
      'titulo' => 'People Analytics',
      'descricao' => 'Dashboard executivo do RH — Headcount, Turnover, Admissões, Desligamentos e Vagas',
  ]) ?>

  <!-- A — Filtros: período, empresa, setor e comparativo -->
  <section class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <form method="get" class="flex flex-wrap items-center gap-2">
        <select name="periodo" data-autosubmit="1" class="rounded-ds-md border border-border bg-surface px-2.5 py-1.5 text-sm font-medium text-text-primary shadow-sm outline-none focus:border-focus focus:ring-2 focus:ring-primary-100">
          <?php foreach ($periodos as $chave => $label): ?>
            <option value="<?= Security::e($chave) ?>" <?= $periodoSelecionado === $chave ? 'selected' : '' ?>><?= Security::e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="comparativo" data-autosubmit="1" aria-label="Comparar com" class="rounded-ds-md border border-border bg-surface px-2.5 py-1.5 text-sm font-medium text-text-primary shadow-sm outline-none focus:border-focus focus:ring-2 focus:ring-primary-100">
          <?php foreach ($comparativos as $chave => $label): ?>
            <option value="<?= Security::e($chave) ?>" <?= $comparativoSelecionado === $chave ? 'selected' : '' ?>>Comparar: <?= Security::e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="empresa" data-autosubmit="1" class="rounded-ds-md border border-border bg-surface px-2.5 py-1.5 text-sm font-medium text-text-primary shadow-sm outline-none focus:border-focus focus:ring-2 focus:ring-primary-100">
          <option value="">Todas as empresas</option>
          <?php foreach ($opcoesFiltro['empresas'] as $empresa): ?>
            <option value="<?= Security::e($empresa['codigo_empresa']) ?>" <?= $filtrosSelecionados['empresa'] === $empresa['codigo_empresa'] ? 'selected' : '' ?>><?= Security::e($empresa['empresa'] ?? $empresa['codigo_empresa']) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="setor" data-autosubmit="1" class="rounded-ds-md border border-border bg-surface px-2.5 py-1.5 text-sm font-medium text-text-primary shadow-sm outline-none focus:border-focus focus:ring-2 focus:ring-primary-100">
          <option value="">Todos os setores</option>
          <?php foreach ($opcoesFiltro['setores'] as $setor): ?>
            <option value="<?= Security::e($setor['codigo_setor']) ?>" <?= $filtrosSelecionados['setor'] === $setor['codigo_setor'] ? 'selected' : '' ?>><?= Security::e($setor['nome'] ?? $setor['codigo_setor']) ?></option>
          <?php endforeach; ?>
          <!-- Sentinela ColaboradorMetadadosConsultaRepository::SETOR_NAO_INFORMADO — "Setor não
               informado" precisa ser selecionável, nunca escondido (§17 da correção de 2026-09). -->
          <option value="__sem_setor__" <?= $filtrosSelecionados['setor'] === '__sem_setor__' ? 'selected' : '' ?>>Setor não informado</option>
        </select>
        <!-- Preserva mês/ano/intervalo personalizado ao trocar empresa/setor/comparativo pelo formulário acima. -->
        <input type="hidden" name="mes" value="<?= Security::e($periodoParams['mes']) ?>">
        <input type="hidden" name="ano" value="<?= Security::e($periodoParams['ano']) ?>">
        <input type="hidden" name="data_inicio" value="<?= Security::e($periodoParams['data_inicio']) ?>">
        <input type="hidden" name="data_fim" value="<?= Security::e($periodoParams['data_fim']) ?>">
      </form>
      <p class="text-[11px] text-text-muted">Última atualização do METADADOS<br><strong class="text-[12px] font-semibold text-text-secondary"><?= !empty($ultimaSincronizacao) ? Security::e($ultimaSincronizacao) : '—' ?></strong></p>
    </div>

    <details class="mt-2.5" <?= in_array($periodoSelecionado, ['mes', 'ano_especifico', 'personalizado'], true) ? 'open' : '' ?>>
      <summary class="cursor-pointer text-xs font-semibold text-primary-700">Período específico ou personalizado</summary>
      <div class="mt-2 flex flex-wrap gap-3">
        <form method="get" class="flex items-end gap-1.5 rounded-ds-md bg-background p-2">
          <input type="hidden" name="periodo" value="mes">
          <input type="hidden" name="empresa" value="<?= Security::e($filtrosSelecionados['empresa']) ?>">
          <input type="hidden" name="setor" value="<?= Security::e($filtrosSelecionados['setor']) ?>">
          <input type="hidden" name="comparativo" value="<?= Security::e($comparativoSelecionado) ?>">
          <label class="text-[11px] text-text-secondary">Mês<br>
            <?php
              $nomesMeses = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho',
                  7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];
            ?>
            <select name="mes" class="rounded-ds-md border border-border bg-surface px-2 py-1 text-sm">
              <?php foreach ($nomesMeses as $m => $nomeMes): ?>
                <option value="<?= $m ?>" <?= (int)$periodoParams['mes'] === $m ? 'selected' : '' ?>><?= Security::e($nomeMes) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="text-[11px] text-text-secondary">Ano<br>
            <input type="number" name="ano" min="2015" max="2100" value="<?= Security::e($periodoParams['ano'] !== '' ? $periodoParams['ano'] : date('Y')) ?>" class="w-20 rounded-ds-md border border-border bg-surface px-2 py-1 text-sm">
          </label>
          <button type="submit" class="rounded-ds-md bg-primary-600 px-2.5 py-1 text-xs font-semibold text-white">Aplicar mês</button>
        </form>

        <form method="get" class="flex items-end gap-1.5 rounded-ds-md bg-background p-2">
          <input type="hidden" name="periodo" value="ano_especifico">
          <input type="hidden" name="empresa" value="<?= Security::e($filtrosSelecionados['empresa']) ?>">
          <input type="hidden" name="setor" value="<?= Security::e($filtrosSelecionados['setor']) ?>">
          <input type="hidden" name="comparativo" value="<?= Security::e($comparativoSelecionado) ?>">
          <label class="text-[11px] text-text-secondary">Ano específico<br>
            <input type="number" name="ano" min="2015" max="2100" value="<?= Security::e($periodoParams['ano'] !== '' ? $periodoParams['ano'] : date('Y')) ?>" class="w-24 rounded-ds-md border border-border bg-surface px-2 py-1 text-sm">
          </label>
          <button type="submit" class="rounded-ds-md bg-primary-600 px-2.5 py-1 text-xs font-semibold text-white">Aplicar ano</button>
        </form>

        <form method="get" class="flex items-end gap-1.5 rounded-ds-md bg-background p-2">
          <input type="hidden" name="periodo" value="personalizado">
          <input type="hidden" name="empresa" value="<?= Security::e($filtrosSelecionados['empresa']) ?>">
          <input type="hidden" name="setor" value="<?= Security::e($filtrosSelecionados['setor']) ?>">
          <input type="hidden" name="comparativo" value="<?= Security::e($comparativoSelecionado) ?>">
          <label class="text-[11px] text-text-secondary">De<br>
            <input type="date" name="data_inicio" value="<?= Security::e($periodoParams['data_inicio']) ?>" class="rounded-ds-md border border-border bg-surface px-2 py-1 text-sm">
          </label>
          <label class="text-[11px] text-text-secondary">Até<br>
            <input type="date" name="data_fim" value="<?= Security::e($periodoParams['data_fim']) ?>" class="rounded-ds-md border border-border bg-surface px-2 py-1 text-sm">
          </label>
          <button type="submit" class="rounded-ds-md bg-primary-600 px-2.5 py-1 text-xs font-semibold text-white">Aplicar intervalo</button>
        </form>
      </div>
    </details>

    <p class="mt-2.5 border-t border-border pt-2 text-[11px] leading-snug text-text-secondary">
      <strong class="text-text-primary"><?= Security::e($fmtData($periodoInicio)) ?> a <?= Security::e($fmtData($periodoFim)) ?></strong>
      <?php if ($painel !== null): ?>
        · comparando com <strong class="text-text-primary"><?= Security::e($comparativos[$comparativoSelecionado]) ?></strong> (<?= Security::e($fmtData($painel['comparativo']['periodo']['inicio'])) ?> a <?= Security::e($fmtData($painel['comparativo']['periodo']['fim'])) ?>)
      <?php endif; ?>
      · Turnover = desligados ÷ ativos do período × 100 · Empresa/Setor usam códigos oficiais do METADADOS
    </p>
    <?php if ($painel !== null && $painel['vagas']['empresa_sem_correspondencia']): ?>
      <p class="mt-1 text-[11px] font-medium text-warning">A Empresa selecionada ainda não tem correspondência no catálogo local de Empresas — Vagas Abertas fica zerada em vez de mostrar o total geral.</p>
    <?php endif; ?>
  </section>

  <?php if ($erro !== null): ?>
    <section class="rounded-ds-lg border border-danger/30 bg-danger/10 p-4">
      <p class="text-sm font-semibold text-danger"><?= Security::e($erro) ?></p>
    </section>
  <?php else: ?>

  <!-- B — Faixa executiva de KPIs -->
  <?php
    $kpis = [
        [
            'icone' => 'users', 'label' => 'Headcount Atual', 'valor' => $fmtN($painel['headcount']['atual']),
            'variacao' => $fmtVariacao($painel['headcount']['variacao_absoluta'], $painel['headcount']['variacao_percentual']), 'comparativo' => true,
        ],
        [
            'icone' => 'refresh', 'label' => 'Turnover Geral', 'valor' => $fmtPct1($painel['turnover']['geral_percentual']),
            'variacao' => $fmtVariacaoPP($painel['turnover']['comparativo']['variacao_pontos_percentuais']), 'comparativo' => true,
        ],
        [
            'icone' => 'calendar', 'label' => 'Admissões', 'valor' => $fmtN($painel['admissoes']['periodo']),
            'variacao' => $fmtVariacao($painel['admissoes']['variacao_absoluta'], $painel['admissoes']['variacao_percentual']), 'comparativo' => true,
        ],
        [
            'icone' => 'exit', 'label' => 'Desligamentos', 'valor' => $fmtN($painel['desligamentos']['periodo']),
            'variacao' => $fmtVariacao($painel['desligamentos']['variacao_absoluta'], $painel['desligamentos']['variacao_percentual']), 'comparativo' => true,
        ],
        [
            'icone' => 'target', 'label' => 'Vagas Abertas', 'valor' => $fmtN($painel['vagas']['abertas']),
            'variacao' => $painel['vagas']['empresa_sem_correspondencia'] ? 'sem correspondência' : ($fmtN($painel['vagas']['fechadas_no_periodo']) . ' fechadas no período'), 'comparativo' => false,
        ],
    ];
  ?>
  <section class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
    <?php foreach ($kpis as $kpi): ?>
      <div class="rounded-ds-lg border border-border bg-surface p-4 shadow-elevated">
        <div class="flex items-center gap-2.5">
          <span class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-ds-md bg-primary-50 text-primary-700">
            <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor"><?= dashboard_icon($kpi['icone']) ?></svg>
          </span>
          <p class="text-[11px] font-semibold uppercase tracking-wide text-text-secondary"><?= Security::e($kpi['label']) ?></p>
        </div>
        <p class="mt-3 text-ds-kpi text-text-primary"><?= Security::e($kpi['valor']) ?></p>
        <p class="mt-2 border-t border-border pt-2 text-[11px] text-text-secondary"><?= Security::e($kpi['variacao']) ?><?php if ($kpi['comparativo']): ?> <span class="text-text-muted">vs. comparativo</span><?php endif; ?></p>
      </div>
    <?php endforeach; ?>
  </section>

  <!-- C — Turnover Geral executivo: número + comparação + composição (bloco protagonista) -->
  <section class="rounded-ds-lg border border-primary-100 bg-surface p-5 shadow-elevated">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
      <h2 class="text-ds-h3 text-text-primary">Turnover Geral</h2>
      <p class="text-[11px] text-text-secondary">Desligados ÷ ativos do período · <?= $fmtN($painel['turnover']['ativos_periodo']) ?> vigente(s) no intervalo</p>
    </div>
    <div class="mt-3 flex flex-col gap-5 lg:flex-row lg:items-center">
      <div class="flex-shrink-0 rounded-ds-md bg-primary-50 px-6 py-4 text-center lg:w-[240px]">
        <p class="text-[56px] font-extrabold leading-none text-primary-700"><?= Security::e($fmtPct1($painel['turnover']['geral_percentual'])) ?></p>
        <p class="mt-2 text-[12px] font-medium text-text-secondary"><?= Security::e($fmtVariacaoPP($painel['turnover']['comparativo']['variacao_pontos_percentuais'])) ?> vs. <?= Security::e($fmtPct1($painel['turnover']['comparativo']['percentual'])) ?></p>
        <p class="mt-1 text-[11px] text-text-muted"><?= $fmtN($painel['desligamentos']['periodo']) ?> desligamento(s)</p>
      </div>
      <div class="hidden h-24 w-px bg-border lg:block"></div>
      <?php
        $totalDeslPeriodo = $painel['desligamentos']['periodo'];
        $tv = $painel['turnover']['voluntario'];
        $ti = $painel['turnover']['involuntario'];
        $to = $painel['turnover']['outros'];
      ?>
      <div class="flex-1">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-text-secondary">Composição dos Desligamentos</p>
        <?php if ($totalDeslPeriodo === 0): ?>
          <p class="mt-2 text-[11px] text-text-secondary">Sem desligamentos no período.</p>
        <?php else: ?>
          <?php
            $segmentosRosca = [];
            if ($tv['eventos'] > 0) { $segmentosRosca[] = ['label' => 'Voluntário', 'value' => $tv['participacao_desligamentos'], 'color' => $pa['brand500']]; }
            if ($ti['eventos'] > 0) { $segmentosRosca[] = ['label' => 'Involuntário', 'value' => $ti['participacao_desligamentos'], 'color' => $pa['brand200']]; }
            if ($to['eventos'] > 0) { $segmentosRosca[] = ['label' => 'Outros', 'value' => $to['participacao_desligamentos'], 'color' => $pa['inkSoft']]; }
          ?>
          <div class="mt-2.5 flex items-center gap-5">
            <div class="w-[104px] flex-shrink-0"><?= dashboard_donut($segmentosRosca, 104, 15) ?></div>
            <ul class="space-y-2 text-sm text-text-primary">
              <li class="flex items-center gap-2"><span class="inline-block h-2.5 w-2.5 flex-shrink-0 rounded-full" style="background:<?= Security::e($pa['brand500']) ?>"></span><span><strong><?= number_format($tv['participacao_desligamentos'], 0, ',', '.') ?>%</strong> · <?= (int)$tv['eventos'] ?> Voluntário</span></li>
              <li class="flex items-center gap-2"><span class="inline-block h-2.5 w-2.5 flex-shrink-0 rounded-full" style="background:<?= Security::e($pa['brand200']) ?>"></span><span><strong><?= number_format($ti['participacao_desligamentos'], 0, ',', '.') ?>%</strong> · <?= (int)$ti['eventos'] ?> Involuntário</span></li>
              <?php if ($to['eventos'] > 0): ?>
                <li class="flex items-center gap-2"><span class="inline-block h-2.5 w-2.5 flex-shrink-0 rounded-full" style="background:<?= Security::e($pa['inkSoft']) ?>"></span><span><strong><?= number_format($to['participacao_desligamentos'], 0, ',', '.') ?>%</strong> · <?= (int)$to['eventos'] ?> Outros</span></li>
              <?php endif; ?>
            </ul>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <?php
    $serieAtual = $painel['evolucao_mensal']['atual'];
    $serieComp = $painel['evolucao_mensal']['comparativo'];
    $nPontos = max(count($serieAtual), count($serieComp));
    $labelsEvolucao = array_pad(array_column($serieAtual, 'label'), $nPontos, '');
    $pad = static fn(array $col, int $n): array => array_pad($col, $n, null);
    $taxaAtual = $pad(array_column($serieAtual, 'taxa'), $nPontos);
    $taxaComp = $pad(array_column($serieComp, 'taxa'), $nPontos);
  ?>

  <!-- D e E — Evolução Mensal e Admissões × Desligamentos -->
  <section class="grid grid-cols-1 gap-4 xl:grid-cols-2">
    <article class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
      <h2 class="text-ds-h3 text-text-primary">Evolução Mensal do Turnover</h2>
      <p class="text-[11px] text-text-secondary">Período selecionado × comparativo, por mês</p>
      <div class="mt-2">
        <?= dashboard_chart_legend([
            ['label' => 'Período selecionado', 'color' => $pa['brand500']],
            ['label' => 'Comparativo', 'color' => $pa['brand200']],
        ]) ?>
      </div>
      <div class="mt-1">
        <?= dashboard_multi_line_chart($labelsEvolucao, [
            ['label' => 'Período selecionado', 'color' => $pa['brand500'], 'values' => $taxaAtual],
            ['label' => 'Comparativo', 'color' => $pa['brand200'], 'values' => $taxaComp],
        ], '%', 1, 'Evolução mensal do turnover, período selecionado comparado ao comparativo') ?>
      </div>
    </article>

    <article class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
      <h2 class="text-ds-h3 text-text-primary">Admissões × Desligamentos</h2>
      <p class="text-[11px] text-text-secondary">Contratos por mês, no período selecionado</p>
      <div class="mt-2">
        <?= dashboard_chart_legend([
            ['label' => 'Admissões', 'color' => $pa['brand500']],
            ['label' => 'Desligamentos', 'color' => $pa['brand200']],
        ]) ?>
      </div>
      <div class="mt-1">
        <?= dashboard_grouped_columns(array_column($serieAtual, 'label'), [
            ['label' => 'Admissões', 'color' => $pa['brand500'], 'values' => array_column($serieAtual, 'admissoes')],
            ['label' => 'Desligamentos', 'color' => $pa['brand200'], 'values' => array_column($serieAtual, 'desligamentos')],
        ], 'Admissões e desligamentos por mês no período selecionado') ?>
      </div>
    </article>
  </section>

  <!-- Visão comparativa: Empresa e Setor (comparável lado a lado) -->
  <?= $secaoDivisor('Visão comparativa') ?>

  <!-- Headcount por Empresa e Turnover por Empresa -->
  <section class="grid grid-cols-1 gap-4 xl:grid-cols-2">
    <article class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
      <h2 class="text-ds-h3 text-text-primary">Headcount por Empresa</h2>
      <p class="text-[11px] text-text-secondary">Contratos ativos agora</p>
      <?php if ($painel['headcount_por_empresa'] === []): ?>
        <?= $estadoVazio('Nenhum contrato ativo para os filtros selecionados.') ?>
      <?php else: ?>
        <?php
          $headcountEmpresaItems = array_map(static function (array $item): array {
              return [
                  'label' => $item['label'],
                  'value' => $item['quantidade'],
                  'display' => number_format($item['quantidade'], 0, ',', '.'),
              ];
          }, $painel['headcount_por_empresa']);
        ?>
        <div class="mt-2.5"><?= dashboard_vertical_bars($headcountEmpresaItems, 'bg-primary-600') ?></div>
      <?php endif; ?>
    </article>

    <article class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
      <h2 class="text-ds-h3 text-text-primary">Turnover por Empresa</h2>
      <p class="text-[11px] text-text-secondary">Cada Empresa com sua própria população · mesma ordem do Headcount acima</p>
      <?php if ($painel['turnover']['por_empresa'] === []): ?>
        <?= $estadoVazio('Nenhum contrato para os filtros selecionados.', 'refresh') ?>
      <?php else: ?>
        <?php
          $turnoverEmpresaItems = array_map(static function (array $item): array {
              return [
                  'label' => $item['label'],
                  'value' => $item['taxa'],
                  'display' => number_format($item['taxa'], 1, ',', '.') . '%',
              ];
          }, $painel['turnover']['por_empresa']);
        ?>
        <div class="mt-2.5"><?= dashboard_vertical_bars($turnoverEmpresaItems, 'bg-primary-400') ?></div>
      <?php endif; ?>
    </article>
  </section>

  <!-- Visão segmentada: Setor (Turnover + Comparativo lado a lado), Sexo, Motivo -->
  <?= $secaoDivisor('Visão segmentada') ?>

  <section class="grid grid-cols-1 gap-4 items-stretch xl:grid-cols-2">
    <article class="flex flex-col rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
      <h2 class="text-ds-h3 text-text-primary">Turnover por Setor</h2>
      <p class="text-[11px] text-text-secondary">Cada Setor com sua própria população · "Setor não informado" nunca é omitido</p>
      <?php if ($painel['turnover']['por_setor'] === []): ?>
        <?= $estadoVazio('Nenhum contrato para os filtros selecionados.', 'refresh') ?>
      <?php else: ?>
        <?php $maxSetorTurnover = max(array_column($painel['turnover']['por_setor'], 'taxa')) ?: 1; ?>
        <div class="mt-3 space-y-2 overflow-y-auto" style="max-height:340px">
          <?php foreach ($painel['turnover']['por_setor'] as $item): ?>
            <?= dashboard_bar_row($item['label'], $item['taxa'], $maxSetorTurnover, number_format($item['taxa'], 1, ',', '.') . '% · ' . $item['desligamentos'] . ' desl. · ' . $item['ativos_periodo'] . ' ativo(s)', $item['codigo'] === '' ? 'bg-text-muted' : 'bg-primary-600') ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </article>

    <article class="flex flex-col rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
      <h2 class="text-ds-h3 text-text-primary">Colaboradores por Setor — Comparativo</h2>
      <p class="text-[11px] text-text-secondary">Período selecionado × comparativo, por Setor</p>
      <?php
        // Altura executiva: no máximo 12 setores no gráfico (hoje sempre cabem todos — cobre
        // crescimento futuro do catálogo de Setor sem nunca remover "Setor não informado" da
        // análise, só do NÚMERO DE BARRAS desenhado; o dado completo continua em $painel).
        $porSetorComp = $painel['colaboradores_por_setor_comparativo'];
        $limiteSetorComp = 12;
        $porSetorCompExibir = array_slice($porSetorComp, 0, $limiteSetorComp);
        $naoInformadoComp = null;
        foreach ($porSetorComp as $l) {
            if ($l['codigo'] === '') { $naoInformadoComp = $l; break; }
        }
        if ($naoInformadoComp !== null && !in_array($naoInformadoComp, $porSetorCompExibir, true)) {
            array_splice($porSetorCompExibir, -1, 1, [$naoInformadoComp]);
        }
      ?>
      <?php if ($porSetorComp === []): ?>
        <?= $estadoVazio('Nenhum contrato para os filtros selecionados.') ?>
      <?php else: ?>
        <div class="mt-2">
          <?= dashboard_chart_legend([
              ['label' => 'Período selecionado', 'color' => $pa['brand500']],
              ['label' => 'Comparativo', 'color' => $pa['brand200']],
          ]) ?>
        </div>
        <div class="mt-1">
          <?= dashboard_grouped_columns(array_column($porSetorCompExibir, 'label'), [
              ['label' => 'Período selecionado', 'color' => $pa['brand500'], 'values' => array_column($porSetorCompExibir, 'atual')],
              ['label' => 'Comparativo', 'color' => $pa['brand200'], 'values' => array_column($porSetorCompExibir, 'comparativo')],
          ], 'Colaboradores por setor, período selecionado comparado ao comparativo', ['rotacionar_eixo_x' => true, 'altura' => 230]) ?>
        </div>
        <?php if (count($porSetorComp) > count($porSetorCompExibir)): ?>
          <p class="mt-1.5 text-[11px] text-text-muted">Exibindo os <?= count($porSetorCompExibir) ?> principais de <?= count($porSetorComp) ?> setores · "Setor não informado" sempre incluído.</p>
        <?php endif; ?>
      <?php endif; ?>
    </article>
  </section>

  <section class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
    <h2 class="text-ds-h3 text-text-primary">Turnover por Sexo</h2>
    <p class="text-[11px] text-text-secondary">Mesma metodologia do Turnover Geral, segmentada pelo sexo oficial do METADADOS</p>
    <?php $genero = $painel['turnover']['genero']; ?>
    <div class="mt-2.5 grid grid-cols-1 gap-2.5 sm:grid-cols-2<?= $genero['nao_informado'] !== null ? ' lg:grid-cols-3' : '' ?>">
      <?php foreach ([['label' => 'Masculino', 'dado' => $genero['masculino']], ['label' => 'Feminino', 'dado' => $genero['feminino']]] as $bloco): ?>
        <div class="rounded-ds-md bg-background p-3.5 text-center">
          <p class="text-[11px] font-semibold text-text-secondary"><?= Security::e($bloco['label']) ?></p>
          <p class="mt-1 text-2xl font-bold text-text-primary"><?= number_format($bloco['dado']['taxa'], 1, ',', '.') ?>%</p>
          <p class="text-[11px] text-text-secondary"><?= (int)$bloco['dado']['desligamentos'] ?> desligamento(s) · <?= (int)$bloco['dado']['ativos_periodo'] ?> ativo(s)</p>
          <?php if ($bloco['dado']['comparativo_taxa'] !== null): ?>
            <p class="mt-1 text-[11px] text-text-muted"><?= Security::e($fmtVariacaoPP(round($bloco['dado']['taxa'] - $bloco['dado']['comparativo_taxa'], 1))) ?> vs. <?= number_format($bloco['dado']['comparativo_taxa'], 1, ',', '.') ?>%</p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if ($genero['nao_informado'] !== null): ?>
        <?php $naoInformado = $genero['nao_informado']; ?>
        <div class="rounded-ds-md bg-background p-3.5 text-center">
          <p class="text-[11px] font-semibold text-text-secondary">Não informado</p>
          <p class="mt-1 text-2xl font-bold text-text-primary"><?= number_format($naoInformado['taxa'], 1, ',', '.') ?>%</p>
          <p class="text-[11px] text-text-secondary"><?= (int)$naoInformado['desligamentos'] ?> desligamento(s) · <?= (int)$naoInformado['ativos_periodo'] ?> ativo(s)</p>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <section class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
    <h2 class="text-ds-h3 text-text-primary">Desligamentos por Motivo</h2>
    <p class="text-[11px] text-text-secondary">Mesma classificação do Dashboard de Turnover · códigos não mapeados entram em Outros</p>
    <?php $motivos = $painel['desligamentos_por_motivo']; ?>
    <?php if ($motivos['total'] === 0): ?>
      <?= $estadoVazio('Nenhum desligamento no período.', 'exit') ?>
    <?php else: ?>
      <?php $maxMotivo = max(array_column($motivos['categorias'], 'quantidade')) ?: 1; ?>
      <div class="mt-3 grid grid-cols-1 gap-x-6 gap-y-2 lg:grid-cols-2">
        <?php foreach ($motivos['categorias'] as $c): ?>
          <?= dashboard_bar_row($c['categoria'], $c['quantidade'], $maxMotivo, $c['quantidade'] . ' · ' . number_format((float)$c['percentual'], 1, ',', '.') . '%', 'bg-primary-600') ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <!-- Complementares: faixa etária, empresa, onboarding, dados pendentes -->
  <?= $secaoDivisor('Dados complementares') ?>

  <section class="grid grid-cols-1 gap-4 xl:grid-cols-2">
    <article class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
      <h2 class="text-ds-h3 text-text-primary">Desligamentos por Empresa</h2>
      <p class="text-[11px] text-text-secondary">Eventos de desligamento no período</p>
      <?php if ($painel['desligamentos_por_empresa'] === []): ?>
        <?= $estadoVazio('Nenhum desligamento no período para os filtros selecionados.', 'exit') ?>
      <?php else: ?>
        <div class="mt-2.5 space-y-2">
          <?php $maxDeslEmpresa = max(array_column($painel['desligamentos_por_empresa'], 'desligamentos')) ?: 1; ?>
          <?php foreach ($painel['desligamentos_por_empresa'] as $item): ?>
            <?= dashboard_bar_row($item['label'], $item['desligamentos'], $maxDeslEmpresa, (string)$item['desligamentos'], 'bg-primary-700') ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </article>

    <article class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
      <h2 class="text-ds-h3 text-text-primary">Turnover por Faixa Etária</h2>
      <p class="text-[11px] text-text-secondary">Desligamentos do período, por idade na rescisão</p>
      <?php if ($painel['turnover']['faixa_etaria'] === null): ?>
        <?= $estadoVazio('Dados insuficientes — nenhum desligamento classificável por idade no período.', 'clock') ?>
      <?php else: ?>
        <div class="mt-2.5 space-y-2">
          <?php $maxFaixa = max(array_column($painel['turnover']['faixa_etaria']['faixas'], 'quantidade')) ?: 1; ?>
          <?php foreach ($painel['turnover']['faixa_etaria']['faixas'] as $faixa): ?>
            <?= dashboard_bar_row($faixa['label'], $faixa['quantidade'], $maxFaixa, $faixa['quantidade'] . ' (' . number_format($faixa['percentual'], 1, ',', '.') . '%)', 'bg-primary-400') ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </article>
  </section>

  <!-- Integrações + Experiência: um único painel, dividido internamente (menos "blocos soltos") -->
  <section class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 sm:divide-x sm:divide-border">
      <div>
        <h2 class="text-ds-h3 text-text-primary">Integrações</h2>
        <p class="text-[11px] text-text-secondary">Realizadas no período</p>
        <p class="mt-1.5 text-2xl font-bold text-text-primary"><?= number_format($painel['integracao']['realizadas_periodo'], 0, ',', '.') ?></p>
        <div class="mt-2.5 border-t border-border pt-2.5">
          <p class="text-[11px] text-text-secondary">NPS Integração</p>
          <?php if ($painel['nps_integracao']['amostra'] === 0): ?>
            <p class="mt-0.5 text-sm text-text-secondary">Dados insuficientes</p>
          <?php else: ?>
            <p class="mt-0.5 text-xl font-bold text-text-primary"><?= number_format($painel['nps_integracao']['nps'], 1, ',', '.') ?></p>
            <p class="text-[11px] text-text-secondary"><?= (int)$painel['nps_integracao']['amostra'] ?> resposta(s)</p>
          <?php endif; ?>
        </div>
      </div>

      <div class="sm:pl-4">
        <h2 class="text-ds-h3 text-text-primary">Experiência</h2>
        <p class="text-[11px] text-text-secondary">Avaliação do período de 90 dias</p>
        <div class="mt-1.5 grid grid-cols-2 gap-2 text-center">
          <div class="rounded-ds-md bg-background p-2">
            <p class="text-xl font-bold text-success"><?= (int)$painel['avaliacao_experiencia']['realizadas'] ?></p>
            <p class="text-[11px] text-text-secondary">Realizadas</p>
          </div>
          <div class="rounded-ds-md bg-background p-2">
            <p class="text-xl font-bold text-warning"><?= (int)$painel['avaliacao_experiencia']['pendentes'] ?></p>
            <p class="text-[11px] text-text-secondary">Pendentes</p>
          </div>
        </div>
        <?php if ((int)$painel['avaliacao_experiencia']['realizadas'] === 0 && (int)$painel['avaliacao_experiencia']['pendentes'] === 0): ?>
          <p class="mt-1.5 text-[11px] text-text-secondary">O controle interno de RH em Solicitação de Vaga ainda não foi utilizado para nenhuma contratação.</p>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <!-- Colaboradores — listagem operacional (base antecipada da Etapa 2, só para viabilizar a
       verificação operacional dos indicadores, ex.: localizar "Setor não informado"). Responde
       só aos filtros do topo (empresa/setor); população = vigentes hoje. Sem interatividade de
       clique-para-filtrar nesta etapa (Etapa 2). -->
  <?= $secaoDivisor('Colaboradores') ?>

  <section class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h2 class="text-ds-h3 text-text-primary">Colaboradores vigentes</h2>
        <p class="text-[11px] text-text-secondary"><?= $fmtN($listagem['total']) ?> colaborador(es) · segue os filtros de empresa/setor do topo</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <form method="get" class="flex items-center gap-1.5">
          <?php
          $listagemCamposOcultos = [
              'periodo' => $periodoSelecionado, 'mes' => $periodoParams['mes'], 'ano' => $periodoParams['ano'],
              'data_inicio' => $periodoParams['data_inicio'], 'data_fim' => $periodoParams['data_fim'],
              'comparativo' => $comparativoSelecionado,
              'empresa' => $filtrosSelecionados['empresa'], 'setor' => $filtrosSelecionados['setor'],
          ];
          ?>
          <?php foreach ($listagemCamposOcultos as $campo => $valor): ?>
            <input type="hidden" name="<?= Security::e($campo) ?>" value="<?= Security::e($valor) ?>">
          <?php endforeach; ?>
          <label class="text-[11px] text-text-secondary" for="por_pagina">Por página</label>
          <select id="por_pagina" name="por_pagina" data-autosubmit="1" class="rounded-ds-md border border-border bg-surface px-2 py-1 text-sm">
            <option value="20" <?= $listagem['per_page'] === 20 ? 'selected' : '' ?>>20</option>
            <option value="50" <?= $listagem['per_page'] === 50 ? 'selected' : '' ?>>50</option>
          </select>
        </form>
        <?php
        $listagemExportParams = array_filter([
            'empresa' => $filtrosSelecionados['empresa'],
            'setor' => $filtrosSelecionados['setor'],
        ], static fn($v) => $v !== '');
        ?>
        <a href="/admin/dashboard/colaboradores/exportar<?= $listagemExportParams !== [] ? '?' . http_build_query($listagemExportParams) : '' ?>"
           class="inline-flex items-center gap-1.5 rounded-ds-md border border-border bg-surface px-3 py-1.5 text-sm font-medium text-text-secondary transition hover:bg-surface-secondary">
          Exportar CSV
        </a>
      </div>
    </div>

    <?php if ($listagem['items'] === []): ?>
      <?= $estadoVazio('Nenhum colaborador para os filtros selecionados.') ?>
    <?php else: ?>
      <div class="mt-3 overflow-x-auto rounded-ds-md border border-border">
        <table class="w-full min-w-[980px] text-sm">
          <thead class="bg-surface-secondary">
            <tr class="text-left text-[11px] font-semibold uppercase tracking-wide text-text-secondary">
              <th class="px-3 py-2">Nome</th>
              <th class="px-3 py-2">Empresa</th>
              <th class="px-3 py-2">Unidade</th>
              <th class="px-3 py-2">Setor</th>
              <th class="px-3 py-2">Cargo</th>
              <th class="px-3 py-2">Sexo</th>
              <th class="px-3 py-2">Admissão</th>
              <th class="px-3 py-2">Situação</th>
              <th class="px-3 py-2">Tempo de Empresa</th>
              <th class="px-3 py-2">Centro de Custo</th>
              <th class="px-3 py-2">Gestor Imediato</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border">
            <?php foreach ($listagem['items'] as $colab): ?>
              <?php
              $situacaoClasse = match ($colab['situacao']) {
                  'Ativo' => 'bg-success/10 text-success',
                  'Transferido' => 'bg-primary-100 text-primary-700',
                  default => 'bg-danger/10 text-danger',
              };
              ?>
              <tr class="hover:bg-surface-secondary">
                <td class="px-3 py-2 font-medium text-text-primary"><?= Security::e($colab['nome']) ?></td>
                <td class="px-3 py-2 text-text-secondary"><?= Security::e($colab['empresa']) ?></td>
                <td class="px-3 py-2 text-text-secondary"><?= Security::e($colab['unidade']) ?></td>
                <td class="px-3 py-2 text-text-secondary" title="<?= Security::e($colab['setor']) ?>"><?= Security::e($colab['setor']) ?></td>
                <td class="px-3 py-2 text-text-secondary"><?= Security::e($colab['cargo']) ?></td>
                <td class="px-3 py-2 text-text-secondary"><?= Security::e($colab['sexo']) ?></td>
                <td class="px-3 py-2 text-text-secondary"><?= Security::e($colab['admissao']) ?></td>
                <td class="px-3 py-2"><span class="inline-flex rounded-ds-sm px-2 py-0.5 text-[11px] font-semibold <?= $situacaoClasse ?>"><?= Security::e($colab['situacao']) ?></span></td>
                <td class="px-3 py-2 text-text-secondary"><?= Security::e($colab['tempo_empresa']) ?></td>
                <td class="px-3 py-2 text-text-secondary"><?= Security::e($colab['centro_custo']) ?></td>
                <td class="px-3 py-2 text-text-secondary"><?= Security::e($colab['gestor_imediato']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($listagem['pages'] > 1): ?>
        <?php
        $listagemParamsBase = array_filter(array_merge($listagemCamposOcultos, ['por_pagina' => $listagem['per_page']]), static fn($v) => $v !== '' && $v !== null);
        $listagemPaginaAtual = $listagem['page'];
        $listagemTotalPaginas = $listagem['pages'];
        $listagemPrevParams = array_merge($listagemParamsBase, ['pagina' => max(1, $listagemPaginaAtual - 1)]);
        $listagemNextParams = array_merge($listagemParamsBase, ['pagina' => min($listagemTotalPaginas, $listagemPaginaAtual + 1)]);
        $listagemJanelaInicio = max(1, $listagemPaginaAtual - 2);
        $listagemJanelaFim = min($listagemTotalPaginas, $listagemPaginaAtual + 2);
        ?>
        <div class="mt-3 flex flex-col gap-3 border-t border-dashed border-border pt-3 md:flex-row md:items-center md:justify-between">
          <div class="text-[11px] text-text-secondary">Página <?= $listagemPaginaAtual ?> de <?= $listagemTotalPaginas ?> · <?= $listagem['per_page'] ?> por página</div>
          <div class="flex flex-wrap items-center gap-1.5">
            <a href="?<?= http_build_query($listagemPrevParams) ?>" class="inline-flex min-h-[36px] items-center justify-center rounded-ds-md border border-border px-3 py-1.5 text-sm font-medium text-text-secondary transition hover:bg-surface-secondary <?= $listagemPaginaAtual <= 1 ? 'pointer-events-none opacity-50' : '' ?>">Anterior</a>
            <?php for ($p = $listagemJanelaInicio; $p <= $listagemJanelaFim; $p++): ?>
              <?php $listagemNumParams = array_merge($listagemParamsBase, ['pagina' => $p]); ?>
              <a href="?<?= http_build_query($listagemNumParams) ?>" class="inline-flex min-h-[36px] min-w-[36px] items-center justify-center rounded-ds-md border px-2.5 py-1.5 text-sm font-semibold transition <?= $p === $listagemPaginaAtual ? 'border-primary-700 bg-primary-700 text-white' : 'border-border text-text-secondary hover:bg-surface-secondary' ?>"><?= $p ?></a>
            <?php endfor; ?>
            <a href="?<?= http_build_query($listagemNextParams) ?>" class="inline-flex min-h-[36px] items-center justify-center rounded-ds-md border border-border px-3 py-1.5 text-sm font-medium text-text-secondary transition hover:bg-surface-secondary <?= $listagemPaginaAtual >= $listagemTotalPaginas ? 'pointer-events-none opacity-50' : '' ?>">Próxima</a>
          </div>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <!-- Dados ainda não integrados — rodapé discreto, sem protagonismo visual -->
  <section class="rounded-ds-md border border-dashed border-border/70 bg-surface-secondary/40 px-3.5 py-2.5">
    <p class="text-[10px] font-semibold uppercase tracking-wide text-text-muted">Dados ainda não integrados ao Portal</p>
    <p class="mt-0.5 text-[11px] text-text-muted">Banco de Horas · Horas Extras · Férias Programadas · Férias a Vencer</p>
  </section>

  <?php endif; ?>
</div>
