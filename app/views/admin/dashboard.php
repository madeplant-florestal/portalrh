<?php
// Cor deixou de ser decorativa por KPI: todo indicador usa o mesmo tratamento
// neutro derivado da marca (ctgreen). Cor real (verde/vermelho) fica reservada
// para os sinais de tendência que já existem no restante da tela.
$dashboardNeutralScheme = ['bg' => 'dashboard-icon-badge', 'text' => '', 'accent' => '#1d2d44', 'soft' => '#c9d2db'];
$palette = [
    'blue' => $dashboardNeutralScheme,
    'violet' => $dashboardNeutralScheme,
    'teal' => $dashboardNeutralScheme,
    'green' => $dashboardNeutralScheme,
    'amber' => $dashboardNeutralScheme,
    'pink' => $dashboardNeutralScheme,
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

if (!function_exists('dashboard_fmt')) {
    function dashboard_fmt(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}

if (!function_exists('dashboard_smooth_line_path')) {
    // Converte uma lista de pontos {x,y} em um path SVG suavizado (Catmull-Rom
    // convertido para Bezier cubico). Mesma curva alimenta tanto a linha quanto
    // a area abaixo dela, para as duas ficarem sempre alinhadas.
    function dashboard_smooth_line_path(array $points): string
    {
        $points = array_values($points);
        $count = count($points);
        if ($count === 0) {
            return '';
        }
        if ($count === 1) {
            return 'M ' . dashboard_fmt($points[0]['x']) . ',' . dashboard_fmt($points[0]['y']);
        }

        $d = 'M ' . dashboard_fmt($points[0]['x']) . ',' . dashboard_fmt($points[0]['y']);
        for ($i = 0; $i < $count - 1; $i++) {
            $p0 = $points[$i - 1] ?? $points[$i];
            $p1 = $points[$i];
            $p2 = $points[$i + 1];
            $p3 = $points[$i + 2] ?? $p2;

            $cp1x = $p1['x'] + ($p2['x'] - $p0['x']) / 6;
            $cp1y = $p1['y'] + ($p2['y'] - $p0['y']) / 6;
            $cp2x = $p2['x'] - ($p3['x'] - $p1['x']) / 6;
            $cp2y = $p2['y'] - ($p3['y'] - $p1['y']) / 6;

            $d .= ' C ' . dashboard_fmt($cp1x) . ',' . dashboard_fmt($cp1y)
                . ' ' . dashboard_fmt($cp2x) . ',' . dashboard_fmt($cp2y)
                . ' ' . dashboard_fmt($p2['x']) . ',' . dashboard_fmt($p2['y']);
        }
        return $d;
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
            $points[] = ['x' => $x, 'y' => $y];
        }

        $linePath = dashboard_smooth_line_path($points);
        $areaPath = $linePath
            . ' L ' . dashboard_fmt($width - $padding) . ',' . dashboard_fmt($height - $padding)
            . ' L ' . dashboard_fmt($padding) . ',' . dashboard_fmt($height - $padding)
            . ' Z';

        return '<svg viewBox="0 0 ' . $width . ' ' . $height . '" class="dashboard-sparkline" preserveAspectRatio="none" role="img" aria-label="' . Security::e($label) . '">'
            . '<title>' . Security::e($label) . '</title>'
            . '<path d="' . $areaPath . '" fill="' . $fill . '" opacity="0.22"></path>'
            . '<path d="' . $linePath . '" fill="none" stroke="' . $stroke . '" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" class="dashboard-chart-draw" style="stroke-dasharray:500;stroke-dashoffset:500;"></path>'
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

        $linePath = dashboard_smooth_line_path($points);
        $areaPath = $linePath
            . ' L ' . dashboard_fmt($width - $right) . ',' . dashboard_fmt($height - $bottom)
            . ' L ' . dashboard_fmt($left) . ',' . dashboard_fmt($height - $bottom)
            . ' Z';

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

        // Destaca so o ultimo ponto, o maior e o menor valor da serie; os
        // demais ficam com uma area de toque/foco invisivel (acessibilidade
        // preservada) que revela um marcador discreto no hover/foco.
        $lastIndex = count($points) - 1;
        $seriesValues = array_column($points, 'value');
        $maxIndex = array_search(max($seriesValues), $seriesValues, true);
        $minIndex = array_search(min($seriesValues), $seriesValues, true);
        $highlightIndexes = array_unique([$lastIndex, $maxIndex, $minIndex]);

        $markers = '';
        foreach ($points as $index => $point) {
            $isHighlighted = in_array($index, $highlightIndexes, true);
            $dotClass = $isHighlighted ? 'dashboard-chart-point-dot dashboard-chart-point-dot--highlight' : 'dashboard-chart-point-dot';
            $markers .= '<g class="dashboard-chart-point" tabindex="0">'
                . '<circle cx="' . $point['x'] . '" cy="' . $point['y'] . '" r="10" fill="transparent"></circle>'
                . '<circle cx="' . $point['x'] . '" cy="' . $point['y'] . '" r="4" fill="#ffffff" stroke="' . $stroke . '" stroke-width="2" class="' . $dotClass . '"></circle>'
                . '<title>' . Security::e($point['label']) . ': ' . number_format($point['value'], 1, ',', '.') . $suffix . '</title>'
                . '</g>';
        }

        return '<div class="dashboard-chart-scroll"><svg viewBox="0 0 ' . $width . ' ' . $height . '" class="dashboard-line-chart" preserveAspectRatio="none" role="img" aria-label="Gráfico de linha">'
            . $grid
            . $axis
            . '<path d="' . $areaPath . '" fill="' . $fill . '" opacity="0.14"></path>'
            . '<path d="' . $linePath . '" fill="none" stroke="' . $stroke . '" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="dashboard-chart-draw" style="stroke-dasharray:2000;stroke-dashoffset:2000;"></path>'
            . $markers
            . $xLabels
            . '</svg></div>';
    }
}

if (!function_exists('dashboard_donut')) {
    function dashboard_donut(array $segments, int $size = 148, int $thickness = 20): string
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
        $segmentCount = count($segments);
        // Ponta reta (nao arredondada) + um pequeno vao fixo entre segmentos:
        // com a paleta tonal atual, pontas arredondadas encostadas ficavam
        // dificeis de distinguir entre si.
        $gap = $segmentCount > 1 ? min(6.0, $circumference / ($segmentCount * 4)) : 0.0;
        $offset = 0.0;
        $svg = '<svg viewBox="0 0 ' . $size . ' ' . $size . '" class="dashboard-donut dashboard-chart-fade -rotate-90" role="img" aria-label="Gráfico de rosca">';
        $svg .= '<circle cx="' . ($size / 2) . '" cy="' . ($size / 2) . '" r="' . $radius . '" fill="none" stroke="#e9edf2" stroke-width="' . $thickness . '"></circle>';
        foreach ($segments as $segment) {
            $value = (float)$segment['value'];
            $rawLength = ($value / $total) * $circumference;
            $length = max(0.0, $rawLength - $gap);
            $svg .= '<circle cx="' . ($size / 2) . '" cy="' . ($size / 2) . '" r="' . $radius . '" fill="none" stroke="' . $segment['color'] . '" stroke-width="' . $thickness . '" stroke-linecap="butt" stroke-dasharray="' . dashboard_fmt($length) . ' ' . dashboard_fmt($circumference - $length) . '" stroke-dashoffset="' . dashboard_fmt(-$offset) . '" tabindex="0">'
                . '<title>' . Security::e($segment['label']) . ': ' . number_format($value, 1, ',', '.') . '%</title>'
                . '</circle>';
            $offset += $rawLength;
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
  .dashboard-icon-badge {
    background: #e9edf2;
    color: #1d2d44;
  }
  .dashboard-stat-box {
    background: #e9edf2;
    color: #1d2d44;
  }
  .dashboard-bar-track {
    background: #eef1f4;
    max-width: 44px;
  }
  .dashboard-legend-swatch {
    border-radius: 4px;
    box-shadow: 0 0 0 1px rgba(15, 23, 42, 0.06);
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
    position: relative;
    overflow-x: auto;
    overflow-y: hidden;
    touch-action: pan-x pan-y pinch-zoom;
  }
  @media (max-width: 640px) {
    .dashboard-chart-scroll::after {
      content: '';
      position: absolute;
      top: 0;
      right: 0;
      bottom: 0;
      width: 28px;
      background: linear-gradient(to right, rgba(255, 255, 255, 0), rgba(255, 255, 255, 0.94));
      pointer-events: none;
    }
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
    min-width: 480px;
    height: 188px;
  }
  .dashboard-sparkline {
    width: 100%;
    height: 26px;
  }
  .dashboard-donut {
    margin: 0 auto;
    width: 148px;
    height: 148px;
  }
  .dashboard-chart-point-dot {
    opacity: 0;
    transition: opacity 0.15s ease;
  }
  .dashboard-chart-point:hover .dashboard-chart-point-dot,
  .dashboard-chart-point:focus .dashboard-chart-point-dot,
  .dashboard-chart-point:focus-within .dashboard-chart-point-dot {
    opacity: 1;
  }
  .dashboard-chart-point-dot--highlight {
    opacity: 1;
  }
  @keyframes dashboard-draw-line {
    to { stroke-dashoffset: 0; }
  }
  .dashboard-chart-draw {
    animation: dashboard-draw-line 1.1s ease-out forwards;
  }
  @keyframes dashboard-fade-scale {
    from { opacity: 0; transform: rotate(-90deg) scale(0.92); }
    to { opacity: 1; transform: rotate(-90deg) scale(1); }
  }
  .dashboard-chart-fade {
    animation: dashboard-fade-scale 0.5s ease-out both;
  }
  @keyframes dashboard-panel-fade {
    from { opacity: 0; }
    to { opacity: 1; }
  }
  .dashboard-panel,
  .dashboard-panel-compact {
    animation: dashboard-panel-fade 0.45s ease-out;
  }
  @keyframes dashboard-grow-x {
    from { transform: scaleX(0); }
    to { transform: scaleX(1); }
  }
  .dashboard-bar-grow-x {
    transform-origin: left;
    animation: dashboard-grow-x 0.6s cubic-bezier(0.22, 0.61, 0.36, 1) both;
  }
  @keyframes dashboard-grow-y {
    from { transform: scaleY(0); }
    to { transform: scaleY(1); }
  }
  .dashboard-bar-grow-y {
    transform-origin: bottom;
    animation: dashboard-grow-y 0.6s cubic-bezier(0.22, 0.61, 0.36, 1) both;
  }
  @media (prefers-reduced-motion: reduce) {
    .dashboard-chart-draw,
    .dashboard-chart-fade,
    .dashboard-panel,
    .dashboard-panel-compact,
    .dashboard-bar-grow-x,
    .dashboard-bar-grow-y {
      animation: none !important;
    }
    .dashboard-chart-draw {
      stroke-dashoffset: 0 !important;
    }
    .dashboard-bar-grow-x,
    .dashboard-bar-grow-y {
      transform: none !important;
    }
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
        <a href="<?= $base ?>/admin/colaboradores" class="ct-btn ct-btn-muted">
          Colaboradores
        </a>
        <button type="button" onclick="window.print()" title="Exportar o dashboard em PDF pela impressão do navegador" class="ct-btn ct-btn-primary">
          Exportar
        </button>
      </div>
    </div>
  </section>

  <section class="dashboard-kpi-grid">
    <?php foreach ($dashboard['kpis'] as $kpiIndex => $kpi): ?>
      <?php
      $scheme = $palette[$kpi['color']] ?? $palette['blue'];
      $href = $kpi['href'] ?? null;
      $tag = $href ? 'a' : 'div';
      ?>
      <<?= $tag ?><?= $href ? ' href="' . $base . $href . '"' : '' ?> class="dashboard-panel-compact block ring-1 ring-slate-200 transition hover:-translate-y-0.5 hover:shadow-md" style="animation-delay: <?= $kpiIndex * 40 ?>ms">
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
        <div class="mt-2">
          <span class="text-[11px] font-semibold text-emerald-600"><?= Security::e($kpi['change']) ?></span>
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
        <span class="dashboard-icon-badge rounded-full px-3 py-1 text-xs font-semibold"><?= Security::e($dashboard['turnoverSeries']['highlight']) ?></span>
      </div>
      <?= dashboard_line_chart($dashboard['turnoverSeries']['labels'], $dashboard['turnoverSeries']['values'], '#1d2d44', '#c9d2db') ?>
    </article>

    <article class="dashboard-panel dashboard-span-3 ring-1 ring-slate-200">
      <h2 class="dashboard-title">Turnover por Gênero (12m)</h2>
      <p class="dashboard-subtitle">Comparativo percentual por público</p>
      <?php
      // Duas categorias com valores proximos: barras horizontais comunicam a
      // diferenca (comprimento/posicao) com muito mais precisao do que um
      // donut (angulo/area) - ver decisao registrada na conversa da Sprint 002.
      $maxGenero = max(array_column($dashboard['turnoverGenero'], 'value'));
      ?>
      <div class="mt-5 space-y-4">
        <?php foreach ($dashboard['turnoverGenero'] as $item): ?>
          <div title="<?= Security::e($item['label']) ?>: <?= number_format($item['value'], 1, ',', '.') ?>%">
            <div class="mb-1.5 flex items-center justify-between">
              <span class="flex items-center gap-2 text-sm text-slate-600">
                <span class="h-2.5 w-2.5 rounded-full" style="background-color: <?= $item['color'] ?>"></span>
                <?= Security::e($item['label']) ?>
              </span>
              <span class="text-lg font-bold text-slate-800"><?= number_format($item['value'], 1, ',', '.') ?>%</span>
            </div>
            <div class="h-3 rounded-full bg-slate-100">
              <div class="dashboard-bar-grow-x h-3 rounded-full" style="width: <?= ($item['value'] / $maxGenero) * 100 ?>%; background-color: <?= $item['color'] ?>;"></div>
            </div>
          </div>
        <?php endforeach; ?>
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
              <div class="dashboard-bar-grow-x h-2.5 rounded-full bg-ctgreen" style="width: <?= ($item['value'] / $maxFaixa) * 100 ?>%"></div>
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
        <div class="dashboard-stat-box rounded-2xl px-3 py-2 text-center">
          <p class="text-3xl font-bold leading-none"><?= (int)$dashboard['tempoFechamento']['value'] ?></p>
          <p class="mt-1 text-[11px] font-semibold uppercase tracking-wide">dias</p>
        </div>
      </div>
      <div class="mt-4 flex items-end gap-2.5">
        <?php $maxTempo = max(array_column($dashboard['tempoFechamento']['bars'], 'value')); ?>
        <?php foreach ($dashboard['tempoFechamento']['bars'] as $bar): ?>
          <div class="flex-1 text-center" title="<?= Security::e($bar['label']) ?>: <?= (int)$bar['value'] ?> dias">
            <div class="mx-auto flex h-24 items-end justify-center rounded-t-lg dashboard-bar-track px-1.5">
              <div class="dashboard-bar-grow-y w-full rounded-t-md bg-ctgreen" style="height: <?= ($bar['value'] / $maxTempo) * 100 ?>%"></div>
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
        <div class="dashboard-stat-box rounded-2xl px-3 py-2 text-center">
          <p class="text-2xl font-bold leading-none"><?= Security::e($dashboard['custoContratacao']['value']) ?></p>
        </div>
      </div>
      <div class="mt-4 flex items-end gap-2.5">
        <?php $maxCost = max(array_column($dashboard['custoContratacao']['bars'], 'value')); ?>
        <?php foreach ($dashboard['custoContratacao']['bars'] as $bar): ?>
          <div class="flex-1 text-center" title="<?= Security::e($bar['label']) ?>: R$ <?= number_format((float)$bar['value'], 0, ',', '.') ?>">
            <div class="mx-auto flex h-24 items-end justify-center rounded-t-lg dashboard-bar-track px-1.5">
              <div class="dashboard-bar-grow-y w-full rounded-t-md bg-ctlight" style="height: <?= ($bar['value'] / $maxCost) * 100 ?>%"></div>
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
          <p class="dashboard-subtitle">Distribuição atual por área derivada da base de colaboradores</p>
        </div>
        <a href="<?= $base ?>/admin/colaboradores" class="text-xs font-semibold text-ctgreen hover:text-ctdark">Ver base</a>
      </div>
      <div class="flex flex-col items-center gap-4 sm:flex-row sm:items-center xl:flex-row">
        <?= dashboard_donut($dashboard['colaboradoresArea']) ?>
        <div class="w-full space-y-2.5">
          <?php foreach ($dashboard['colaboradoresArea'] as $item): ?>
            <div class="flex items-center justify-between gap-3 text-[13px]" title="<?= Security::e($item['label']) ?>: <?= (int)$item['value'] ?> colaboradores">
              <div class="flex items-center gap-2">
                <span class="dashboard-legend-swatch h-3 w-3 shrink-0" style="background-color: <?= $item['color'] ?>"></span>
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
      <p class="dashboard-subtitle">Faixas de permanência calculadas a partir da admissão registrada</p>
      <?php $maxTempoEmpresa = max(array_column($dashboard['tempoEmpresa'], 'value')); ?>
      <div class="mt-4 space-y-3.5">
        <?php foreach ($dashboard['tempoEmpresa'] as $item): ?>
          <div title="<?= Security::e($item['label']) ?>: <?= (int)$item['value'] ?>%">
            <div class="mb-1 flex items-center justify-between text-[13px]">
              <span class="text-slate-600"><?= Security::e($item['label']) ?></span>
              <span class="font-semibold text-slate-800"><?= (int)$item['value'] ?>%</span>
            </div>
            <div class="h-2.5 rounded-full bg-slate-100">
              <div class="dashboard-bar-grow-x h-2.5 rounded-full bg-ctlight" style="width: <?= ($item['value'] / $maxTempoEmpresa) * 100 ?>%"></div>
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
</div>
