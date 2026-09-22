<?php

/**
 * Central do Portal RH (Nova UI) — a HOME do Portal: `GET /admin`. Primeira tela no AppShell V2.
 *
 * Não concede acesso: qualquer sessão autenticada (mesmo gate de `AdminManualController`) vê a página, e os cards vêm de
 * `PortalNavegacaoService` — a fonte oficial da navegação do Portal. Cada rota de destino continua protegida no próprio backend.
 * Sem permissão nova, sem migration. O dashboard que ocupava `/admin` (People Analytics) agora é `/admin/dashboard`
 * (AdminController::index, com o mesmo gate `dashboard.visualizar`). É também o destino pós-login.
 */
class AdminCentralController extends Controller
{
    public function index(): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        $this->view->render('admin/central', [
            'tituloPagina' => 'Central do Portal RH',
            'modulos' => (new PortalNavegacaoService())->modulos(),
        ], 'layouts/app-shell');
    }
}
