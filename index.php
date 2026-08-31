<?php
require_once __DIR__ . '/app/core/bootstrap.php';

try {
    $cfg = Config::get();
    $baseUrl = (string)($cfg['app']['base_url'] ?? '');
    $basePath = (string)parse_url($baseUrl, PHP_URL_PATH);
    $basePath = rtrim($basePath, '/');
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    if ($scriptDir !== '' && $scriptDir !== '/' && strncmp($requestPath, $scriptDir, strlen($scriptDir)) === 0) {
        $requestPath = substr($requestPath, strlen($scriptDir)) ?: '/';
    }

    if ($requestPath === '/' || $requestPath === '') {
        redirect('/login');
    }

    $isAdminPath = strpos($requestPath, '/admin') === 0;
    $isAdminAuthRoute = preg_match('#^/admin/(login|logout|forgot-password|reset-password(?:/[^/]+)?)$#', $requestPath) === 1;
    if ($isAdminPath && !$isAdminAuthRoute && !Auth::check()) {
        redirect('/login');
    }

    $router = new Router($basePath);

    $router->get('/vagas', [HomeController::class, 'index']);
    $router->get('/vaga/{id}', [HomeController::class, 'vaga']);
    $router->post('/candidatar/{id}', [HomeController::class, 'candidatar']);

    $router->post('/api/check-cpf', [ApiController::class, 'checkCpf']);
    $router->post('/api/pipeline/move', [AdminPipelineController::class, 'move']);
    $router->post('/api/solicitacoes-vaga/move', [AdminSolicitacoesVagaKanbanController::class, 'move']);
    $router->post('/api/admin/usuarios/{id}/password', [AdminUsuariosController::class, 'adminChangePasswordApi']);

    $router->get('/login', [AuthController::class, 'login']);
    $router->post('/login', [AuthController::class, 'doLogin']);
    $router->get('/logout', [AuthController::class, 'logout']);
    $router->get('/forgot-password', [PasswordRecoveryController::class, 'requestForm']);
    $router->post('/forgot-password', [PasswordRecoveryController::class, 'sendToken']);
    $router->get('/reset-password/{token}', [PasswordRecoveryController::class, 'resetForm']);
    $router->post('/reset-password/{token}', [PasswordRecoveryController::class, 'performReset']);

    $router->get('/admin/login', [AuthController::class, 'login']);
    $router->post('/admin/login', [AuthController::class, 'doLogin']);
    $router->get('/admin/logout', [AuthController::class, 'logout']);
    $router->get('/admin/forgot-password', [PasswordRecoveryController::class, 'requestForm']);
    $router->post('/admin/forgot-password', [PasswordRecoveryController::class, 'sendToken']);
    $router->get('/admin/reset-password/{token}', [PasswordRecoveryController::class, 'resetForm']);
    $router->post('/admin/reset-password/{token}', [PasswordRecoveryController::class, 'performReset']);
    $router->get('/admin', [AdminController::class, 'index']);
    $router->get('/admin/indicadores-rh', [AdminRhIndicadoresController::class, 'index']);
    $router->get('/admin/colaboradores', [AdminColaboradoresController::class, 'index']);
    $router->post('/admin/colaboradores/importar', [AdminColaboradoresController::class, 'import']);
    $router->get('/admin/colaboradores/rh/editar/{id}', [AdminColaboradoresController::class, 'editRh']);
    $router->post('/admin/colaboradores/rh/editar/{id}', [AdminColaboradoresController::class, 'updateRh']);

    $router->get('/admin/avaliacoes', [AdminAvaliacoesController::class, 'index']);
    $router->get('/admin/avaliacoes/novo', [AdminAvaliacoesController::class, 'create']);
    $router->post('/admin/avaliacoes/novo', [AdminAvaliacoesController::class, 'store']);
    $router->get('/admin/avaliacoes/editar/{id}', [AdminAvaliacoesController::class, 'edit']);
    $router->post('/admin/avaliacoes/editar/{id}', [AdminAvaliacoesController::class, 'update']);
    $router->post('/admin/avaliacoes/excluir/{id}', [AdminAvaliacoesController::class, 'delete']);
    $router->get('/admin/manual', [AdminManualController::class, 'index']);
    $router->get('/admin/pipeline', [AdminPipelineController::class, 'index']);
    $router->get('/admin/recruitment-webhooks', [AdminRecruitmentWebhooksController::class, 'index']);
    $router->post('/admin/recruitment-webhooks/settings/save', [AdminRecruitmentWebhooksController::class, 'saveSetting']);
    $router->post('/admin/recruitment-webhooks/settings/regenerate-secret', [AdminRecruitmentWebhooksController::class, 'regenerateSecret']);
    $router->post('/admin/recruitment-webhooks/test', [AdminRecruitmentWebhooksController::class, 'testWebhook']);
    $router->post('/admin/recruitment-webhooks/process-pending', [AdminRecruitmentWebhooksController::class, 'processPending']);
    $router->post('/admin/recruitment-webhooks/events/{id}/retry', [AdminRecruitmentWebhooksController::class, 'retryEvent']);
    $router->get('/admin/empresas', [AdminEmpresasController::class, 'index']);
    $router->get('/admin/empresas/novo', [AdminEmpresasController::class, 'create']);
    $router->post('/admin/empresas/novo', [AdminEmpresasController::class, 'store']);
    $router->get('/admin/empresas/editar/{id}', [AdminEmpresasController::class, 'edit']);
    $router->post('/admin/empresas/editar/{id}', [AdminEmpresasController::class, 'update']);
    $router->post('/admin/empresas/excluir/{id}', [AdminEmpresasController::class, 'delete']);
    $router->get('/admin/setores', [AdminSetoresController::class, 'index']);
    $router->get('/admin/setores/export', [AdminSetoresController::class, 'export']);
    $router->get('/admin/setores/novo', [AdminSetoresController::class, 'create']);
    $router->post('/admin/setores/novo', [AdminSetoresController::class, 'store']);
    $router->get('/admin/setores/editar/{id}', [AdminSetoresController::class, 'edit']);
    $router->post('/admin/setores/editar/{id}', [AdminSetoresController::class, 'update']);
    $router->post('/admin/setores/excluir/{id}', [AdminSetoresController::class, 'delete']);
    $router->post('/admin/setores/saneamento/empresa', [AdminSetoresController::class, 'sanitizeLegacy']);
    $router->post('/admin/setores/{setorId}/cargos/vincular', [AdminCargoSetoresController::class, 'storeBySetor']);
    $router->post('/admin/setores/{setorId}/cargos/{cargoId}/desvincular', [AdminCargoSetoresController::class, 'destroyBySetor']);
    $router->get('/admin/cargos', [AdminCargosController::class, 'index']);
    $router->get('/admin/cargos/novo', [AdminCargosController::class, 'create']);
    $router->post('/admin/cargos/novo', [AdminCargosController::class, 'store']);
    $router->get('/admin/cargos/editar/{id}', [AdminCargosController::class, 'edit']);
    $router->post('/admin/cargos/editar/{id}', [AdminCargosController::class, 'update']);
    $router->post('/admin/cargos/excluir/{id}', [AdminCargosController::class, 'delete']);
    $router->post('/admin/cargos/{cargoId}/setores/vincular', [AdminCargoSetoresController::class, 'storeByCargo']);
    $router->post('/admin/cargos/{cargoId}/setores/{setorId}/desvincular', [AdminCargoSetoresController::class, 'destroyByCargo']);

    $router->get('/admin/vagas', [AdminVagasController::class, 'index']);
    $router->get('/admin/vagas/novo', [AdminVagasController::class, 'create']);
    $router->post('/admin/vagas/novo', [AdminVagasController::class, 'store']);
    $router->get('/admin/vagas/editar/{id}', [AdminVagasController::class, 'edit']);
    $router->post('/admin/vagas/editar/{id}', [AdminVagasController::class, 'update']);
    $router->post('/admin/vagas/excluir/{id}', [AdminVagasController::class, 'delete']);
    $router->get('/admin/solicitacoes-vaga', [AdminSolicitacoesVagaController::class, 'index']);
    $router->get('/admin/solicitacoes-vaga/kanban', [AdminSolicitacoesVagaKanbanController::class, 'index']);
    $router->get('/admin/solicitacoes-vaga/nova', [AdminSolicitacoesVagaController::class, 'create']);
    $router->post('/admin/solicitacoes-vaga/nova', [AdminSolicitacoesVagaController::class, 'store']);
    $router->get('/admin/solicitacoes-vaga/{id}', [AdminSolicitacoesVagaController::class, 'show']);
    $router->post('/admin/solicitacoes-vaga/{id}/aprovar-lider', [AdminSolicitacoesVagaController::class, 'approveLeader']);
    $router->post('/admin/solicitacoes-vaga/{id}/aprovar-rh', [AdminSolicitacoesVagaController::class, 'approveRh']);
    $router->post('/admin/solicitacoes-vaga/{id}/controle-rh', [AdminSolicitacoesVagaController::class, 'updateRh']);
    $router->post('/admin/solicitacoes-vaga/{id}/anotacao', [AdminSolicitacoesVagaController::class, 'addNota']);
    $router->get('/admin/movimentacoes-pessoal', [AdminMovimentacoesPessoalController::class, 'index']);
    $router->get('/admin/movimentacoes-pessoal/nova', [AdminMovimentacoesPessoalController::class, 'create']);
    $router->post('/admin/movimentacoes-pessoal/nova', [AdminMovimentacoesPessoalController::class, 'store']);
    $router->get('/admin/movimentacoes-pessoal/{id}', [AdminMovimentacoesPessoalController::class, 'show']);
    $router->post('/admin/movimentacoes-pessoal/{id}/editar', [AdminMovimentacoesPessoalController::class, 'update']);
    $router->post('/admin/movimentacoes-pessoal/{id}/assinar-rh', [AdminMovimentacoesPessoalController::class, 'signRh']);

    $router->get('/admin/candidaturas', [AdminCandidaturasController::class, 'index']);
    $router->get('/admin/candidaturas/{id}', [AdminCandidaturasController::class, 'show']);
    $router->get('/admin/candidaturas/{id}/download', [AdminCandidaturasController::class, 'download']);
    $router->post('/admin/candidaturas/{id}/atualizar', [AdminCandidaturasController::class, 'update']);
    $router->post('/admin/candidaturas/{id}/indicacao', [AdminCandidaturasController::class, 'updateIndicacao']);
    $router->get('/admin/indicacoes', [AdminIndicacoesController::class, 'index']);
    $router->get('/admin/indicacoes/export', [AdminIndicacoesController::class, 'export']);
    $router->post('/admin/indicacoes/{id}/pagar', [AdminIndicacoesController::class, 'markPago']);
    $router->post('/admin/indicacoes/{id}/pagar/editar-data', [AdminIndicacoesController::class, 'updatePaymentDate']);
    $router->get('/api/indicacoes/{id}/status', [AdminIndicacoesController::class, 'statusApi']);
    $router->get('/api/financeiro/contas-receber/indicacoes', [AdminIndicacoesController::class, 'contasReceberApi']);
    $router->get('/api/financeiro/conciliacao/indicacoes', [AdminIndicacoesController::class, 'conciliacaoApi']);
    $router->get('/api/financeiro/relatorios/indicacoes', [AdminIndicacoesController::class, 'relatoriosFinanceirosApi']);

    $router->get('/admin/beneficios', [AdminBeneficiosController::class, 'index']);
    $router->get('/admin/beneficios/novo', [AdminBeneficiosController::class, 'create']);
    $router->post('/admin/beneficios/novo', [AdminBeneficiosController::class, 'store']);
    $router->get('/admin/beneficios/editar/{id}', [AdminBeneficiosController::class, 'edit']);
    $router->post('/admin/beneficios/editar/{id}', [AdminBeneficiosController::class, 'update']);
    $router->post('/admin/beneficios/excluir/{id}', [AdminBeneficiosController::class, 'delete']);

    $router->get('/admin/usuarios', [AdminUsuariosController::class, 'index']);
    $router->get('/admin/usuarios/novo', [AdminUsuariosController::class, 'create']);
    $router->get('/admin/usuarios/{id}', [AdminUsuariosController::class, 'show']);
    $router->post('/admin/usuarios/novo', [AdminUsuariosController::class, 'store']);
    $router->post('/admin/usuarios/supervisor/garantir', [AdminSupervisorController::class, 'ensure']);
    $router->post('/admin/usuarios/{id}/role', [AdminUsuariosController::class, 'updateRole']);
    $router->post('/admin/usuarios/{id}/status', [AdminUsuariosController::class, 'updateStatus']);
    $router->post('/admin/usuarios/{id}/excluir', [AdminUsuariosController::class, 'delete']);

    $router->dispatch();
} catch (\Throwable $e) {
    error_log((string)$e);
    http_response_code(500);
    echo "Erro interno do sistema.";
}
