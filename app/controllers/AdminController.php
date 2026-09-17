<?php
class AdminController extends Controller
{
/** Mesmo conjunto de períodos já usado nos demais dashboards desta geração (Indicadores de RH,
     *  Dashboard de Recrutamento) — consistência de UI entre as telas. */
    private const PERIODOS = [
        '12m' => 'Últimos 12 meses',
        '6m' => 'Últimos 6 meses',
        'ano_atual' => 'Ano atual',
        'ano_anterior' => 'Ano anterior',
    ];

    public function index(): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        Authorization::requirePermissao('dashboard.visualizar');

        $periodoSelecionado = Security::sanitizeString($_GET['periodo'] ?? '12m');
        if (!array_key_exists($periodoSelecionado, self::PERIODOS)) {
            $periodoSelecionado = '12m';
        }
        [$inicio, $fim] = $this->resolverPeriodo($periodoSelecionado);

        $filtros = [
            'codigo_empresa' => Security::sanitizeString($_GET['empresa'] ?? ''),
            'codigo_setor' => Security::sanitizeString($_GET['setor'] ?? ''),
        ];
        $filtros = array_filter($filtros, static fn(string $v): bool => $v !== '');

        $service = new PeopleAnalyticsService();
        $painel = null;
        $erro = null;
        try {
            $painel = $service->montarPainel($filtros, $inicio, $fim);
            $opcoesFiltro = $service->opcoesFiltro();
        } catch (Throwable $e) {
            Logger::exception($e, 'ERROR', ['controller' => 'AdminController']);
            $erro = 'Não foi possível carregar o painel de People Analytics agora. Tente novamente em instantes.';
            $opcoesFiltro = ['empresas' => [], 'setores' => []];
        }

        $this->view->render('admin/dashboard', [
            'painel' => $painel,
            'erro' => $erro,
            'opcoesFiltro' => $opcoesFiltro,
            'periodos' => self::PERIODOS,
            'periodoSelecionado' => $periodoSelecionado,
            'filtrosSelecionados' => [
                'empresa' => $filtros['codigo_empresa'] ?? '',
                'setor' => $filtros['codigo_setor'] ?? '',
            ],
            'periodoInicio' => $inicio,
            'periodoFim' => $fim,
        ], 'layouts/admin');
    }

    /** @return array{0:DateTimeImmutable,1:DateTimeImmutable} */
    private function resolverPeriodo(string $periodo): array
    {
        $hoje = new DateTimeImmutable('today');
        switch ($periodo) {
            case '6m':
                return [$hoje->modify('first day of this month')->modify('-5 months'), $hoje];
            case 'ano_atual':
                return [new DateTimeImmutable($hoje->format('Y') . '-01-01'), $hoje];
            case 'ano_anterior':
                $anoAnterior = (int)$hoje->format('Y') - 1;
                return [new DateTimeImmutable("{$anoAnterior}-01-01"), new DateTimeImmutable("{$anoAnterior}-12-31")];
            case '12m':
            default:
                return [$hoje->modify('first day of this month')->modify('-11 months'), $hoje];
        }
    }

}
