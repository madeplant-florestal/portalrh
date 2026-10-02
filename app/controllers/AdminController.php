<?php
class AdminController extends Controller
{
/** Mesmo conjunto de períodos já usado nos demais dashboards desta geração (Indicadores de RH,
     *  Dashboard de Recrutamento) — consistência de UI entre as telas. */
    private const PERIODOS = [
        '12m' => 'Últimos 12 meses',
        '6m' => 'Últimos 6 meses',
        'mes_atual' => 'Mês atual',
        'ano_atual' => 'Ano atual',
        'ano_anterior' => 'Ano anterior',
        '12m_anteriores' => '12 meses anteriores',
        'mes' => 'Mês específico',
        'ano_especifico' => 'Ano específico',
        'personalizado' => 'Intervalo personalizado',
    ];

    private const COMPARATIVOS = [
        'ano_anterior' => 'Mesmo período do ano anterior',
        'anterior' => 'Período imediatamente anterior',
    ];

    public function index(): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        Authorization::requirePermissao('dashboard.visualizar');

        $periodoSelecionado = Security::sanitizeString($_GET['periodo'] ?? '12m');
        if (!array_key_exists($periodoSelecionado, self::PERIODOS)) {
            $periodoSelecionado = '12m';
        }
        $periodoParams = [
            'mes' => Security::sanitizeString($_GET['mes'] ?? ''),
            'ano' => Security::sanitizeString($_GET['ano'] ?? ''),
            'data_inicio' => Security::sanitizeString($_GET['data_inicio'] ?? ''),
            'data_fim' => Security::sanitizeString($_GET['data_fim'] ?? ''),
        ];
        [$inicio, $fim] = $this->resolverPeriodo($periodoSelecionado, $periodoParams);

        $comparativoSelecionado = Security::sanitizeString($_GET['comparativo'] ?? 'ano_anterior');
        if (!array_key_exists($comparativoSelecionado, self::COMPARATIVOS)) {
            $comparativoSelecionado = 'ano_anterior';
        }
        [$compInicio, $compFim] = $comparativoSelecionado === 'anterior'
            ? RhIndicadoresService::periodoImediatamenteAnterior($inicio, $fim)
            : RhIndicadoresService::periodoMesmoIntervaloAnoAnterior($inicio, $fim);

        $filtrosInterativos = $this->filtrosInterativosDoRequest();
        $filtros = [
            'codigo_empresa' => Security::sanitizeString($_GET['empresa'] ?? ''),
            'codigo_setor' => Security::sanitizeString($_GET['setor'] ?? ''),
            'sexo' => $filtrosInterativos['sexo'],
            'motivo_categoria' => $filtrosInterativos['motivo_categoria'],
            // Gestor (Bloco 7, 2026-10, pedido do RH): só aplicado ao bloco Integração/Onboarding —
            // ver PeopleAnalyticsService::montarIntegracaoExecutivo().
            'gestor_usuario_id' => Security::sanitizeString($_GET['gestor'] ?? ''),
        ];
        $filtros = array_filter($filtros, static fn(string $v): bool => $v !== '');

        // Paginação da listagem de Colaboradores — 20/50, padrão 20 (§15 da correção de 2026-09).
        $porPagina = (int)($_GET['por_pagina'] ?? 20);
        if (!in_array($porPagina, [20, 50], true)) {
            $porPagina = 20;
        }
        $pagina = max(1, (int)($_GET['pagina'] ?? 1));

        $filtrosListagem = $filtros;
        $filtrosListagem['contexto_lista'] = $filtrosInterativos['contexto_lista'];
        $filtrosListagem['mes_evento'] = $filtrosInterativos['mes_evento'];

        $service = new PeopleAnalyticsService();
        $painel = null;
        $listagem = null;
        $erro = null;
        try {
            $painel = $service->montarPainel($filtros, $inicio, $fim, $compInicio, $compFim);
            $opcoesFiltro = $service->opcoesFiltro();
            $listagem = $service->listarColaboradores($filtrosListagem, $inicio, $fim, $pagina, $porPagina);
        } catch (Throwable $e) {
            Logger::exception($e, 'ERROR', ['controller' => 'AdminController']);
            $erro = 'Não foi possível carregar o painel de People Analytics agora. Tente novamente em instantes.';
            $opcoesFiltro = ['empresas' => [], 'setores' => [], 'gestores' => []];
        }

        // Mesma fonte/convenção de "Última atualização" do Indicadores de RH
        // (AdminRhIndicadoresController::index()) — nunca deriva de updated_at de colaborador,
        // nunca cria mecanismo novo de sincronização aqui.
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

        $this->view->render('admin/dashboard', [
            'painel' => $painel,
            'erro' => $erro,
            'opcoesFiltro' => $opcoesFiltro,
            'periodos' => self::PERIODOS,
            'periodoSelecionado' => $periodoSelecionado,
            'periodoParams' => $periodoParams,
            'comparativos' => self::COMPARATIVOS,
            'comparativoSelecionado' => $comparativoSelecionado,
            'filtrosSelecionados' => [
                'empresa' => $filtros['codigo_empresa'] ?? '',
                'setor' => $filtros['codigo_setor'] ?? '',
                'gestor' => $filtros['gestor_usuario_id'] ?? '',
            ],
            'filtrosInterativos' => $filtrosInterativos,
            'periodoInicio' => $inicio,
            'periodoFim' => $fim,
            'ultimaSincronizacao' => $ultimaSincronizacao,
            'listagem' => $listagem,
        ], 'layouts/app-shell');
    }

    /**
     * Dados atualizados do painel (Etapa 2 — People Analytics interativo, correção de 2026-09):
     * mesmo cálculo de index(), devolvendo o HTML já renderizado do bloco de resultado (reaproveita
     * o MESMO partial, nunca uma versão paralela em JSON+JS) — o front só troca o innerHTML de um
     * container. Nunca recalcula Turnover/Headcount/Admissões/Desligamentos no JavaScript (§9).
     */
    public function dadosDashboard(): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        Authorization::requirePermissao('dashboard.visualizar');

        $periodoSelecionado = Security::sanitizeString($_GET['periodo'] ?? '12m');
        if (!array_key_exists($periodoSelecionado, self::PERIODOS)) {
            $periodoSelecionado = '12m';
        }
        $periodoParams = [
            'mes' => Security::sanitizeString($_GET['mes'] ?? ''),
            'ano' => Security::sanitizeString($_GET['ano'] ?? ''),
            'data_inicio' => Security::sanitizeString($_GET['data_inicio'] ?? ''),
            'data_fim' => Security::sanitizeString($_GET['data_fim'] ?? ''),
        ];
        [$inicio, $fim] = $this->resolverPeriodo($periodoSelecionado, $periodoParams);

        $comparativoSelecionado = Security::sanitizeString($_GET['comparativo'] ?? 'ano_anterior');
        if (!array_key_exists($comparativoSelecionado, self::COMPARATIVOS)) {
            $comparativoSelecionado = 'ano_anterior';
        }
        [$compInicio, $compFim] = $comparativoSelecionado === 'anterior'
            ? RhIndicadoresService::periodoImediatamenteAnterior($inicio, $fim)
            : RhIndicadoresService::periodoMesmoIntervaloAnoAnterior($inicio, $fim);

        $filtrosInterativos = $this->filtrosInterativosDoRequest();
        $filtros = [
            'codigo_empresa' => Security::sanitizeString($_GET['empresa'] ?? ''),
            'codigo_setor' => Security::sanitizeString($_GET['setor'] ?? ''),
            'sexo' => $filtrosInterativos['sexo'],
            'motivo_categoria' => $filtrosInterativos['motivo_categoria'],
            // Gestor (Bloco 7, 2026-10, pedido do RH): só aplicado ao bloco Integração/Onboarding —
            // ver PeopleAnalyticsService::montarIntegracaoExecutivo().
            'gestor_usuario_id' => Security::sanitizeString($_GET['gestor'] ?? ''),
        ];
        $filtros = array_filter($filtros, static fn(string $v): bool => $v !== '');

        $porPagina = (int)($_GET['por_pagina'] ?? 20);
        if (!in_array($porPagina, [20, 50], true)) {
            $porPagina = 20;
        }
        $pagina = max(1, (int)($_GET['pagina'] ?? 1));

        $filtrosListagem = $filtros;
        $filtrosListagem['contexto_lista'] = $filtrosInterativos['contexto_lista'];
        $filtrosListagem['mes_evento'] = $filtrosInterativos['mes_evento'];

        header('Content-Type: application/json; charset=UTF-8');
        try {
            $service = new PeopleAnalyticsService();
            $painel = $service->montarPainel($filtros, $inicio, $fim, $compInicio, $compFim);
            $listagem = $service->listarColaboradores($filtrosListagem, $inicio, $fim, $pagina, $porPagina);
            $opcoesFiltro = $service->opcoesFiltro();

            $html = $this->view->renderPartial('admin/partials/dashboard/resultado', [
                'painel' => $painel,
                'erro' => null,
                'listagem' => $listagem,
                'opcoesFiltro' => $opcoesFiltro,
                'filtrosSelecionados' => [
                    'empresa' => $filtros['codigo_empresa'] ?? '',
                    'setor' => $filtros['codigo_setor'] ?? '',
                    'gestor' => $filtros['gestor_usuario_id'] ?? '',
                ],
                'filtrosInterativos' => $filtrosInterativos,
                'comparativos' => self::COMPARATIVOS,
                'comparativoSelecionado' => $comparativoSelecionado,
                'periodoSelecionado' => $periodoSelecionado,
                'periodoParams' => $periodoParams,
                'porPagina' => $porPagina,
            ]);

            echo json_encode(['ok' => true, 'html' => $html], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            Logger::exception($e, 'ERROR', ['controller' => 'AdminController', 'action' => 'dadosDashboard']);
            http_response_code(500);
            echo json_encode(['ok' => false, 'mensagem' => 'Não foi possível atualizar o painel agora. Tente novamente em instantes.'], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Filtros interativos (Etapa 2 — People Analytics interativo, correção de 2026-09): sexo,
     * motivo (categoria do Dashboard de Turnover), contexto da listagem e mês de evento — ponto
     * único de leitura/sanitização, reaproveitado por index(), dadosDashboard() e
     * exportarColaboradores(). Mapeamento sempre contra whitelist — nunca um texto livre do
     * request vira coluna/condição dinâmica na query (§14 da correção).
     */
    private function filtrosInterativosDoRequest(): array
    {
        $sexo = Security::sanitizeString($_GET['sexo'] ?? '');
        if (!in_array($sexo, ['M', 'F', 'nao_informado'], true)) {
            $sexo = '';
        }

        $motivoCategoria = Security::sanitizeString($_GET['motivo'] ?? '');
        if (!in_array($motivoCategoria, TurnoverDashboardService::ORDEM_CATEGORIAS, true)) {
            $motivoCategoria = '';
        }

        $contextoLista = Security::sanitizeString($_GET['contexto_lista'] ?? 'ativos');
        if (!in_array($contextoLista, ['ativos', 'desligados', 'admitidos'], true)) {
            $contextoLista = 'ativos';
        }

        $mesEvento = Security::sanitizeString($_GET['mes_evento'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}$/', $mesEvento)) {
            $mesEvento = '';
        }

        return [
            'sexo' => $sexo,
            'motivo_categoria' => $motivoCategoria,
            'contexto_lista' => $contextoLista,
            'mes_evento' => $mesEvento,
        ];
    }

    /**
     * Exportação CSV da listagem de Colaboradores (§16 da correção de 2026-09) — mesma população
     * filtrada da tela (nunca paginada), nunca salário/CPF/dados bancários. GET (download de
     * arquivo, sem mudança de estado — não exige CSRF, mesmo padrão de outras exportações do
     * Portal, ex.: AdminIndicacoesController::export()).
     */
    public function exportarColaboradores(): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        Authorization::requirePermissao('dashboard.visualizar');

        $periodoSelecionado = Security::sanitizeString($_GET['periodo'] ?? '12m');
        if (!array_key_exists($periodoSelecionado, self::PERIODOS)) {
            $periodoSelecionado = '12m';
        }
        $periodoParams = [
            'mes' => Security::sanitizeString($_GET['mes'] ?? ''),
            'ano' => Security::sanitizeString($_GET['ano'] ?? ''),
            'data_inicio' => Security::sanitizeString($_GET['data_inicio'] ?? ''),
            'data_fim' => Security::sanitizeString($_GET['data_fim'] ?? ''),
        ];
        [$inicio, $fim] = $this->resolverPeriodo($periodoSelecionado, $periodoParams);

        $filtrosInterativos = $this->filtrosInterativosDoRequest();
        $filtros = [
            'codigo_empresa' => Security::sanitizeString($_GET['empresa'] ?? ''),
            'codigo_setor' => Security::sanitizeString($_GET['setor'] ?? ''),
            'sexo' => $filtrosInterativos['sexo'],
            'motivo_categoria' => $filtrosInterativos['motivo_categoria'],
            'contexto_lista' => $filtrosInterativos['contexto_lista'],
            'mes_evento' => $filtrosInterativos['mes_evento'],
        ];
        $filtros = array_filter($filtros, static fn(string $v): bool => $v !== '');

        $service = new PeopleAnalyticsService();
        $linhas = $service->exportarColaboradoresCsv($filtros, $inicio, $fim);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="people-analytics-colaboradores-' . date('Ymd-His') . '.csv"');
        $saida = fopen('php://output', 'w');
        fwrite($saida, "\xEF\xBB\xBF"); // BOM UTF-8 — Excel abre acentuação corretamente.
        fputcsv($saida, ['Nome', 'Empresa', 'Unidade', 'Setor', 'Cargo', 'Sexo', 'Admissão', 'Situação', 'Tempo de Empresa', 'Centro de Custo', 'Gestor Imediato'], ';');
        foreach ($linhas as $linha) {
            fputcsv($saida, [
                $linha['nome'], $linha['empresa'], $linha['unidade'], $linha['setor'], $linha['cargo'],
                $linha['sexo'], $linha['admissao'], $linha['situacao'], $linha['tempo_empresa'],
                $linha['centro_custo'], $linha['gestor_imediato'],
            ], ';');
        }
        fclose($saida);
    }

    /**
     * @param array{mes?:string,ano?:string,data_inicio?:string,data_fim?:string} $params Vindos do
     *        $_GET, só usados pelos modos 'mes'/'ano_especifico'/'personalizado'.
     * @return array{0:DateTimeImmutable,1:DateTimeImmutable}
     */
    private function resolverPeriodo(string $periodo, array $params = []): array
    {
        $hoje = new DateTimeImmutable('today');
        $padrao = static fn(): array => [$hoje->modify('first day of this month')->modify('-11 months'), $hoje];

        switch ($periodo) {
            case '6m':
                return [$hoje->modify('first day of this month')->modify('-5 months'), $hoje];
            case 'mes_atual':
                return [$hoje->modify('first day of this month'), $hoje];
            case 'ano_atual':
                return [new DateTimeImmutable($hoje->format('Y') . '-01-01'), $hoje];
            case 'ano_anterior':
                $anoAnterior = (int)$hoje->format('Y') - 1;
                return [new DateTimeImmutable("{$anoAnterior}-01-01"), new DateTimeImmutable("{$anoAnterior}-12-31")];
            case '12m_anteriores':
                $inicio12m = $hoje->modify('first day of this month')->modify('-11 months');
                return [$inicio12m->modify('-12 months'), $inicio12m->modify('-1 day')];
            case 'mes':
                $ano = $this->anoValido($params['ano'] ?? '', (int)$hoje->format('Y'));
                $mes = $this->mesValido($params['mes'] ?? '', (int)$hoje->format('n'));
                $inicioMes = new DateTimeImmutable(sprintf('%04d-%02d-01', $ano, $mes));
                $fimMes = $inicioMes->modify('last day of this month');
                return [$inicioMes, $fimMes > $hoje ? $hoje : $fimMes];
            case 'ano_especifico':
                $ano = $this->anoValido($params['ano'] ?? '', (int)$hoje->format('Y'));
                $inicioAno = new DateTimeImmutable(sprintf('%04d-01-01', $ano));
                $fimAno = new DateTimeImmutable(sprintf('%04d-12-31', $ano));
                return [$inicioAno, $fimAno > $hoje ? $hoje : $fimAno];
            case 'personalizado':
                $inicioCustom = $this->dataValida($params['data_inicio'] ?? '');
                $fimCustom = $this->dataValida($params['data_fim'] ?? '');
                if ($inicioCustom === null || $fimCustom === null || $inicioCustom > $fimCustom) {
                    return $padrao();
                }
                return [$inicioCustom, $fimCustom > $hoje ? $hoje : $fimCustom];
            case '12m':
            default:
                return $padrao();
        }
    }

    /** Ano dentro de uma janela sensata (2015 até o ano atual); fora disso, cai no fallback. */
    private function anoValido(string $valor, int $fallback): int
    {
        if ($valor === '' || !ctype_digit($valor)) {
            return $fallback;
        }
        $ano = (int)$valor;
        $anoAtual = (int)(new DateTimeImmutable('today'))->format('Y');
        return ($ano >= 2015 && $ano <= $anoAtual) ? $ano : $fallback;
    }

    /** Mês 1-12; fora disso, cai no fallback. */
    private function mesValido(string $valor, int $fallback): int
    {
        if ($valor === '' || !ctype_digit($valor)) {
            return $fallback;
        }
        $mes = (int)$valor;
        return ($mes >= 1 && $mes <= 12) ? $mes : $fallback;
    }

    private function dataValida(string $valor): ?DateTimeImmutable
    {
        if ($valor === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($valor);
        } catch (Throwable) {
            return null;
        }
    }

}
