<?php
$palette = [
    'blue' => ['bg' => 'bg-blue-100', 'text' => 'text-blue-700', 'accent' => '#2563eb', 'soft' => '#dbeafe'],
    'violet' => ['bg' => 'bg-violet-100', 'text' => 'text-violet-700', 'accent' => '#7c3aed', 'soft' => '#ede9fe'],
    'teal' => ['bg' => 'bg-teal-100', 'text' => 'text-teal-700', 'accent' => '#0f766e', 'soft' => '#ccfbf1'],
    'green' => ['bg' => 'bg-green-100', 'text' => 'text-green-700', 'accent' => '#16a34a', 'soft' => '#dcfce7'],
    'amber' => ['bg' => 'bg-amber-100', 'text' => 'text-amber-700', 'accent' => '#f59e0b', 'soft' => '#fef3c7'],
    'pink' => ['bg' => 'bg-pink-100', 'text' => 'text-pink-700', 'accent' => '#ec4899', 'soft' => '#fce7f3'],
];

if (!function_exists('dashboard_icon')) {
    function dashboard_icon(string $type): string
    {
        $icons = [
            'users' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 19a4 4 0 0 0-8 0M12 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm7 8a4 4 0 0 0-3-3.87M17 5.13A3 3 0 0 1 17 11"/>',
            'refresh' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 4v5h5M20 20v-5h-5M5.64 18.36A8 8 0 0 0 18.36 18M18.36 6A8 8 0 0 0 5.64 6.64"/>',
            'calendar' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 3v3m8-3v3M4 9h16M5 5h14a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Z"/>',
            'smile' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 14s1.5 2 4 2 4-2 4-2M9 9h.01M15 9h.01"/><circle cx="12" cy="12" r="9" stroke-width="1.8"/>',
            'star' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="m12 3 2.8 5.67 6.26.91-4.53 4.42 1.07 6.24L12 17.27 6.4 20.24l1.07-6.24L2.94 9.58l6.26-.91L12 3Z"/>',
            'money' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 6v12m3-9.5a3 3 0 0 0-3-1.5 3 3 0 0 0 0 6 3 3 0 0 1 0 6 3 3 0 0 1-3-1.5"/><circle cx="12" cy="12" r="9" stroke-width="1.8"/>',
            'academic' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="m3 8.5 9-4 9 4-9 4-9-4Zm3 2.5v4.5c0 1.38 2.69 2.5 6 2.5s6-1.12 6-2.5V11"/>',
            'book' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 6.5A2.5 2.5 0 0 1 6.5 4H20v15H6.5A2.5 2.5 0 0 0 4 21V6.5Zm0 0A2.5 2.5 0 0 1 6.5 9H20"/>',
            'target' => '<circle cx="12" cy="12" r="8" stroke-width="1.8"/><circle cx="12" cy="12" r="4" stroke-width="1.8"/><circle cx="12" cy="12" r="1.5" fill="currentColor" stroke="none"/>',
        ];
        return $icons[$type] ?? $icons['users'];
    }
}

if (!function_exists('dashboard_sparkline')) {
    function dashboard_sparkline(array $values, string $stroke, string $fill, string $label): string
    {
        $width = 168.0;
        $height = 30.0;
        $padding = 3.0;
        $min = min($values);
        $max = max($values);
        if ($max === $min) {
            $max += 1;
        }
        $stepX = count($values) > 1 ? ($width - ($padding * 2)) / (count($values) - 1) : 0;
        $points = [];
        foreach (array_values($values) as $index => $value) {
            $x = $padding + ($index * $stepX);
            $y = $height - $padding - (($value - $min) / ($max - $min)) * ($height - ($padding * 2));
            $points[] = number_format($x, 2, '.', '') . ',' . number_format($y, 2, '.', '');
        }
        $area = $points;
        $area[] = number_format($width - $padding, 2, '.', '') . ',' . number_format($height - $padding, 2, '.', '');
        $area[] = number_format($padding, 2, '.', '') . ',' . number_format($height - $padding, 2, '.', '');

        return '<svg viewBox="0 0 ' . $width . ' ' . $height . '" class="dashboard-sparkline" preserveAspectRatio="none" role="img" aria-label="' . Security::e($label) . '">'
            . '<title>' . Security::e($label) . '</title>'
            . '<polygon points="' . implode(' ', $area) . '" fill="' . $fill . '" opacity="0.30"></polygon>'
            . '<polyline points="' . implode(' ', $points) . '" fill="none" stroke="' . $stroke . '" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"></polyline>'
            . '</svg>';
    }
}

if (!function_exists('dashboard_line_chart')) {
    function dashboard_line_chart(array $labels, array $values, string $stroke, string $fill, string $suffix = '%'): string
    {
        $width = 720.0;
        $height = 210.0;
        $left = 34.0;
        $right = 16.0;
        $top = 16.0;
        $bottom = 28.0;
        $min = min($values);
        $max = max($values);
        if ($max === $min) {
            $max += 1;
        }
        $stepX = count($values) > 1 ? ($width - $left - $right) / (count($values) - 1) : 0;
        $chartHeight = $height - $top - $bottom;
        $points = [];
        foreach (array_values($values) as $index => $value) {
            $x = $left + ($index * $stepX);
            $y = $top + ($max - $value) / ($max - $min) * $chartHeight;
            $points[] = ['x' => $x, 'y' => $y, 'value' => $value, 'label' => $labels[$index] ?? ''];
        }
        $polyline = implode(' ', array_map(static fn(array $point): string => number_format($point['x'], 2, '.', '') . ',' . number_format($point['y'], 2, '.', ''), $points));
        $area = $polyline . ' ' . number_format($width - $right, 2, '.', '') . ',' . number_format($height - $bottom, 2, '.', '') . ' ' . number_format($left, 2, '.', '') . ',' . number_format($height - $bottom, 2, '.', '');

        $grid = '';
        $axis = '';
        for ($i = 0; $i <= 4; $i++) {
            $y = $top + ($chartHeight / 4) * $i;
            $tick = $max - (($max - $min) / 4) * $i;
            $grid .= '<line x1="' . $left . '" y1="' . $y . '" x2="' . ($width - $right) . '" y2="' . $y . '" stroke="#e2e8f0" stroke-dasharray="3 5"></line>';
            $axis .= '<text x="4" y="' . ($y + 4) . '" font-size="10" fill="#94a3b8">' . number_format($tick, 0, ',', '.') . $suffix . '</text>';
        }

        $xLabels = '';
        foreach ($labels as $index => $label) {
            $x = $left + ($index * $stepX);
            $xLabels .= '<text x="' . $x . '" y="' . ($height - 8) . '" text-anchor="middle" font-size="10" fill="#94a3b8">' . Security::e($label) . '</text>';
        }

        $circles = '';
        foreach ($points as $point) {
            $circles .= '<circle cx="' . $point['x'] . '" cy="' . $point['y'] . '" r="4" fill="#ffffff" stroke="' . $stroke . '" stroke-width="2" tabindex="0">'
                . '<title>' . Security::e($point['label']) . ': ' . number_format($point['value'], 1, ',', '.') . $suffix . '</title>'
                . '</circle>';
        }

        return '<div class="dashboard-chart-scroll"><svg viewBox="0 0 ' . $width . ' ' . $height . '" class="dashboard-line-chart" preserveAspectRatio="none" role="img" aria-label="Gráfico de linha">'
            . $grid
            . $axis
            . '<polygon points="' . $area . '" fill="' . $fill . '" opacity="0.25"></polygon>'
            . '<polyline points="' . $polyline . '" fill="none" stroke="' . $stroke . '" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"></polyline>'
            . $circles
            . $xLabels
            . '</svg></div>';
    }
}

if (!function_exists('dashboard_donut')) {
    function dashboard_donut(array $segments, int $size = 138, int $thickness = 16): string
    {
        $total = 0.0;
        foreach ($segments as $segment) {
            $total += (float)$segment['value'];
        }
        if ($total <= 0) {
            $total = 1;
        }
        $radius = ($size - $thickness) / 2;
        $circumference = 2 * M_PI * $radius;
        $offset = 0.0;
        $svg = '<svg viewBox="0 0 ' . $size . ' ' . $size . '" class="dashboard-donut -rotate-90" role="img" aria-label="Gráfico de rosca">';
        $svg .= '<circle cx="' . ($size / 2) . '" cy="' . ($size / 2) . '" r="' . $radius . '" fill="none" stroke="#e2e8f0" stroke-width="' . $thickness . '"></circle>';
        foreach ($segments as $segment) {
            $value = (float)$segment['value'];
            $length = ($value / $total) * $circumference;
            $svg .= '<circle cx="' . ($size / 2) . '" cy="' . ($size / 2) . '" r="' . $radius . '" fill="none" stroke="' . $segment['color'] . '" stroke-width="' . $thickness . '" stroke-linecap="round" stroke-dasharray="' . number_format($length, 2, '.', '') . ' ' . number_format($circumference - $length, 2, '.', '') . '" stroke-dashoffset="' . number_format(-$offset, 2, '.', '') . '" tabindex="0">'
                . '<title>' . Security::e($segment['label']) . ': ' . number_format($value, 1, ',', '.') . '%</title>'
                . '</circle>';
            $offset += $length;
        }
        $svg .= '</svg>';
        return $svg;
    }
}
?>
<style>
  .dashboard-shell {
    margin: 0 auto;
    max-width: 1280px;
  }
  .dashboard-kpi-grid,
  .dashboard-grid,
  .dashboard-training-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 0.75rem;
  }
  .dashboard-panel {
    border-radius: 22px;
    background: #fff;
    padding: 1rem;
    border: 1px solid rgba(148, 163, 184, 0.16);
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
  }
  .dashboard-panel-compact {
    border-radius: 20px;
    background: #fff;
    padding: 0.9rem;
    border: 1px solid rgba(148, 163, 184, 0.16);
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
  }
  .dashboard-title {
    font-size: 1rem;
    font-weight: 700;
    line-height: 1.2;
    color: #0f172a;
  }
  .dashboard-subtitle {
    margin-top: 0.2rem;
    font-size: 0.75rem;
    line-height: 1.3;
    color: #64748b;
  }
  .dashboard-chart-scroll {
    overflow-x: auto;
    overflow-y: hidden;
    touch-action: pan-x pan-y pinch-zoom;
  }
  .dashboard-chart-scroll::-webkit-scrollbar {
    height: 6px;
  }
  .dashboard-chart-scroll::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 999px;
  }
  .dashboard-line-chart {
    width: 100%;
    min-width: 520px;
    height: 188px;
  }
  .dashboard-sparkline {
    width: 100%;
    height: 26px;
  }
  .dashboard-donut {
    margin: 0 auto;
    width: 132px;
    height: 132px;
  }
  @media (min-width: 640px) {
    .dashboard-kpi-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .dashboard-training-grid {
      grid-template-columns: repeat(3, minmax(0, 1fr));
    }
  }
  @media (min-width: 1280px) {
    .dashboard-kpi-grid {
      grid-template-columns: repeat(6, minmax(0, 1fr));
    }
    .dashboard-grid {
      grid-template-columns: repeat(12, minmax(0, 1fr));
      gap: 1rem;
    }
    .dashboard-span-3 {
      grid-column: span 3 / span 3;
    }
    .dashboard-span-4 {
      grid-column: span 4 / span 4;
    }
    .dashboard-span-5 {
      grid-column: span 5 / span 5;
    }
  }
  @media (min-width: 1280px) {
    .dashboard-panel {
      padding: 1.05rem 1.1rem;
    }
    .dashboard-panel-compact {
      padding: 0.95rem;
    }
  }
</style>
<div class="dashboard-shell space-y-4">
  <section class="dashboard-panel ring-1 ring-slate-200">
    <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
      <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900 xl:text-[2rem]">Dashboard de Indicadores de RH</h1>
        <p class="mt-1 text-sm text-slate-500">Visão geral dos principais indicadores de gestão de pessoas</p>
      </div>
      <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap xl:items-center">
        <form method="get" class="flex flex-col gap-2 sm:flex-row">
          <select name="period" data-autosubmit="1" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 shadow-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
            <?php foreach ($periodOptions as $key => $label): ?>
              <option value="<?= Security::e($key) ?>" <?= $selectedPeriod === $key ? 'selected' : '' ?>><?= Security::e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="area" data-autosubmit="1" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 shadow-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
            <?php foreach ($areaOptions as $key => $label): ?>
              <option value="<?= Security::e($key) ?>" <?= $selectedArea === $key ? 'selected' : '' ?>><?= Security::e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
        <a href="<?= $base ?>/admin/colaboradores" class="inline-flex items-center justify-center rounded-xl border border-blue-200 bg-blue-50 px-4 py-2.5 text-sm font-semibold text-blue-700 shadow-sm hover:bg-blue-100">
          Colaboradores
        </a>
        <button type="button" onclick="window.print()" title="Exportar o dashboard em PDF pela impressão do navegador" class="inline-flex items-center justify-center rounded-xl bg-blue-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-900">
          Exportar
        </button>
      </div>
    </div>
  </section>

  <section class="dashboard-kpi-grid">
    <?php foreach ($dashboard['kpis'] as $kpi): ?>
      <?php
      $scheme = $palette[$kpi['color']] ?? $palette['blue'];
      $href = $kpi['href'] ?? null;
      $tag = $href ? 'a' : 'div';
      ?>
      <<?= $tag ?><?= $href ? ' href="' . $base . $href . '"' : '' ?> class="dashboard-panel-compact block ring-1 ring-slate-200 transition hover:-translate-y-0.5 hover:shadow-md">
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <p class="truncate text-xs font-semibold uppercase tracking-wide text-slate-500"><?= Security::e($kpi['label']) ?></p>
            <p class="mt-1 text-[2rem] font-bold leading-none tracking-tight text-slate-900"><?= Security::e($kpi['value']) ?></p>
            <?php if ($kpi['suffix'] !== ''): ?>
              <p class="mt-1 text-sm text-slate-500"><?= Security::e($kpi['suffix']) ?></p>
            <?php endif; ?>
          </div>
          <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl <?= $scheme['bg'] ?> <?= $scheme['text'] ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-5 w-5">
              <?= dashboard_icon($kpi['icon']) ?>
            </svg>
          </div>
        </div>
        <div class="mt-3">
          <?= dashboard_sparkline($kpi['trend'], $scheme['accent'], $scheme['soft'], $kpi['label']) ?>
        </div>
        <div class="mt-2 flex items-center justify-between gap-2">
          <span class="text-[11px] font-semibold text-emerald-600"><?= Security::e($kpi['change']) ?></span>
          <?php if (!empty($href)): ?>
            <span class="text-[11px] font-semibold text-blue-700">Base real</span>
          <?php endif; ?>
        </div>
      </<?= $tag ?>>
    <?php endforeach; ?>
  </section>

  <section class="dashboard-grid">
    <article class="dashboard-panel dashboard-span-5 ring-1 ring-slate-200">
      <div class="mb-3 flex items-center justify-between gap-3">
        <div>
          <h2 class="dashboard-title">Turnover - últimos 12 meses</h2>
          <p class="dashboard-subtitle">Evolução mensal do indicador</p>
        </div>
        <span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700"><?= Security::e($dashboard['turnoverSeries']['highlight']) ?></span>
      </div>
      <?= dashboard_line_chart($dashboard['turnoverSeries']['labels'], $dashboard['turnoverSeries']['values'], '#2563eb', '#bfdbfe') ?>
    </article>

    <article class="dashboard-panel dashboard-span-3 ring-1 ring-slate-200">
      <h2 class="dashboard-title">Turnover por Gênero (12m)</h2>
      <p class="dashboard-subtitle">Comparativo percentual por público</p>
      <div class="mt-4 flex flex-col items-center gap-4 sm:flex-row sm:items-center sm:justify-between xl:flex-col xl:items-center">
        <?= dashboard_donut($dashboard['turnoverGenero']) ?>
        <div class="w-full space-y-3">
          <?php foreach ($dashboard['turnoverGenero'] as $item): ?>
            <div class="flex items-center gap-3">
              <span class="h-3 w-3 rounded-full" style="background-color: <?= $item['color'] ?>"></span>
              <div class="flex items-center justify-between gap-3 w-full">
                <span class="text-sm text-slate-600"><?= Security::e($item['label']) ?></span>
                <span class="text-xl font-bold text-slate-800"><?= number_format($item['value'], 1, ',', '.') ?>%</span>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </article>

    <article class="dashboard-panel dashboard-span-4 ring-1 ring-slate-200">
      <h2 class="dashboard-title">Turnover por Faixa Etária (12m)</h2>
      <p class="dashboard-subtitle">Distribuição percentual por faixa</p>
      <?php $maxFaixa = max(array_column($dashboard['turnoverFaixaEtaria'], 'value')); ?>
      <div class="mt-4 space-y-3.5">
        <?php foreach ($dashboard['turnoverFaixaEtaria'] as $item): ?>
          <div title="<?= Security::e($item['label']) ?>: <?= number_format($item['value'], 1, ',', '.') ?>%">
            <div class="mb-1 flex items-center justify-between text-[13px]">
              <span class="text-slate-600"><?= Security::e($item['label']) ?></span>
              <span class="font-semibold text-slate-800"><?= number_format($item['value'], 1, ',', '.') ?>%</span>
            </div>
            <div class="h-2.5 rounded-full bg-slate-100">
              <div class="h-2.5 rounded-full bg-violet-600" style="width: <?= ($item['value'] / $maxFaixa) * 100 ?>%"></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </article>
  </section>

  <section class="dashboard-grid">
    <article class="dashboard-panel dashboard-span-4 ring-1 ring-slate-200">
      <div class="flex items-start justify-between gap-3">
        <div>
          <h2 class="dashboard-title">Tempo Médio de Fechamento de Vagas</h2>
          <p class="dashboard-subtitle"><?= Security::e($dashboard['tempoFechamento']['meta']) ?></p>
        </div>
        <div class="rounded-2xl bg-blue-100 px-3 py-2 text-center text-blue-700">
          <p class="text-3xl font-bold leading-none"><?= (int)$dashboard['tempoFechamento']['value'] ?></p>
          <p class="mt-1 text-[11px] font-semibold uppercase tracking-wide">dias</p>
        </div>
      </div>
      <div class="mt-4 flex items-end gap-2.5">
        <?php $maxTempo = max(array_column($dashboard['tempoFechamento']['bars'], 'value')); ?>
        <?php foreach ($dashboard['tempoFechamento']['bars'] as $bar): ?>
          <div class="flex-1 text-center" title="<?= Security::e($bar['label']) ?>: <?= (int)$bar['value'] ?> dias">
            <div class="mx-auto flex h-24 items-end justify-center rounded-t-2xl bg-blue-50 px-1.5">
              <div class="w-full rounded-t-xl bg-blue-600" style="height: <?= ($bar['value'] / $maxTempo) * 100 ?>%"></div>
            </div>
            <p class="mt-2 text-[11px] font-semibold text-slate-600"><?= Security::e($bar['label']) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="mt-3 text-xs font-semibold text-emerald-600"><?= Security::e($dashboard['tempoFechamento']['change']) ?></p>
    </article>

    <article class="dashboard-panel dashboard-span-4 ring-1 ring-slate-200">
      <div class="flex items-start justify-between gap-3">
        <div>
          <h2 class="dashboard-title">Taxa de Retenção de Talentos</h2>
          <p class="dashboard-subtitle"><?= Security::e($dashboard['retencaoTalentos']['meta']) ?></p>
        </div>
        <div class="rounded-2xl bg-green-100 px-3 py-2 text-center text-green-700">
          <p class="text-3xl font-bold leading-none"><?= Security::e($dashboard['retencaoTalentos']['value']) ?></p>
        </div>
      </div>
      <?= dashboard_line_chart($dashboard['retencaoTalentos']['labels'], $dashboard['retencaoTalentos']['values'], '#16a34a', '#bbf7d0') ?>
      <p class="mt-1 text-xs font-semibold text-emerald-600"><?= Security::e($dashboard['retencaoTalentos']['change']) ?></p>
    </article>

    <article class="dashboard-panel dashboard-span-4 ring-1 ring-slate-200">
      <div class="flex items-start justify-between gap-3">
        <div>
          <h2 class="dashboard-title">Custo de Contratação</h2>
          <p class="dashboard-subtitle"><?= Security::e($dashboard['custoContratacao']['meta']) ?></p>
        </div>
        <div class="rounded-2xl bg-amber-100 px-3 py-2 text-center text-amber-700">
          <p class="text-2xl font-bold leading-none"><?= Security::e($dashboard['custoContratacao']['value']) ?></p>
        </div>
      </div>
      <div class="mt-4 flex items-end gap-2.5">
        <?php $maxCost = max(array_column($dashboard['custoContratacao']['bars'], 'value')); ?>
        <?php foreach ($dashboard['custoContratacao']['bars'] as $bar): ?>
          <div class="flex-1 text-center" title="<?= Security::e($bar['label']) ?>: R$ <?= number_format((float)$bar['value'], 0, ',', '.') ?>">
            <div class="mx-auto flex h-24 items-end justify-center rounded-t-2xl bg-amber-50 px-1.5">
              <div class="w-full rounded-t-xl bg-amber-500" style="height: <?= ($bar['value'] / $maxCost) * 100 ?>%"></div>
            </div>
            <p class="mt-2 text-[11px] font-semibold text-slate-600"><?= Security::e($bar['label']) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="mt-3 text-xs font-semibold text-emerald-600"><?= Security::e($dashboard['custoContratacao']['change']) ?></p>
    </article>
  </section>

  <section class="dashboard-grid">
    <article class="dashboard-panel dashboard-span-4 ring-1 ring-slate-200">
      <div class="mb-3 flex items-start justify-between gap-3">
        <div>
          <h2 class="dashboard-title">Colaboradores por Área</h2>
          <p class="dashboard-subtitle">Distribuição estimada por macroárea</p>
        </div>
        <a href="<?= $base ?>/admin/colaboradores" class="text-xs font-semibold text-blue-700 hover:text-blue-900">Ver base</a>
      </div>
      <div class="flex flex-col items-center gap-4 sm:flex-row sm:items-center xl:flex-row">
        <?= dashboard_donut($dashboard['colaboradoresArea']) ?>
        <div class="w-full space-y-2.5">
          <?php foreach ($dashboard['colaboradoresArea'] as $item): ?>
            <div class="flex items-center justify-between gap-3 text-[13px]" title="<?= Security::e($item['label']) ?>: <?= (int)$item['value'] ?> colaboradores">
              <div class="flex items-center gap-2">
                <span class="h-3 w-3 rounded-full" style="background-color: <?= $item['color'] ?>"></span>
                <span class="text-slate-600"><?= Security::e($item['label']) ?></span>
              </div>
              <div class="text-right">
                <div class="font-semibold text-slate-800"><?= (int)$item['value'] ?></div>
                <div class="text-[11px] text-slate-400"><?= Security::e($item['percent']) ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </article>

    <article class="dashboard-panel dashboard-span-4 ring-1 ring-slate-200">
      <h2 class="dashboard-title">Distribuição por Tempo de Empresa</h2>
      <p class="dashboard-subtitle">Faixas de permanência estimadas</p>
      <?php $maxTempoEmpresa = max(array_column($dashboard['tempoEmpresa'], 'value')); ?>
      <div class="mt-4 space-y-3.5">
        <?php foreach ($dashboard['tempoEmpresa'] as $item): ?>
          <div title="<?= Security::e($item['label']) ?>: <?= (int)$item['value'] ?>%">
            <div class="mb-1 flex items-center justify-between text-[13px]">
              <span class="text-slate-600"><?= Security::e($item['label']) ?></span>
              <span class="font-semibold text-slate-800"><?= (int)$item['value'] ?>%</span>
            </div>
            <div class="h-2.5 rounded-full bg-slate-100">
              <div class="h-2.5 rounded-full bg-blue-700" style="width: <?= ($item['value'] / $maxTempoEmpresa) * 100 ?>%"></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </article>

    <article class="dashboard-panel dashboard-span-4 ring-1 ring-slate-200">
      <h2 class="dashboard-title">Treinamento e Desenvolvimento</h2>
      <p class="dashboard-subtitle">Indicadores demonstrativos de capacitação</p>
      <div class="dashboard-training-grid mt-4">
        <?php foreach ($dashboard['treinamento'] as $item): ?>
          <?php $scheme = $palette[$item['color']] ?? $palette['blue']; ?>
          <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-2xl <?= $scheme['bg'] ?> <?= $scheme['text'] ?>">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-5 w-5">
                <?= dashboard_icon($item['icon']) ?>
              </svg>
            </div>
            <p class="mt-3 text-xs font-medium text-slate-500"><?= Security::e($item['label']) ?></p>
            <p class="mt-1 text-2xl font-bold leading-none text-slate-900"><?= Security::e($item['value']) ?></p>
            <p class="mt-2 text-[11px] font-semibold text-emerald-600"><?= Security::e($item['change']) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    </article>
  </section>

  <section class="dashboard-panel ring-1 ring-slate-200">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
      <div>
        <h2 class="dashboard-title">Base real de colaboradores conectada</h2>
        <p class="dashboard-subtitle">A lista administrativa já consome os registros persistidos no banco e está pronta para receber indicadores reais na próxima etapa.</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <a href="<?= $base ?>/admin/colaboradores" class="inline-flex items-center justify-center rounded-xl bg-blue-950 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-900">
          Abrir colaboradores
        </a>
        <span class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm font-semibold text-slate-600">
          <?= (int)$colaboradoresCadastrados ?> cadastro(s) disponíveis
        </span>
      </div>
    </div>
  </section>
</div>
