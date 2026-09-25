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


if (!function_exists('dashboard_vertical_bars')) {
    // Barras verticais para comparar N categorias (ex.: Headcount/Turnover por Empresa) — mesmo
    // padrão visual/sem biblioteca externa de dashboard_bar_row, só em orientação vertical.
    // $items: lista de ['label' => string, 'value' => float, 'display' => string]. $color é uma
    // classe Tailwind estática (nunca gerada por concatenação de valor dinâmico, para o build do
    // Tailwind conseguir detectar a classe em tempo de compilação).
    function dashboard_vertical_bars(array $items, string $color, string $trackColor = 'bg-slate-100'): string
    {
        if ($items === []) {
            return '';
        }
        $max = max(array_column($items, 'value')) ?: 1;
        $bars = '';
        foreach ($items as $item) {
            $height = $max > 0 ? min(100, (max(0.0, (float)$item['value']) / $max) * 100) : 0;
            $bars .= '<div class="flex w-20 flex-shrink-0 flex-col items-center gap-1.5" title="' . Security::e($item['label']) . ': ' . Security::e($item['display']) . '">'
                . '<span class="text-xs font-semibold text-slate-700">' . Security::e($item['display']) . '</span>'
                . '<div class="flex h-28 w-12 items-end rounded-md ' . $trackColor . '">'
                . '<div class="dashboard-bar-grow-y w-full rounded-md ' . $color . '" style="height: ' . dashboard_fmt($height) . '%"></div>'
                . '</div>'
                . '<span class="line-clamp-2 min-h-[2.1em] w-full text-center text-[10px] font-medium leading-tight text-slate-500">' . Security::e($item['label']) . '</span>'
                . '</div>';
        }
        return '<div class="flex items-end justify-center gap-3 overflow-x-auto pb-1">' . $bars . '</div>';
    }
}

if (!function_exists('dashboard_nice_max')) {
    // Teto "redondo" (1/2/5 x 10^n) para o eixo Y de gráficos com valores >= 0.
    function dashboard_nice_max(float $max): float
    {
        if ($max <= 0) {
            return 1.0;
        }
        $base = 10 ** floor(log10($max));
        $fracao = $max / $base;
        $nice = $fracao <= 1 ? 1 : ($fracao <= 2 ? 2 : ($fracao <= 5 ? 5 : 10));
        return $nice * $base;
    }
}

if (!function_exists('dashboard_chart_legend')) {
    // Legenda de séries. $items: ['label' => string, 'color' => '#hex', 'estilo' => 'solid'|'dashed'|'hollow'].
    function dashboard_chart_legend(array $items): string
    {
        $html = '<ul class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-[#5B5F4E]">';
        foreach ($items as $item) {
            $cor = Security::e((string)$item['color']);
            $estilo = $item['estilo'] ?? 'solid';
            if ($estilo === 'dashed') {
                $marca = 'border-2 border-dashed bg-white" style="border-color:' . $cor;
            } elseif ($estilo === 'hollow') {
                $marca = 'border-2 bg-white" style="border-color:' . $cor;
            } else {
                $marca = '" style="background:' . $cor;
            }
            $html .= '<li class="flex items-center gap-1.5"><span class="inline-block h-2.5 w-2.5 flex-shrink-0 rounded-full ' . $marca . '"></span>' . Security::e((string)$item['label']) . '</li>';
        }
        return $html . '</ul>';
    }
}

if (!function_exists('dashboard_data_table')) {
    // Os mesmos valores do gráfico em tabela acessível (não depende de hover nem só do SVG).
    // $rows: lista de linhas, cada uma uma lista de strings já formatadas (a 1ª coluna é o cabeçalho da linha).
    function dashboard_data_table(string $caption, array $headers, array $rows): string
    {
        $html = '<details class="mt-3"><summary class="cursor-pointer text-xs font-semibold text-[#3B4822]">Ver valores em tabela</summary>'
            . '<div class="mt-2 overflow-x-auto"><table class="min-w-full text-xs"><caption class="sr-only">' . Security::e($caption) . '</caption><thead><tr class="border-b text-left text-[#5B5F4E]">';
        foreach ($headers as $header) {
            $html .= '<th scope="col" class="px-2 py-1.5 font-semibold">' . Security::e((string)$header) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr class="border-b">';
            foreach (array_values($row) as $i => $celula) {
                $html .= $i === 0
                    ? '<th scope="row" class="px-2 py-1.5 text-left font-medium text-[#2B2E22]">' . Security::e((string)$celula) . '</th>'
                    : '<td class="px-2 py-1.5 text-[#2B2E22]">' . Security::e((string)$celula) . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table></div></details>';
    }
}

if (!function_exists('dashboard_multi_line_chart')) {
    // Linhas com várias séries sobre as mesmas categorias (ex.: 12 meses). SVG puro, sem biblioteca.
    // $series: [['label','color','values' => array<?float> (null = sem ponto, quebra a linha),
    //            'partial' => array<bool> (ponto parcial: marcador vazado + trecho tracejado)]].
    // Rótulo de valor em todo ponto (não depende de hover). Eixo Y sempre a partir de 0.
    function dashboard_multi_line_chart(array $labels, array $series, string $suffix = '%', int $decimals = 1, string $ariaLabel = 'Gráfico de linhas', array $opcoes = []): string
    {
        $largura = 720.0;
        $altura = 280.0;
        $esq = 44.0;
        $dir = 16.0;
        $topo = 30.0;
        $base = 34.0;
        $n = max(1, count($labels));
        $plotW = $largura - $esq - $dir;
        $plotH = $altura - $topo - $base;

        $maximo = 0.0;
        foreach ($series as $s) {
            foreach ($s['values'] as $v) {
                if ($v !== null) {
                    $maximo = max($maximo, (float)$v);
                }
            }
        }
        // Escala padrão: de 0 ao "teto bonito" dos dados. `$opcoes['min']`/`['max']` fixam a escala (ex.: eNPS de
        // -100 a 100, nota de 1 a 5); sem opções o comportamento é o original.
        $minimo = isset($opcoes['min']) ? (float)$opcoes['min'] : 0.0;
        $teto = isset($opcoes['max']) ? (float)$opcoes['max'] : dashboard_nice_max($maximo);
        $faixa = max(0.0001, $teto - $minimo);
        $escalaFixa = isset($opcoes['min']) || isset($opcoes['max']);
        $mostrarValores = ($opcoes['valores'] ?? true) !== false;
        $xDe = static fn(int $i): float => $esq + ($i + 0.5) * ($plotW / $n);
        $yDe = static fn(float $v): float => $topo + $plotH - (($v - $minimo) / $faixa) * $plotH;
        $fmt = static fn(float $v): string => number_format($v, $decimals, ',', '.') . $suffix;

        $svg = '<svg viewBox="0 0 ' . $largura . ' ' . $altura . '" class="h-auto w-full" role="img" aria-label="' . Security::e($ariaLabel) . '">';
        for ($t = 0; $t <= 4; $t++) {
            $valorTick = $minimo + $faixa / 4 * $t;
            $y = $yDe($valorTick);
            $svg .= '<line x1="' . $esq . '" y1="' . dashboard_fmt($y) . '" x2="' . ($largura - $dir) . '" y2="' . dashboard_fmt($y) . '" stroke="#E2DFD0" stroke-dasharray="3 5"></line>'
                . '<text x="' . ($esq - 6) . '" y="' . dashboard_fmt($y + 3) . '" text-anchor="end" font-size="10" fill="#5B5F4E">' . Security::e(number_format($valorTick, $escalaFixa ? (fmod($faixa / 4, 1.0) !== 0.0 ? 1 : 0) : ($teto < 10 ? 1 : 0), ',', '.') . $suffix) . '</text>';
        }
        foreach ($labels as $i => $rotulo) {
            $svg .= '<text x="' . dashboard_fmt($xDe((int)$i)) . '" y="' . ($altura - 12) . '" text-anchor="middle" font-size="11" fill="#5B5F4E">' . Security::e((string)$rotulo) . '</text>';
        }

        foreach ($series as $indiceSerie => $s) {
            $cor = Security::e((string)$s['color']);
            $parciais = $s['partial'] ?? [];
            $segmentos = [];
            $atual = [];
            foreach ($labels as $i => $rotulo) {
                $v = $s['values'][$i] ?? null;
                if ($v === null) {
                    if ($atual !== []) {
                        $segmentos[] = $atual;
                        $atual = [];
                    }
                    continue;
                }
                $atual[] = ['x' => $xDe((int)$i), 'y' => $yDe((float)$v), 'v' => (float)$v, 'i' => (int)$i, 'parcial' => !empty($parciais[$i]), 'rotulo' => (string)$rotulo];
            }
            if ($atual !== []) {
                $segmentos[] = $atual;
            }

            foreach ($segmentos as $pontos) {
                $solidos = $pontos;
                $ultimo = end($pontos);
                if (count($pontos) >= 2 && $ultimo['parcial']) {
                    array_pop($solidos);
                    $anterior = end($solidos);
                    $svg .= '<line x1="' . dashboard_fmt($anterior['x']) . '" y1="' . dashboard_fmt($anterior['y']) . '" x2="' . dashboard_fmt($ultimo['x']) . '" y2="' . dashboard_fmt($ultimo['y']) . '" stroke="' . $cor . '" stroke-width="2.5" stroke-dasharray="4 4" stroke-linecap="round"></line>';
                }
                if (count($solidos) >= 2) {
                    $svg .= '<path d="' . dashboard_smooth_line_path($solidos) . '" fill="none" stroke="' . $cor . '" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"></path>';
                }
            }
            foreach ($segmentos as $pontos) {
                foreach ($pontos as $p) {
                    // Duas séries: o maior valor do mês fica com o rótulo acima, o menor abaixo (sem sobrepor).
                    $abaixo = false;
                    if (count($series) === 2) {
                        $outro = $series[1 - $indiceSerie]['values'][$p['i']] ?? null;
                        if ($outro !== null) {
                            $abaixo = $p['v'] < (float)$outro || ($p['v'] === (float)$outro && $indiceSerie === 1);
                        }
                    }
                    $svg .= '<g><title>' . Security::e($s['label'] . ' — ' . $p['rotulo'] . ': ' . $fmt($p['v']) . ($p['parcial'] ? ' (parcial)' : '')) . '</title>'
                        . '<circle cx="' . dashboard_fmt($p['x']) . '" cy="' . dashboard_fmt($p['y']) . '" r="3.5" fill="' . ($p['parcial'] ? '#ffffff' : $cor) . '" stroke="' . $cor . '" stroke-width="2"></circle>'
                        . ($mostrarValores ? '<text x="' . dashboard_fmt($p['x']) . '" y="' . dashboard_fmt($p['y'] + ($abaixo ? 16 : -8)) . '" text-anchor="middle" font-size="10" font-weight="600" fill="#2B2E22">' . Security::e($fmt($p['v'])) . '</text>' : '') . '</g>';
                }
            }
        }
        return '<div class="overflow-x-auto"><div class="min-w-[560px]">' . $svg . '</svg></div></div>';
    }
}

if (!function_exists('dashboard_grouped_columns')) {
    // Colunas agrupadas (uma coluna por série em cada categoria). $series: [['label','color',
    // 'values' => array<?int> (null = sem coluna e sem rótulo), 'partial' => array<bool>]]. Coluna
    // parcial: preenchimento translúcido + contorno tracejado. Rótulo de valor em toda coluna.
    function dashboard_grouped_columns(array $labels, array $series, string $ariaLabel = 'Gráfico de colunas', array $opcoes = []): string
    {
        $mostrarValores = ($opcoes['valores'] ?? true) !== false;
        // Rótulos do eixo X inclinados: opt-in (nunca muda o comportamento de quem já chama esta
        // função) — usado quando as categorias são texto longo (ex.: nomes de Setor) em vez de
        // rótulos curtos (ex.: meses), que ficariam sobrepostos na horizontal.
        $rotacionarEixoX = ($opcoes['rotacionar_eixo_x'] ?? false) === true;
        $largura = 720.0;
        // Altura opt-in (default 280, igual a antes) — usado para caber num card mais compacto
        // (ex.: metade da largura, ao lado de outro gráfico) sem exigir tanta rolagem vertical.
        $altura = (float)($opcoes['altura'] ?? 280.0);
        // Rotacionado, o texto do 1º grupo se estende para a ESQUERDA/CIMA do seu ponto de
        // ancoragem (text-anchor="end") — margens maiores (e rótulo bem curto, ver abaixo) evitam
        // cortar esse rótulo na borda do viewBox (o SVG raiz recorta conteúdo fora do viewBox por
        // padrão do navegador).
        $esq = $rotacionarEixoX ? 90.0 : 40.0;
        $dir = 12.0;
        $topo = 26.0;
        $base = $rotacionarEixoX ? 80.0 : 34.0;
        $n = max(1, count($labels));
        $k = max(1, count($series));
        $plotW = $largura - $esq - $dir;
        $plotH = $altura - $topo - $base;
        $grupoW = $plotW / $n;
        $barraW = min(18.0, $grupoW * 0.38);

        $maximo = 0.0;
        foreach ($series as $s) {
            foreach ($s['values'] as $v) {
                if ($v !== null) {
                    $maximo = max($maximo, (float)$v);
                }
            }
        }
        $teto = max(1.0, dashboard_nice_max($maximo));

        $svg = '<svg viewBox="0 0 ' . $largura . ' ' . $altura . '" class="h-auto w-full" role="img" aria-label="' . Security::e($ariaLabel) . '">';
        for ($t = 0; $t <= 4; $t++) {
            $valorTick = $teto / 4 * $t;
            $y = $topo + $plotH - ($valorTick / $teto) * $plotH;
            $svg .= '<line x1="' . $esq . '" y1="' . dashboard_fmt($y) . '" x2="' . ($largura - $dir) . '" y2="' . dashboard_fmt($y) . '" stroke="#E2DFD0" stroke-dasharray="3 5"></line>'
                . '<text x="' . ($esq - 6) . '" y="' . dashboard_fmt($y + 3) . '" text-anchor="end" font-size="10" fill="#5B5F4E">' . Security::e(number_format($valorTick, $teto < 4 ? 1 : 0, ',', '.')) . '</text>';
        }
        foreach ($labels as $i => $rotulo) {
            $centro = $esq + ($i + 0.5) * $grupoW;
            if ($rotacionarEixoX) {
                $yEixo = $altura - 14;
                // Trunca só o texto DESENHADO (o nome completo continua no <title> de cada barra,
                // no foreach abaixo) — mantém o alcance horizontal/vertical do texto rotacionado
                // sempre dentro da margem reservada, mesmo para categorias com nomes muito longos.
                $textoEixo = mb_strlen((string)$rotulo) > 11 ? mb_substr((string)$rotulo, 0, 10) . '…' : (string)$rotulo;
                // rotate(+45), não -45: com text-anchor="end", um ângulo negativo empurra o INÍCIO
                // do texto para BAIXO (para fora do viewBox, y crescente) — o positivo é que sobe
                // o texto para cima-esquerda do ponto de ancoragem, o padrão visual esperado.
                $svg .= '<text x="' . dashboard_fmt($centro) . '" y="' . dashboard_fmt($yEixo) . '" text-anchor="end" font-size="10" fill="#5B5F4E" transform="rotate(45 ' . dashboard_fmt($centro) . ' ' . dashboard_fmt($yEixo) . ')">' . Security::e($textoEixo) . '</text>';
            } else {
                $svg .= '<text x="' . dashboard_fmt($centro) . '" y="' . ($altura - 12) . '" text-anchor="middle" font-size="11" fill="#5B5F4E">' . Security::e((string)$rotulo) . '</text>';
            }
            foreach ($series as $indice => $s) {
                $v = $s['values'][$i] ?? null;
                if ($v === null) {
                    continue;
                }
                $v = (float)$v;
                $x = $centro + ($indice - ($k - 1) / 2) * ($barraW + 3) - $barraW / 2;
                $h = ($v / $teto) * $plotH;
                $y = $topo + $plotH - $h;
                $parcial = !empty(($s['partial'] ?? [])[$i]);
                $cor = Security::e((string)$s['color']);
                $svg .= '<g><title>' . Security::e($s['label'] . ' — ' . $rotulo . ': ' . number_format($v, 0, ',', '.') . ($parcial ? ' (parcial)' : '')) . '</title>';
                if ($h > 0) {
                    $svg .= '<rect x="' . dashboard_fmt($x) . '" y="' . dashboard_fmt($y) . '" width="' . dashboard_fmt($barraW) . '" height="' . dashboard_fmt($h) . '" rx="2" fill="' . $cor . '"'
                        . ($parcial ? ' fill-opacity="0.45" stroke="' . $cor . '" stroke-width="1.5" stroke-dasharray="3 2"' : '') . '></rect>';
                }
                if ($mostrarValores) {
                    $svg .= '<text x="' . dashboard_fmt($x + $barraW / 2) . '" y="' . dashboard_fmt($y - 4) . '" text-anchor="middle" font-size="9" font-weight="600" fill="#2B2E22">' . Security::e(number_format($v, 0, ',', '.')) . '</text>';
                }
                $svg .= '</g>';
            }
        }
        return '<div class="overflow-x-auto"><div class="min-w-[560px]">' . $svg . '</svg></div></div>';
    }
}
}
