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

    // Pesquisa de Experiência do Candidato — página pública, sem login, acessada por token
    // aleatório (nunca sequencial/derivado de CPF). Ver PesquisaExperienciaController.
    $router->get('/experiencia/{token}', [PesquisaExperienciaController::class, 'show']);
    $router->post('/experiencia/{token}', [PesquisaExperienciaController::class, 'store']);

    // Pesquisa de Integração (onboarding) do Colaborador — página pública, sem login, acessada
    // por token aleatório (nunca sequencial/derivado de CPF/ID/e-mail). Ver
    // PesquisaIntegracaoController. Distinta de /experiencia (processo seletivo).
    $router->get('/integracao/{token}', [PesquisaIntegracaoController::class, 'show']);
    $router->post('/integracao/{token}', [PesquisaIntegracaoController::class, 'store']);

    // Pesquisa de Integração — fluxo COLETIVO via QR Code único e reutilizável (CPF + data de
    // nascimento -> contrato oficial -> pesquisa). Rotas estáticas: o Router as resolve ANTES de
    // `/integracao/{token}`, que continua atendendo os links individuais já distribuídos.
    // Ver PesquisaIntegracaoQrController.
    $router->get('/integracao', [PesquisaIntegracaoQrController::class, 'inicio']);
    $router->post('/integracao/identificar', [PesquisaIntegracaoQrController::class, 'identificar']);
    $router->get('/integracao/confirmar', [PesquisaIntegracaoQrController::class, 'confirmar']);
    $router->post('/integracao/confirmar', [PesquisaIntegracaoQrController::class, 'confirmarSelecao']);
    $router->get('/integracao/responder', [PesquisaIntegracaoQrController::class, 'responder']);
    $router->post('/integracao/responder', [PesquisaIntegracaoQrController::class, 'enviar']);
    $router->get('/integracao/obrigado', [PesquisaIntegracaoQrController::class, 'obrigado']);

    // Pesquisa de Reação — Treinamento de Integração — página pública, sem login, acessada por
    // token aleatório. Instrumento DIFERENTE de /integracao (não é a mesma pesquisa/tabela): 1
    // Campanha -> N Respostas, participação anônima opcional. Ver PesquisaReacaoIntegracaoController.
    $router->get('/pesquisa-reacao/{token}', [PesquisaReacaoIntegracaoController::class, 'show']);
    $router->post('/pesquisa-reacao/{token}', [PesquisaReacaoIntegracaoController::class, 'store']);

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
    $router->get('/admin/dashboard-recrutamento', [AdminDashboardRecrutamentoController::class, 'index']);
    // Sincronização operacional do METADADOS sob demanda (Etapa 1). O Portal só aciona a camada
    // de orquestração interna e consulta o andamento — nunca acessa o SQL Server. Ver
    // AdminMetadadosSyncController e docs/claude/roadmap-tecnico.md.
    $router->post('/admin/indicadores-rh/sincronizar', [AdminMetadadosSyncController::class, 'solicitar']);
    $router->get('/admin/indicadores-rh/sincronizar/status', [AdminMetadadosSyncController::class, 'status']);
    $router->get('/admin/colaboradores', [AdminColaboradoresController::class, 'index']);
    // Materialização sob demanda da extensão local de um contrato oficial — corrige o bloqueio
    // "Sem extensão local" (idempotente, nunca por CPF, nunca escreve em colaboradores_metadados).
    $router->post('/admin/colaboradores/materializar/{metadadosId}', [AdminColaboradoresController::class, 'materializarExtensaoLocal']);
    $router->get('/admin/colaboradores/rh/editar/{id}', [AdminColaboradoresController::class, 'editRh']);
    $router->post('/admin/colaboradores/rh/editar/{id}', [AdminColaboradoresController::class, 'updateRh']);
    // Acesso / Liderança do colaborador (sprint Solicitação/Publicação de Vagas): vincula o
    // usuário de login e define os papéis do Portal (é líder / pode solicitar vaga / líder imediato).
    $router->get('/admin/colaboradores/{id}/acesso', [AdminColaboradoresController::class, 'acesso']);
    $router->post('/admin/colaboradores/{id}/acesso', [AdminColaboradoresController::class, 'updateAcesso']);
    // Integração (onboarding) do colaborador — Sprint "Integração do Colaborador". Dado
    // operacional do Portal, nunca escrito no METADADOS.
    $router->post('/admin/colaboradores/{id}/integracao', [AdminColaboradoresController::class, 'updateIntegracao']);
    // Geração da Pesquisa de Integração (onboarding) — Sprint "Fundação do Dashboard de
    // Integração". Só permitida quando a integração já está 'realizada'.
    $router->post('/admin/colaboradores/{id}/pesquisa-integracao/gerar', [AdminColaboradoresController::class, 'gerarPesquisaIntegracao']);

    $router->get('/admin/avaliacoes', [AdminAvaliacoesController::class, 'index']);
    $router->get('/admin/avaliacoes/novo', [AdminAvaliacoesController::class, 'create']);
    $router->post('/admin/avaliacoes/novo', [AdminAvaliacoesController::class, 'store']);
    $router->get('/admin/avaliacoes/editar/{id}', [AdminAvaliacoesController::class, 'edit']);
    $router->post('/admin/avaliacoes/editar/{id}', [AdminAvaliacoesController::class, 'update']);
    $router->post('/admin/avaliacoes/excluir/{id}', [AdminAvaliacoesController::class, 'delete']);
    $router->get('/admin/manual', [AdminManualController::class, 'index']);
    $router->get('/admin/pipeline', [AdminPipelineController::class, 'index']);
    // Máquina-a-máquina, autenticado via HMAC (nunca sessão/CSRF) — ver
    // InternalMetadadosSyncController e docs/claude/roadmap-tecnico.md (Fase 4 - sincronização
    // segura de produção). Fora do prefixo /admin de propósito: não é tela administrativa.
    $router->post('/internal/metadados/colaboradores/sync', [InternalMetadadosSyncController::class, 'sync']);
    // Fase 5.1A — Estrutura Organizacional: Empresas (RHEMPRESAS) e Unidades (RHUNIDADES) como
    // dimensões oficiais sincronizadas. Mesma auth HMAC, mesmo padrão de resposta.
    $router->post('/internal/metadados/empresas/sync', [InternalMetadadosSyncController::class, 'empresas']);
    $router->post('/internal/metadados/unidades/sync', [InternalMetadadosSyncController::class, 'unidades']);
    // Fase 5.2 — Setores (RHSETORES) e Cargos (RHCARGOS) como dimensões oficiais. Chave global,
    // código string opaca. Mesma auth HMAC.
    $router->post('/internal/metadados/setores/sync', [InternalMetadadosSyncController::class, 'setores']);
    $router->post('/internal/metadados/cargos/sync', [InternalMetadadosSyncController::class, 'cargos']);

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
    // Publica um rascunho de vaga (originado de Solicitação de Vaga aprovada) — a partir daí
    // aparece na página pública.
    $router->post('/admin/vagas/{id}/publicar', [AdminVagasController::class, 'publicar']);
    $router->get('/admin/solicitacoes-vaga', [AdminSolicitacoesVagaController::class, 'index']);
    $router->get('/admin/solicitacoes-vaga/kanban', [AdminSolicitacoesVagaKanbanController::class, 'index']);
    $router->get('/admin/solicitacoes-vaga/nova', [AdminSolicitacoesVagaController::class, 'create']);
    $router->post('/admin/solicitacoes-vaga/nova', [AdminSolicitacoesVagaController::class, 'store']);
    $router->get('/admin/solicitacoes-vaga/solicitante-contexto/{usuarioId}', [AdminSolicitacoesVagaController::class, 'solicitanteContexto']);
    $router->get('/admin/solicitacoes-vaga/{id}', [AdminSolicitacoesVagaController::class, 'show']);
    $router->post('/admin/solicitacoes-vaga/{id}/aprovar-lider', [AdminSolicitacoesVagaController::class, 'approveLeader']);
    $router->post('/admin/solicitacoes-vaga/{id}/aprovar-rh', [AdminSolicitacoesVagaController::class, 'approveRh']);
    $router->post('/admin/solicitacoes-vaga/{id}/controle-rh', [AdminSolicitacoesVagaController::class, 'updateRh']);
    $router->post('/admin/solicitacoes-vaga/{id}/anotacao', [AdminSolicitacoesVagaController::class, 'addNota']);
    // Rede de segurança / disparo manual da geração do rascunho da vaga (solicitação já aprovada).
    $router->post('/admin/solicitacoes-vaga/{id}/gerar-vaga', [AdminSolicitacoesVagaController::class, 'gerarVaga']);
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

    $router->get('/admin/mensagens', [AdminMensagensController::class, 'index']);
    $router->get('/admin/mensagens/novo', [AdminMensagensController::class, 'create']);
    $router->post('/admin/mensagens/novo', [AdminMensagensController::class, 'store']);
    $router->get('/admin/mensagens/editar/{id}', [AdminMensagensController::class, 'edit']);
    $router->post('/admin/mensagens/editar/{id}', [AdminMensagensController::class, 'update']);

    $router->get('/admin/pesquisas-reacao-integracao', [AdminPesquisaReacaoIntegracaoController::class, 'index']);
    $router->post('/admin/pesquisas-reacao-integracao', [AdminPesquisaReacaoIntegracaoController::class, 'store']);
    $router->post('/admin/pesquisas-reacao-integracao/{id}/desativar', [AdminPesquisaReacaoIntegracaoController::class, 'desativar']);
    $router->get('/admin/pesquisas-reacao-integracao/{id}/resultados', [AdminPesquisaReacaoIntegracaoController::class, 'resultados']);

    $router->get('/admin/pesquisa-integracao-qr', [AdminPesquisaIntegracaoQrController::class, 'index']);
    $router->post('/admin/pesquisa-integracao-qr/sessao', [AdminPesquisaIntegracaoQrController::class, 'abrir']);
    $router->post('/admin/pesquisa-integracao-qr/sessao/{id}/encerrar', [AdminPesquisaIntegracaoQrController::class, 'encerrar']);

    $router->get('/admin/usuarios', [AdminUsuariosController::class, 'index']);
    $router->get('/admin/usuarios/novo', [AdminUsuariosController::class, 'create']);
    $router->get('/admin/usuarios/metadados/buscar', [AdminUsuariosController::class, 'buscarMetadados']);
    $router->get('/admin/usuarios/{id}', [AdminUsuariosController::class, 'show']);
    $router->post('/admin/usuarios/novo', [AdminUsuariosController::class, 'store']);
    $router->post('/admin/usuarios/supervisor/garantir', [AdminSupervisorController::class, 'ensure']);
    $router->post('/admin/usuarios/{id}/role', [AdminUsuariosController::class, 'updateRole']);
    $router->post('/admin/usuarios/{id}/status', [AdminUsuariosController::class, 'updateStatus']);
    $router->post('/admin/usuarios/{id}/vaga-acesso', [AdminUsuariosController::class, 'updateVagaAcesso']);
    $router->post('/admin/usuarios/{id}/metadados-vinculo', [AdminUsuariosController::class, 'vincularMetadados']);
    $router->post('/admin/usuarios/{id}/contexto-organizacional', [AdminUsuariosController::class, 'updateContextoOrganizacional']);
    $router->post('/admin/usuarios/{id}/permissoes', [AdminUsuariosController::class, 'updatePermissoes']);
    $router->post('/admin/usuarios/{id}/excluir', [AdminUsuariosController::class, 'delete']);

    $router->dispatch();
} catch (\Throwable $e) {
    error_log((string)$e);
    http_response_code(500);
    echo "Erro interno do sistema.";
}
