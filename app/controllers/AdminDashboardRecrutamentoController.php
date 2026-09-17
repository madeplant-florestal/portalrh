<?php

/**
 * Dashboard de Recrutamento e Seleção. Protegido por permissão individual
 * (`dashboard_recrutamento.visualizar`) — Admin mantém o bypass central de
 * `Authorization::usuarioTemPermissao()`; RH/Supervisor não recebem acesso automático pela role
 * (diferente do padrão "aditivo" usado nos módulos legados — decisão explícita desta sprint).
 */
class AdminDashboardRecrutamentoController extends Controller
{
    /** Mesmo conjunto de períodos de AdminRhIndicadoresController — UI consistente entre os dois
     *  dashboards. Não compartilhado via classe comum: são ~10 linhas de boilerplate de datas, e
     *  os dois controllers não têm nenhuma outra razão para depender um do outro. */
    private const PERIODOS = [
        '12m' => 'Últimos 12 meses',
        '6m' => 'Últimos 6 meses',
        'ano_atual' => 'Ano atual',
        'ano_anterior' => 'Ano anterior',
    ];

    public function index(): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        Authorization::requirePermissao('dashboard_recrutamento.visualizar');

        $periodoSelecionado = Security::sanitizeString($_GET['periodo'] ?? '12m');
        if (!array_key_exists($periodoSelecionado, self::PERIODOS)) {
            $periodoSelecionado = '12m';
        }
        [$inicio, $fim] = $this->resolverPeriodo($periodoSelecionado);

        $empresaIdRaw = Security::sanitizeString($_GET['empresa'] ?? '');
        $vagaIdRaw = Security::sanitizeString($_GET['vaga'] ?? '');
        $filtros = [
            'empresa_id' => ctype_digit($empresaIdRaw) ? (int)$empresaIdRaw : null,
            'vaga_id' => ctype_digit($vagaIdRaw) ? (int)$vagaIdRaw : null,
        ];

        $service = new RecrutamentoIndicadoresService();
        $painel = null;
        $erro = null;
        try {
            $painel = $service->montarPainel($filtros, $inicio, $fim);
            $opcoesFiltro = $service->opcoesFiltro();
        } catch (Throwable $e) {
            Logger::exception($e, 'ERROR', ['controller' => 'AdminDashboardRecrutamentoController']);
            $erro = 'Não foi possível carregar o Dashboard de Recrutamento e Seleção agora. Tente novamente em instantes.';
            $opcoesFiltro = ['empresas' => [], 'vagas' => []];
        }

        $this->view->render('admin/dashboard-recrutamento', [
            'painel' => $painel,
            'erro' => $erro,
            'opcoesFiltro' => $opcoesFiltro,
            'periodos' => self::PERIODOS,
            'periodoSelecionado' => $periodoSelecionado,
            'filtrosSelecionados' => [
                'empresa' => $filtros['empresa_id'] !== null ? (string)$filtros['empresa_id'] : '',
                'vaga' => $filtros['vaga_id'] !== null ? (string)$filtros['vaga_id'] : '',
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
