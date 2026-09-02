<?php

/**
 * Dashboard Analítico de RH (Fase 4) — indicadores oficiais alimentados pelo espelho
 * `colaboradores_metadados` (METADADOS, só leitura). Nunca consulta o SQL Server em tempo real —
 * trabalha inteiramente sobre o espelho local, para não depender da disponibilidade do METADADOS
 * para abrir a tela. Ver docs/claude/indicadores-rh.md para o dicionário de indicadores.
 */
class AdminRhIndicadoresController extends Controller
{
    private const PERIODOS = [
        '12m' => 'Últimos 12 meses',
        '6m' => 'Últimos 6 meses',
        'ano_atual' => 'Ano atual',
        'ano_anterior' => 'Ano anterior',
    ];

    public function index(): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);

        $periodoSelecionado = Security::sanitizeString($_GET['periodo'] ?? '12m');
        if (!array_key_exists($periodoSelecionado, self::PERIODOS)) {
            $periodoSelecionado = '12m';
        }

        $filtros = [
            'codigo_empresa' => Security::sanitizeString($_GET['empresa'] ?? ''),
            'codigo_unidade' => Security::sanitizeString($_GET['unidade'] ?? ''),
            'cargo' => Security::sanitizeString($_GET['cargo'] ?? ''),
            'setor' => Security::sanitizeString($_GET['setor'] ?? ''),
            'centro_custo' => Security::sanitizeString($_GET['centro_custo'] ?? ''),
        ];
        $filtros = array_filter($filtros, static fn(string $v): bool => $v !== '');

        [$inicio, $fim] = $this->resolverPeriodo($periodoSelecionado);

        $service = new RhIndicadoresService();
        $painel = null;
        $erro = null;
        try {
            $painel = $service->montarPainel($filtros, $inicio, $fim);
            $opcoesFiltro = $service->opcoesFiltro();
        } catch (Throwable $e) {
            Logger::exception($e, 'ERROR', ['controller' => 'AdminRhIndicadoresController']);
            $erro = 'Não foi possível carregar os indicadores agora. Tente novamente em instantes.';
            $opcoesFiltro = ['empresas' => [], 'unidades' => [], 'cargos' => [], 'setores' => [], 'centrosCusto' => []];
        }

        // "Última atualização" e botão "Atualizar dados": fonte é o histórico oficial de
        // sincronizações (metadados_sync_execucoes), nunca updated_at de colaborador. Resiliente
        // a ambiente onde a migration ainda não rodou — nesse caso o cabeçalho só omite a data.
        $ultimaSincronizacao = null;
        try {
            $ultima = (new MetadadosSyncExecucaoRepository())->ultimaSincronizacaoValida();
            if ($ultima !== null && !empty($ultima['concluido_em'])) {
                $d = new DateTimeImmutable((string)$ultima['concluido_em']);
                $ultimaSincronizacao = $d->format('d/m/Y') . ' às ' . $d->format('H:i');
            }
        } catch (Throwable $e) {
            Logger::warning('Não foi possível ler a última sincronização do METADADOS', ['erro' => $e->getMessage()]);
        }

        $syncConfig = Config::get()['metadados_sync'] ?? [];
        $orquestradorConfigurado = preg_match('#^https://#i', (string)($syncConfig['orchestrator_url'] ?? '')) === 1
            && (string)($syncConfig['orchestrator_secret'] ?? '') !== '';
        $podeSincronizar = in_array(strtolower((string)(Auth::role() ?? '')), ['admin', 'rh'], true)
            || !empty($_SESSION['user_is_supervisor']);

        $this->view->render('admin/indicadores-rh', [
            'painel' => $painel,
            'erro' => $erro,
            'opcoesFiltro' => $opcoesFiltro,
            'periodos' => self::PERIODOS,
            'periodoSelecionado' => $periodoSelecionado,
            'filtrosSelecionados' => [
                'empresa' => $filtros['codigo_empresa'] ?? '',
                'unidade' => $filtros['codigo_unidade'] ?? '',
                'cargo' => $filtros['cargo'] ?? '',
                'setor' => $filtros['setor'] ?? '',
                'centro_custo' => $filtros['centro_custo'] ?? '',
            ],
            'periodoInicio' => $inicio,
            'periodoFim' => $fim,
            'ultimaSincronizacao' => $ultimaSincronizacao,
            'podeSincronizar' => $podeSincronizar,
            'orquestradorConfigurado' => $orquestradorConfigurado,
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
