<?php
class AdminController extends Controller
{
    public function index(): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);

        $period = Security::sanitizeString($_GET['period'] ?? 'maio-2026');
        $area = Security::sanitizeString($_GET['area'] ?? 'todas');
        $vagasAtivas = count(Vaga::allActive());
        $totalCandidaturas = count(Candidatura::all());
        $colaboradoresCadastrados = class_exists('Colaborador') ? Colaborador::countAll() : 0;
        $dashboardFallback = $this->dashboardDataset();
        $dashboard = $dashboardFallback;

        if (class_exists('CollaboratorDashboardDataService')) {
            $dashboard = (new CollaboratorDashboardDataService())->build($dashboardFallback, $period, $area);
        }

        $this->view->render('admin/dashboard', [
            'vagasAtivas' => $vagasAtivas,
            'totalCandidaturas' => $totalCandidaturas,
            'colaboradoresCadastrados' => $colaboradoresCadastrados,
            'periodOptions' => $this->periodOptions(),
            'areaOptions' => $this->areaOptions(),
            'selectedPeriod' => $period,
            'selectedArea' => $area,
            'dashboard' => $dashboard,
        ], 'layouts/admin');
    }

    private function periodOptions(): array
    {
        return [
            'maio-2026' => 'Maio/2026',
            'abril-2026' => 'Abril/2026',
            'marco-2026' => 'Março/2026',
        ];
    }

    private function areaOptions(): array
    {
        return [
            'todas' => 'Todas as áreas',
            'operacoes' => 'Operações',
            'administrativo' => 'Administrativo',
            'tecnologia' => 'Tecnologia',
            'rh' => 'RH/DP/SST',
        ];
    }

    private function dashboardDataset(): array
    {
        return [
            'kpis' => [
                [
                    'label' => 'Headcount',
                    'value' => '1.274',
                    'suffix' => 'colaboradores',
                    'change' => '+2,3% vs. Abr/2026',
                    'color' => 'blue',
                    'icon' => 'users',
                    'trend' => [8, 12, 9, 15, 10, 13, 11, 17, 12, 15, 11, 16],
                    'href' => '/admin/colaboradores',
                ],
                [
                    'label' => 'Turnover (12m)',
                    'value' => '8,7%',
                    'suffix' => '',
                    'change' => '-0,5 p.p. vs. Abr/2026',
                    'color' => 'violet',
                    'icon' => 'refresh',
                    'trend' => [11, 12, 10, 13, 12, 14, 11, 13, 12, 10, 8, 9],
                ],
                [
                    'label' => 'Absenteísmo',
                    'value' => '3,6%',
                    'suffix' => '',
                    'change' => '-0,2 p.p. vs. Abr/2026',
                    'color' => 'teal',
                    'icon' => 'calendar',
                    'trend' => [4, 6, 5, 7, 6, 8, 5, 7, 4, 6, 5, 4],
                ],
                [
                    'label' => 'Engajamento',
                    'value' => '74%',
                    'suffix' => '',
                    'change' => '+3 p.p. vs. Abr/2026',
                    'color' => 'green',
                    'icon' => 'smile',
                    'trend' => [9, 11, 13, 15, 12, 14, 13, 16, 17, 18, 19, 20],
                ],
                [
                    'label' => 'NPS eNPS',
                    'value' => '+45',
                    'suffix' => '',
                    'change' => '+6 vs. Abr/2026',
                    'color' => 'amber',
                    'icon' => 'star',
                    'trend' => [5, 7, 6, 9, 8, 10, 8, 9, 7, 8, 7, 8],
                ],
                [
                    'label' => 'Custo por Colaborador',
                    'value' => 'R$ 4.128',
                    'suffix' => '',
                    'change' => '-3,2% vs. Abr/2026',
                    'color' => 'pink',
                    'icon' => 'money',
                    'trend' => [12, 14, 13, 12, 11, 10, 9, 10, 8, 7, 9, 11],
                ],
            ],
            'turnoverSeries' => [
                'labels' => ['Jun/25', 'Jul/25', 'Ago/25', 'Set/25', 'Out/25', 'Nov/25', 'Dez/25', 'Jan/26', 'Fev/26', 'Mar/26', 'Abr/26', 'Mai/26'],
                'values' => [9.1, 9.3, 9.6, 9.8, 9.7, 9.4, 9.2, 8.9, 8.6, 8.5, 8.2, 8.7],
                'highlight' => '8,7%',
            ],
            'turnoverGenero' => [
                ['label' => 'Feminino', 'value' => 8.1, 'color' => '#7c3aed'],
                ['label' => 'Masculino', 'value' => 9.3, 'color' => '#2563eb'],
            ],
            'turnoverFaixaEtaria' => [
                ['label' => 'Até 25 anos', 'value' => 14.2],
                ['label' => '26 a 35 anos', 'value' => 9.8],
                ['label' => '36 a 45 anos', 'value' => 7.2],
                ['label' => '46 a 55 anos', 'value' => 5.1],
                ['label' => 'Acima de 55 anos', 'value' => 3.2],
            ],
            'tempoFechamento' => [
                'value' => 34,
                'meta' => 'Meta: 35 dias',
                'change' => '-2 dias vs. Abr/2026',
                'bars' => [
                    ['label' => 'Dez/25', 'value' => 45],
                    ['label' => 'Jan/26', 'value' => 42],
                    ['label' => 'Fev/26', 'value' => 40],
                    ['label' => 'Mar/26', 'value' => 37],
                    ['label' => 'Abr/26', 'value' => 36],
                    ['label' => 'Mai/26', 'value' => 34],
                ],
            ],
            'retencaoTalentos' => [
                'value' => '92%',
                'meta' => 'Meta: 90%',
                'change' => '+2 p.p. vs. Abr/2026',
                'values' => [90, 90, 91, 91, 90, 92],
                'labels' => ['Dez/25', 'Jan/26', 'Fev/26', 'Mar/26', 'Abr/26', 'Mai/26'],
            ],
            'custoContratacao' => [
                'value' => 'R$ 4.128',
                'meta' => 'por contratação',
                'change' => '-3,2% vs. Abr/2026',
                'bars' => [
                    ['label' => 'Dez/25', 'value' => 4560],
                    ['label' => 'Jan/26', 'value' => 4420],
                    ['label' => 'Fev/26', 'value' => 4300],
                    ['label' => 'Mar/26', 'value' => 4250],
                    ['label' => 'Abr/26', 'value' => 4260],
                    ['label' => 'Mai/26', 'value' => 4128],
                ],
            ],
            'colaboradoresArea' => [
                ['label' => 'Operações', 'value' => 512, 'percent' => '40,2%', 'color' => '#2563eb'],
                ['label' => 'Comercial', 'value' => 246, 'percent' => '19,3%', 'color' => '#06b6d4'],
                ['label' => 'Administrativo', 'value' => 198, 'percent' => '15,6%', 'color' => '#a855f7'],
                ['label' => 'Tecnologia', 'value' => 164, 'percent' => '12,9%', 'color' => '#10b981'],
                ['label' => 'Financeiro', 'value' => 98, 'percent' => '7,7%', 'color' => '#f97316'],
                ['label' => 'Outros', 'value' => 56, 'percent' => '4,3%', 'color' => '#94a3b8'],
            ],
            'tempoEmpresa' => [
                ['label' => 'Até 1 ano', 'value' => 18],
                ['label' => '1 a 3 anos', 'value' => 28],
                ['label' => '3 a 5 anos', 'value' => 22],
                ['label' => '5 a 10 anos', 'value' => 20],
                ['label' => 'Acima de 10 anos', 'value' => 12],
            ],
            'treinamento' => [
                ['label' => 'Horas de Treinamento', 'value' => '18,7 mil', 'change' => '+12% vs. Abr/2026', 'icon' => 'academic', 'color' => 'blue'],
                ['label' => 'Média de Horas por Colaborador', 'value' => '14,7 h', 'change' => '+1,8 h vs. Abr/2026', 'icon' => 'book', 'color' => 'violet'],
                ['label' => 'Taxa de Participação', 'value' => '78%', 'change' => '+6 p.p. vs. Abr/2026', 'icon' => 'target', 'color' => 'green'],
            ],
        ];
    }
}
