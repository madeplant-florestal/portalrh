<?php

/**
 * Dashboard de Turnover (`/admin/dashboard-turnover`) — seis análises sobre os contratos oficiais
 * do METADADOS (espelho `colaboradores_metadados`). Autorização por permissão individual
 * (`dashboard_turnover.visualizar`); Admin só pelo bypass central, nunca por role.
 */
class AdminDashboardTurnoverController extends Controller
{
    public function index(): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        Authorization::requirePermissao('dashboard_turnover.visualizar');

        $hoje = new DateTimeImmutable('today');
        $service = new TurnoverDashboardService();

        $erro = null;
        $painel = null;
        $anos = [(int)$hoje->format('Y')];
        $opcoesFiltro = ['empresas' => [], 'cargos' => []];
        try {
            $anos = $service->anosDisponiveis($hoje);
            $opcoesFiltro = $service->opcoesFiltro();
        } catch (Throwable $e) {
            Logger::exception($e, 'ERROR', ['controller' => 'AdminDashboardTurnoverController']);
            $erro = 'Não foi possível carregar o Dashboard de Turnover agora. Tente novamente em instantes.';
        }

        $anoSolicitado = ctype_digit((string)($_GET['ano'] ?? '')) ? (int)$_GET['ano'] : 0;
        $anoSelecionado = in_array($anoSolicitado, $anos, true) ? $anoSolicitado : max($anos);

        $filtros = [
            'codigo_empresa' => Security::sanitizeString($_GET['empresa'] ?? ''),
            'codigo_cargo' => Security::sanitizeString($_GET['cargo'] ?? ''),
        ];
        $filtros = array_filter($filtros, static fn(string $v): bool => $v !== '');

        if ($erro === null) {
            try {
                $painel = $service->montarPainel($filtros, $anoSelecionado, $hoje);
            } catch (Throwable $e) {
                Logger::exception($e, 'ERROR', ['controller' => 'AdminDashboardTurnoverController']);
                $erro = 'Não foi possível carregar o Dashboard de Turnover agora. Tente novamente em instantes.';
            }
        }

        // Mesma fonte/convenção de "Última atualização" do People Analytics e de Indicadores de RH.
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

        $this->view->render('admin/dashboard-turnover', [
            'painel' => $painel,
            'erro' => $erro,
            'anos' => $anos,
            'anoSelecionado' => $anoSelecionado,
            'opcoesFiltro' => $opcoesFiltro,
            'filtrosSelecionados' => [
                'empresa' => $filtros['codigo_empresa'] ?? '',
                'cargo' => $filtros['codigo_cargo'] ?? '',
            ],
            'hoje' => $hoje,
            'ultimaSincronizacao' => $ultimaSincronizacao,
        ], 'layouts/admin');
    }
}
