<?php

class CollaboratorDashboardDataService
{
    private const TOTAL_DASHBOARD_SLOTS = 15;
    private const MONTH_LABELS = [
        1 => 'Jan',
        2 => 'Fev',
        3 => 'Mar',
        4 => 'Abr',
        5 => 'Mai',
        6 => 'Jun',
        7 => 'Jul',
        8 => 'Ago',
        9 => 'Set',
        10 => 'Out',
        11 => 'Nov',
        12 => 'Dez',
    ];

    private const AREA_KEYWORDS = [
        'operacoes' => ['oper', 'produ', 'manut', 'campo', 'logist', 'fabr', 'industrial'],
        'administrativo' => ['admin', 'finance', 'fiscal', 'contab', 'compras', 'jurid', 'control', 'diret'],
        'tecnologia' => ['ti', 'tecn', 'sistema', 'infra', 'dados', 'desenvolv', 'software'],
        'rh' => ['rh', 'dp', 'sst', 'seguran', 'gente', 'pessoas'],
    ];

    public function build(array $fallback, string $selectedPeriod, string $selectedArea): array
    {
        if (!class_exists('Colaborador') || Colaborador::countAll() === 0) {
            return $this->withSourceSummary($fallback, 0, 0, ['Nenhum colaborador encontrado para alimentar métricas reais.']);
        }

        $rows = $this->loadCollaborators($selectedArea);
        if ($rows === []) {
            return $this->withSourceSummary($fallback, 0, 0, ['O filtro de área selecionado não retornou colaboradores.']);
        }

        $periodEnd = $this->resolvePeriodEnd($selectedPeriod);
        $months = $this->buildMonthWindow($periodEnd, 12);
        $today = new DateTimeImmutable('today');
        $currentActiveRows = array_values(array_filter($rows, fn(array $row): bool => $this->isActiveOnDate($row, $today)));

        $realMetrics = 0;
        $notes = [];
        $dataset = $fallback;

        $headcountSeries = $this->buildHeadcountSeries($rows, $months);
        if ($headcountSeries['current'] > 0) {
            $dataset['kpis'][0]['value'] = number_format($headcountSeries['current'], 0, ',', '.');
            $dataset['kpis'][0]['suffix'] = 'colaboradores ativos';
            $dataset['kpis'][0]['change'] = $headcountSeries['change_label'];
            $dataset['kpis'][0]['trend'] = $headcountSeries['trend'];
            $dataset['kpis'][0]['is_real'] = true;
            $realMetrics++;
        }

        $turnoverSeries = $this->buildTurnoverSeries($rows, $months);
        if ($turnoverSeries['has_data']) {
            $dataset['kpis'][1]['value'] = $this->formatPercent($turnoverSeries['latest']);
            $dataset['kpis'][1]['change'] = $turnoverSeries['change_label'];
            $dataset['kpis'][1]['trend'] = $turnoverSeries['trend'];
            $dataset['kpis'][1]['is_real'] = true;

            $dataset['turnoverSeries']['labels'] = $turnoverSeries['labels'];
            $dataset['turnoverSeries']['values'] = $turnoverSeries['values'];
            $dataset['turnoverSeries']['highlight'] = $this->formatPercent($turnoverSeries['latest']);
            $realMetrics += 2;
        } else {
            $notes[] = 'Turnover permaneceu com fallback porque não há demissões suficientes no recorte analisado.';
        }

        $salarySeries = $this->buildAverageSalarySeries($rows, $months);
        if ($salarySeries['has_data']) {
            $dataset['kpis'][5]['value'] = 'R$ ' . $this->formatNumber($salarySeries['latest'], 0);
            $dataset['kpis'][5]['change'] = $salarySeries['change_label'];
            $dataset['kpis'][5]['trend'] = $salarySeries['trend'];
            $dataset['kpis'][5]['is_real'] = true;

            $dataset['custoContratacao']['value'] = 'R$ ' . $this->formatNumber($salarySeries['latest'], 0);
            $dataset['custoContratacao']['meta'] = 'média salarial da base ativa';
            $dataset['custoContratacao']['change'] = $salarySeries['change_label'];
            $dataset['custoContratacao']['bars'] = $salarySeries['bars'];
            $realMetrics += 2;
        } else {
            $notes[] = 'Custo médio por colaborador permaneceu genérico porque não há salários suficientes preenchidos.';
        }

        $retentionSeries = $this->buildRetentionSeries($turnoverSeries);
        if ($retentionSeries['has_data']) {
            $dataset['retencaoTalentos']['value'] = $this->formatPercent($retentionSeries['latest'], 0);
            $dataset['retencaoTalentos']['meta'] = 'estimado com base na retenção observada na tabela colaboradores';
            $dataset['retencaoTalentos']['change'] = $retentionSeries['change_label'];
            $dataset['retencaoTalentos']['values'] = $retentionSeries['values'];
            $dataset['retencaoTalentos']['labels'] = $retentionSeries['labels'];
            $realMetrics++;
        }

        $areaDistribution = $this->buildAreaDistribution($currentActiveRows);
        if ($areaDistribution !== []) {
            $dataset['colaboradoresArea'] = $areaDistribution;
            $realMetrics++;
        }

        $tenureDistribution = $this->buildTenureDistribution($currentActiveRows, $periodEnd);
        if ($tenureDistribution !== []) {
            $dataset['tempoEmpresa'] = $tenureDistribution;
            $realMetrics++;
        } else {
            $notes[] = 'Distribuição por tempo de empresa permaneceu genérica porque faltam datas de admissão suficientes.';
        }

        $turnoverByAge = $this->buildTurnoverByAge($rows, $periodEnd);
        if ($turnoverByAge !== []) {
            $dataset['turnoverFaixaEtaria'] = $turnoverByAge;
            $realMetrics++;
        } else {
            $notes[] = 'Turnover por faixa etária permaneceu genérico porque faltam datas de nascimento ou desligamento suficientes.';
        }

        return $this->withSourceSummary($dataset, $realMetrics, count($currentActiveRows), $notes);
    }

    private function loadCollaborators(string $selectedArea): array
    {
        $sql = 'SELECT c.id, c.nome, c.ativo, c.salario_atual, c.data_admissao, c.data_demissao, c.data_nascimento,
                       c.empresa_id, c.setor_id, c.cargo_id,
                       COALESCE(s.nome, "") AS setor_nome,
                       COALESCE(cg.nome, "") AS cargo_nome,
                       COALESCE(e.nome, "") AS empresa_nome
                FROM colaboradores c
                LEFT JOIN setores s ON s.id = c.setor_id
                LEFT JOIN cargos cg ON cg.id = c.cargo_id
                LEFT JOIN empresas e ON e.id = c.empresa_id
                WHERE 1=1';
        $params = [];

        if ($selectedArea !== 'todas' && isset(self::AREA_KEYWORDS[$selectedArea])) {
            $clauses = [];
            foreach (self::AREA_KEYWORDS[$selectedArea] as $keyword) {
                $clauses[] = 'LOWER(CONCAT_WS(" ", COALESCE(s.nome, ""), COALESCE(cg.nome, ""), COALESCE(e.nome, ""))) LIKE ?';
                $params[] = '%' . $keyword . '%';
            }
            $sql .= ' AND (' . implode(' OR ', $clauses) . ')';
        }

        $sql .= ' ORDER BY c.id ASC';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function buildHeadcountSeries(array $rows, array $months): array
    {
        $values = [];
        foreach ($months as $month) {
            $monthEnd = $month->modify('last day of this month');
            $count = 0;
            foreach ($rows as $row) {
                if ($this->isActiveOnDate($row, $monthEnd)) {
                    $count++;
                }
            }
            $values[] = $count;
        }

        $latest = (int)($values[count($values) - 1] ?? 0);
        $previous = (int)($values[count($values) - 2] ?? $latest);

        return [
            'current' => $latest,
            'trend' => $this->normalizeTrendValues($values),
            'change_label' => $this->formatAbsoluteChange($latest - $previous, 'vs. mês anterior'),
        ];
    }

    private function buildTurnoverSeries(array $rows, array $months): array
    {
        $labels = [];
        $values = [];
        foreach ($months as $month) {
            $monthStart = $month->modify('first day of this month');
            $monthEnd = $month->modify('last day of this month');
            $headcountStart = 0;
            $headcountEnd = 0;
            $dismissals = 0;

            foreach ($rows as $row) {
                if ($this->isActiveOnDate($row, $monthStart->modify('-1 day'))) {
                    $headcountStart++;
                }
                if ($this->isActiveOnDate($row, $monthEnd)) {
                    $headcountEnd++;
                }
                if ($this->isDateWithinMonth($row['data_demissao'] ?? null, $monthStart, $monthEnd)) {
                    $dismissals++;
                }
            }

            $averageHeadcount = ($headcountStart + $headcountEnd) / 2;
            $values[] = $averageHeadcount > 0 ? round(($dismissals / $averageHeadcount) * 100, 1) : 0.0;
            $labels[] = $this->formatMonthLabel($monthStart);
        }

        $latest = (float)($values[count($values) - 1] ?? 0.0);
        $previous = (float)($values[count($values) - 2] ?? $latest);
        $hasData = array_sum($values) > 0;

        return [
            'has_data' => $hasData,
            'labels' => $labels,
            'values' => $values,
            'trend' => $this->normalizeTrendValues($values),
            'latest' => $latest,
            'change_label' => $this->formatPointChange($latest - $previous),
        ];
    }

    private function buildAverageSalarySeries(array $rows, array $months): array
    {
        $values = [];
        $bars = [];
        foreach ($months as $month) {
            $monthEnd = $month->modify('last day of this month');
            $sum = 0.0;
            $count = 0;
            foreach ($rows as $row) {
                $salary = (float)($row['salario_atual'] ?? 0);
                if ($salary <= 0 || !$this->isActiveOnDate($row, $monthEnd)) {
                    continue;
                }
                $sum += $salary;
                $count++;
            }
            $average = $count > 0 ? round($sum / $count, 2) : 0.0;
            $values[] = $average;
            $bars[] = ['label' => $this->formatMonthLabel($month), 'value' => max($average, 0)];
        }

        $latest = (float)($values[count($values) - 1] ?? 0.0);
        $previous = (float)($values[count($values) - 2] ?? $latest);
        $hasData = max($values) > 0;

        return [
            'has_data' => $hasData,
            'latest' => $latest,
            'trend' => $this->normalizeTrendValues($values),
            'bars' => array_slice($bars, -6),
            'change_label' => $this->formatPercentChange($latest, $previous),
        ];
    }

    private function buildRetentionSeries(array $turnoverSeries): array
    {
        if (!($turnoverSeries['has_data'] ?? false)) {
            return ['has_data' => false];
        }

        $values = array_map(static fn($value): float => round(max(0, 100 - (float)$value), 1), $turnoverSeries['values']);
        $latest = (float)($values[count($values) - 1] ?? 0.0);
        $previous = (float)($values[count($values) - 2] ?? $latest);

        return [
            'has_data' => true,
            'values' => array_slice($values, -6),
            'labels' => array_slice($turnoverSeries['labels'], -6),
            'latest' => $latest,
            'change_label' => $this->formatPointChange($latest - $previous),
        ];
    }

    private function buildAreaDistribution(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $groups = [];
        foreach ($rows as $row) {
            $label = $this->resolveAreaLabel($row);
            $groups[$label] = ($groups[$label] ?? 0) + 1;
        }

        arsort($groups);
        $total = array_sum($groups);
        $colors = ['#2563eb', '#06b6d4', '#a855f7', '#10b981', '#f97316', '#94a3b8'];

        $items = [];
        $others = 0;
        $index = 0;
        foreach ($groups as $label => $value) {
            if ($index < 5) {
                $items[] = [
                    'label' => $label,
                    'value' => $value,
                    'percent' => $this->formatPercent(($value / $total) * 100, 1),
                    'color' => $colors[$index] ?? '#94a3b8',
                ];
            } else {
                $others += $value;
            }
            $index++;
        }

        if ($others > 0) {
            $items[] = [
                'label' => 'Outros',
                'value' => $others,
                'percent' => $this->formatPercent(($others / $total) * 100, 1),
                'color' => $colors[count($items)] ?? '#94a3b8',
            ];
        }

        return $items;
    }

    private function buildTenureDistribution(array $rows, DateTimeImmutable $referenceDate): array
    {
        $buckets = [
            'Até 1 ano' => 0,
            '1 a 3 anos' => 0,
            '3 a 5 anos' => 0,
            '5 a 10 anos' => 0,
            'Acima de 10 anos' => 0,
        ];
        $total = 0;

        foreach ($rows as $row) {
            $admissionDate = $this->safeDate($row['data_admissao'] ?? null);
            if ($admissionDate === null) {
                continue;
            }
            $months = $this->monthsDiff($admissionDate, $referenceDate);
            $total++;
            if ($months < 12) {
                $buckets['Até 1 ano']++;
            } elseif ($months < 36) {
                $buckets['1 a 3 anos']++;
            } elseif ($months < 60) {
                $buckets['3 a 5 anos']++;
            } elseif ($months < 120) {
                $buckets['5 a 10 anos']++;
            } else {
                $buckets['Acima de 10 anos']++;
            }
        }

        if ($total === 0) {
            return [];
        }

        $result = [];
        foreach ($buckets as $label => $count) {
            $result[] = [
                'label' => $label,
                'value' => (int)round(($count / $total) * 100),
            ];
        }

        return $result;
    }

    private function buildTurnoverByAge(array $rows, DateTimeImmutable $periodEnd): array
    {
        $windowStart = $periodEnd->modify('first day of this month')->modify('-11 months');
        $windowEnd = $periodEnd->modify('last day of this month');
        $buckets = [
            'Até 25 anos' => 0,
            '26 a 35 anos' => 0,
            '36 a 45 anos' => 0,
            '46 a 55 anos' => 0,
            'Acima de 55 anos' => 0,
        ];
        $total = 0;

        foreach ($rows as $row) {
            $dismissalDate = $this->safeDate($row['data_demissao'] ?? null);
            $birthDate = $this->safeDate($row['data_nascimento'] ?? null);
            if ($dismissalDate === null || $birthDate === null) {
                continue;
            }
            if ($dismissalDate < $windowStart || $dismissalDate > $windowEnd) {
                continue;
            }

            $age = (int)$birthDate->diff($dismissalDate)->y;
            $total++;
            if ($age <= 25) {
                $buckets['Até 25 anos']++;
            } elseif ($age <= 35) {
                $buckets['26 a 35 anos']++;
            } elseif ($age <= 45) {
                $buckets['36 a 45 anos']++;
            } elseif ($age <= 55) {
                $buckets['46 a 55 anos']++;
            } else {
                $buckets['Acima de 55 anos']++;
            }
        }

        if ($total === 0) {
            return [];
        }

        $result = [];
        foreach ($buckets as $label => $count) {
            $result[] = [
                'label' => $label,
                'value' => round(($count / $total) * 100, 1),
            ];
        }

        return $result;
    }

    private function resolveAreaLabel(array $row): string
    {
        foreach (['setor_nome', 'cargo_nome', 'empresa_nome'] as $field) {
            $value = trim((string)($row[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return 'Sem área definida';
    }

    private function withSourceSummary(array $dataset, int $realMetrics, int $activeScope, array $notes): array
    {
        $fallbackMetrics = max(0, self::TOTAL_DASHBOARD_SLOTS - $realMetrics);
        $dataset['sourceSummary'] = [
            'real_metrics' => $realMetrics,
            'fallback_metrics' => $fallbackMetrics,
            'active_scope' => $activeScope,
            'notes' => $notes,
        ];

        return $dataset;
    }

    private function resolvePeriodEnd(string $selectedPeriod): DateTimeImmutable
    {
        $normalized = $this->normalizeText($selectedPeriod);
        if (preg_match('/^([a-z]+)-(\d{4})$/', $normalized, $matches)) {
            $monthMap = [
                'janeiro' => 1,
                'fevereiro' => 2,
                'marco' => 3,
                'abril' => 4,
                'maio' => 5,
                'junho' => 6,
                'julho' => 7,
                'agosto' => 8,
                'setembro' => 9,
                'outubro' => 10,
                'novembro' => 11,
                'dezembro' => 12,
            ];
            $month = $monthMap[$matches[1]] ?? null;
            if ($month !== null) {
                return (new DateTimeImmutable(sprintf('%04d-%02d-01', (int)$matches[2], $month)))->modify('last day of this month');
            }
        }

        return (new DateTimeImmutable('today'))->modify('last day of this month');
    }

    private function buildMonthWindow(DateTimeImmutable $periodEnd, int $months): array
    {
        $window = [];
        $cursor = $periodEnd->modify('first day of this month')->modify('-' . ($months - 1) . ' months');
        for ($i = 0; $i < $months; $i++) {
            $window[] = $cursor;
            $cursor = $cursor->modify('+1 month');
        }
        return $window;
    }

    private function isActiveOnDate(array $row, DateTimeImmutable $date): bool
    {
        $admissionDate = $this->safeDate($row['data_admissao'] ?? null);
        if ($admissionDate === null || $admissionDate > $date) {
            return false;
        }

        $dismissalDate = $this->safeDate($row['data_demissao'] ?? null);
        return $dismissalDate === null || $dismissalDate > $date;
    }

    private function isDateWithinMonth(?string $value, DateTimeImmutable $monthStart, DateTimeImmutable $monthEnd): bool
    {
        $date = $this->safeDate($value);
        return $date !== null && $date >= $monthStart && $date <= $monthEnd;
    }

    private function safeDate(?string $value): ?DateTimeImmutable
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable $e) {
            return null;
        }
    }

    private function monthsDiff(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        return ((int)$start->diff($end)->y * 12) + (int)$start->diff($end)->m;
    }

    private function normalizeTrendValues(array $values): array
    {
        return array_map(static fn($value): int => (int)round((float)$value), $values);
    }

    private function formatMonthLabel(DateTimeImmutable $date): string
    {
        $month = (int)$date->format('n');
        return (self::MONTH_LABELS[$month] ?? $date->format('M')) . '/' . $date->format('y');
    }

    private function formatPercent(float $value, int $decimals = 1): string
    {
        return number_format($value, $decimals, ',', '.') . '%';
    }

    private function formatNumber(float $value, int $decimals = 0): string
    {
        return number_format($value, $decimals, ',', '.');
    }

    private function formatAbsoluteChange(int $delta, string $context): string
    {
        if ($delta === 0) {
            return 'Estável ' . $context;
        }

        return sprintf('%+d %s', $delta, $context);
    }

    private function formatPointChange(float $delta): string
    {
        if (abs($delta) < 0.05) {
            return 'Estável vs. mês anterior';
        }

        $formatted = number_format(abs($delta), 1, ',', '.');
        return sprintf('%s%s p.p. vs. mês anterior', $delta > 0 ? '+' : '-', $formatted);
    }

    private function formatPercentChange(float $current, float $previous): string
    {
        if ($previous <= 0.0) {
            return 'Base real atualizada';
        }

        $delta = (($current - $previous) / $previous) * 100;
        if (abs($delta) < 0.05) {
            return 'Estável vs. mês anterior';
        }

        $formatted = number_format(abs($delta), 1, ',', '.') . '%';
        return sprintf('%s%s vs. mês anterior', $delta > 0 ? '+' : '-', $formatted);
    }

    private function normalizeText(string $value): string
    {
        $value = strtolower(trim($value));
        $search = ['á', 'à', 'ã', 'â', 'é', 'ê', 'í', 'ó', 'ô', 'õ', 'ú', 'ç'];
        $replace = ['a', 'a', 'a', 'a', 'e', 'e', 'i', 'o', 'o', 'o', 'u', 'c'];
        return str_replace($search, $replace, $value);
    }
}
