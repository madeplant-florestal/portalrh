<?php

/**
 * Dashboard da Entrevista de Desligamento (`/admin/dashboard-entrevista-desligamento`) — painel gerencial
 * AGREGADO. Autorização por permissão individual própria (`dashboard_entrevista_desligamento.visualizar`); Admin só
 * pelo bypass central, nunca por role. Não depende de `entrevista_desligamento.resultados` (o resultado individual
 * identificado continua sendo outro recurso).
 */
class AdminDashboardEntrevistaDesligamentoController extends Controller
{
    public function index(): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        Authorization::requirePermissao('dashboard_entrevista_desligamento.visualizar');

        $hoje = new DateTimeImmutable('today');
        $service = new DashboardEntrevistaDesligamentoService();

        $erro = null;
        $painel = null;
        $opcoes = ['unidades' => [], 'cargos' => []];
        $filtros = DashboardEntrevistaDesligamentoService::normalizarFiltros([], $hoje, $opcoes);
        try {
            $opcoes = $service->opcoesFiltro();
            $filtros = DashboardEntrevistaDesligamentoService::normalizarFiltros($_GET, $hoje, $opcoes);
            $painel = $service->montarPainel($filtros);
        } catch (Throwable $e) {
            Logger::exception($e, 'ERROR', ['controller' => 'AdminDashboardEntrevistaDesligamentoController']);
            $erro = 'Não foi possível carregar o Dashboard da Entrevista de Desligamento agora. Tente novamente em instantes.';
        }

        // Mesma fonte/convenção de "Última atualização" dos demais dashboards.
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

        $this->view->render('admin/dashboard-entrevista-desligamento', [
            'painel' => $painel,
            'erro' => $erro,
            'filtros' => $filtros,
            'opcoes' => $opcoes,
            'atalhos' => DashboardEntrevistaDesligamentoService::atalhos($hoje),
            'ultimaSincronizacao' => $ultimaSincronizacao,
        ], 'layouts/app-shell');
    }
}
