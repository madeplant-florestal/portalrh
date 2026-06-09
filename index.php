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
    $router->get('/admin/colaboradores', [AdminColaboradoresController::class, 'index']);
    $router->get('/admin/manual', [AdminManualController::class, 'index']);
    $router->get('/admin/pipeline', [AdminPipelineController::class, 'index']);

    $router->get('/admin/vagas', [AdminVagasController::class, 'index']);
    $router->get('/admin/vagas/novo', [AdminVagasController::class, 'create']);
    $router->post('/admin/vagas/novo', [AdminVagasController::class, 'store']);
    $router->get('/admin/vagas/editar/{id}', [AdminVagasController::class, 'edit']);
    $router->post('/admin/vagas/editar/{id}', [AdminVagasController::class, 'update']);
    $router->post('/admin/vagas/excluir/{id}', [AdminVagasController::class, 'delete']);

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
    error_log($e->getMessage());
    http_response_code(500);
    echo "Erro interno do sistema.";
}
