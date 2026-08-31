<?php
// Helpers de gráfico compartilhados entre telas administrativas (SVG puro, sem biblioteca de
// charting — consistente com a stack vanilla JS/sem bundler do projeto). Extraído de
// admin/dashboard.php na Fase 4 para ser reutilizado por admin/indicadores-rh.php sem duplicar a
// matemática de curva/donut. Guardado por function_exists() porque pode ser incluído mais de uma
// vez se duas views o requisitarem na mesma requisição.

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
            'exit' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 17l5-5-5-5M21 12H9"/>',
            'clock' => '<circle cx="12" cy="12" r="9" stroke-width="1.8"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 7v5l3 3"/>',
            'alert' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a1 1 0 0 0 .86 1.5h18.64a1 1 0 0 0 .86-1.5L13.71 3.86a1 1 0 0 0-1.72 0Z"/>',
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

if (!function_exists('dashboard_bar_row')) {
    // Barra horizontal com rótulo + valor — mesmo padrão visual já usado inline em
    // admin/dashboard.php para comparações de poucas categorias, agora reutilizável.
    function dashboard_bar_row(string $label, float $value, float $max, string $displayValue, string $color = 'bg-ctlight'): string
    {
        $width = $max > 0 ? min(100, ($value / $max) * 100) : 0;
        return '<div title="' . Security::e($label) . ': ' . Security::e($displayValue) . '">'
            . '<div class="mb-1 flex items-center justify-between text-[13px]">'
            . '<span class="text-slate-600">' . Security::e($label) . '</span>'
            . '<span class="font-semibold text-slate-800">' . Security::e($displayValue) . '</span>'
            . '</div>'
            . '<div class="h-2.5 rounded-full bg-slate-100">'
            . '<div class="dashboard-bar-grow-x h-2.5 rounded-full ' . $color . '" style="width: ' . dashboard_fmt($width) . '%"></div>'
            . '</div></div>';
    }
}
