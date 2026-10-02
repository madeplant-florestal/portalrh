<?php

/**
 * Detalhamento administrativo dos resultados da Pesquisa de Integração respondida via QR Code, por
 * data de integração. A URL vive sob a central `/admin/pesquisas-reacao-integracao`, mas o domínio
 * de permissão é o da Integração (`integracao_colaborador.visualizar`) — possuir só a permissão da
 * Pesquisa de Reação NÃO dá acesso a estes dados.
 */
class AdminPesquisaIntegracaoResultadosController extends Controller
{
    public function resultados(string $data): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        Authorization::requirePermissao('integracao_colaborador.visualizar');

        $dataYmd = PesquisaIntegracaoResultadosService::dataValida($data);
        if ($dataYmd === null) {
            http_response_code(404);
            echo 'Integração não encontrada.';
            return;
        }

        $this->view->render('admin/pesquisa_integracao_qr/resultados', [
            'resultados' => PesquisaIntegracaoResultadosService::resultadosDaIntegracao($dataYmd),
        ], 'layouts/app-shell');
    }

    /**
     * Dashboard gerencial AGREGADO da Integração/Onboarding (Etapa 8, 2026-10) — mesma permissão do
     * detalhamento (`integracao_colaborador.visualizar`): é a mesma área funcional, uma visão mais
     * ampla dos mesmos dados, não uma capacidade nova.
     */
    public function dashboard(): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        Authorization::requirePermissao('integracao_colaborador.visualizar');

        $hoje = new DateTimeImmutable('today');
        $service = new DashboardIntegracaoService();
        $erro = null;
        $painel = null;
        $opcoes = ['empresas' => [], 'unidades' => [], 'setores' => [], 'gestores' => []];
        $filtros = DashboardIntegracaoService::normalizarFiltros([], $hoje, $opcoes);
        try {
            $opcoes = $service->opcoesFiltro();
            $filtros = DashboardIntegracaoService::normalizarFiltros($_GET, $hoje, $opcoes);
            $painel = $service->montarPainel($filtros);
        } catch (Throwable $e) {
            Logger::exception($e, 'ERROR', ['controller' => 'AdminPesquisaIntegracaoResultadosController']);
            $erro = 'Não foi possível carregar o Dashboard de Integração agora. Tente novamente em instantes.';
        }

        $this->view->render('admin/pesquisa_integracao_qr/dashboard', [
            'painel' => $painel,
            'erro' => $erro,
            'filtros' => $filtros,
            'opcoes' => $opcoes,
            'atalhos' => DashboardIntegracaoService::atalhos($hoje),
            'hoje' => $hoje,
        ], 'layouts/app-shell');
    }
}
