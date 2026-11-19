<?php

/**
 * Dashboard AGREGADO de Integração (Etapa 2C.2C) — a visão que o Gestor pode ter: só indicadores dos Setores Gerenciados, com as regras de
 * privacidade de DashboardIntegracaoAgregadoService (mínimo de 5 respondentes distintos, supressão complementar, sem comentários nem respostas
 * individuais). Distinto do dashboard OPERACIONAL (`AdminPesquisaIntegracaoResultadosController::dashboard`, integracao_colaborador.visualizar,
 * Admin/RH) e do QR/gestão de campanhas — o Gestor não acessa nenhum deles.
 *
 * Gate: role admin/rh/gestor + permissão `dashboard_integracao_agregado.visualizar` (Admin/RH pelo bypass). Viewer: 403 (role). O escopo vem SEMPRE do
 * banco (EscopoSetorial::resolver(id)), nunca da sessão nem de parâmetro.
 */
class AdminDashboardIntegracaoAgregadoController extends Controller
{
    private function painel(): array
    {
        $usuarioId = (int)($_SESSION['user_id'] ?? 0);
        $escopo = EscopoSetorial::resolver($usuarioId);
        return (new DashboardIntegracaoAgregadoService())->montar($_GET, DateHelper::hojeOperacional(), $escopo);
    }

    public function index(): void
    {
        Auth::requireRole(['admin', 'rh', 'gestor']);
        Authorization::requirePermissao('dashboard_integracao_agregado.visualizar');

        $erro = null;
        $painel = null;
        try {
            $painel = $this->painel();
        } catch (Throwable $e) {
            Logger::exception($e, 'ERROR', ['controller' => 'AdminDashboardIntegracaoAgregadoController']);
            $erro = 'Não foi possível carregar o Dashboard agora. Tente novamente em instantes.';
        }
        $this->view->render('admin/dashboard_integracao_agregado/index', [
            'painel' => $painel,
            'erro' => $erro,
            'hoje' => DateHelper::hojeOperacional(),
        ], 'layouts/app-shell');
    }

    /** Mesmo conteúdo em JSON (estrutura fechada, sem dado individual). */
    public function dados(): void
    {
        Auth::requireRole(['admin', 'rh', 'gestor']);
        Authorization::requirePermissao('dashboard_integracao_agregado.visualizar');

        header('Content-Type: application/json; charset=UTF-8');
        try {
            $painel = $this->painel();
            unset($painel['opcoes']); // só o necessário; as opções vêm da tela
            echo json_encode($painel, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            Logger::exception($e, 'ERROR', ['controller' => 'AdminDashboardIntegracaoAgregadoController']);
            http_response_code(500);
            echo json_encode(['erro' => 'Falha ao carregar o dashboard.'], JSON_UNESCAPED_UNICODE);
        }
    }
}
