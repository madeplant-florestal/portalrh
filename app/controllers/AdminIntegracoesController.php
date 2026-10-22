<?php

/**
 * Configurações → Integrações (2026-10) — nova área administrativa genérica para integrações do
 * Portal. Primeiro ocupante: a administração da integração de Webhooks do Recrutamento com o n8n,
 * realocada de `/admin/recruitment-webhooks` (ver auditoria técnica da Etapa 1 e a Etapa 2 desta
 * sprint). Reaproveita integralmente `RecruitmentWebhookAdminService` e as classes de
 * infraestrutura de webhook (fila, HMAC, HTTP client, URL guard) — nenhuma delas foi alterada
 * nesta etapa; a limpeza de nomenclatura (`Recruitment*`) e a permissão
 * `recruitment_webhooks.visualizar` ficam para uma etapa futura dedicada a isso (débito técnico
 * documentado, não um esquecimento).
 */
class AdminIntegracoesController extends Controller
{
    private RecruitmentWebhookAdminService $service;
    private IntegracaoRegraComunicacaoRepository $regraRepository;
    private IntegracaoRegraComunicacaoService $regraService;

    public function __construct()
    {
        parent::__construct();
        $this->service = new RecruitmentWebhookAdminService();
        $this->regraRepository = new IntegracaoRegraComunicacaoRepository();
        $this->regraService = new IntegracaoRegraComunicacaoService($this->regraRepository);
    }

    public function index(): void
    {
        // Entrada (somente leitura): role admin/rh (como sempre) OU a permissão individual recruitment_webhooks.visualizar. Salvar,
        // regenerar segredo, testar, reprocessar e reenviar seguem com seus gates próprios, inalterados.
        Authorization::requireRoleOuPermissao(['admin', 'rh'], 'recruitment_webhooks.visualizar');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $data = $this->service->dashboardData($page);

        $revealSecret = null;
        if (!empty($_SESSION['flash_webhook_secret'])) {
            $revealSecret = $_SESSION['flash_webhook_secret'];
            unset($_SESSION['flash_webhook_secret']);
        }

        $testResult = null;
        if (!empty($_SESSION['flash_webhook_test_result'])) {
            $testResult = $_SESSION['flash_webhook_test_result'];
            unset($_SESSION['flash_webhook_test_result']);
        }

        $this->view->render('admin/configuracoes/integracoes/index', [
            'setting' => $data['setting'],
            'queue' => $data['queue'],
            'lastProcessedAt' => $data['last_processed_at'],
            'history' => $data['history'],
            'csrf' => Security::csrfToken(),
            'flashError' => Security::sanitizeString($_GET['erro'] ?? ''),
            'flashSuccess' => Security::sanitizeString($_GET['ok'] ?? ''),
            'revealSecret' => $revealSecret,
            'testResult' => $testResult,
            // Só exibição: testar/reprocessar/reenviar = admin/rh; salvar configuração e regenerar segredo = admin. Quem entrou só pela
            // permissão recruitment_webhooks.visualizar vê a tela em modo leitura (sem formulários de ação).
            'podeOperar' => !empty($_SESSION['user_is_supervisor']) || in_array(Auth::role(), ['admin', 'rh'], true),
            // Regras de comunicação (Etapa 3) — mesma tela, nova seção; mesmo gate de leitura/operação do restante da página.
            'regras' => $this->regraRepository->all(),
            'eventosRegra' => IntegracaoRegraComunicacaoService::eventosDisponiveis(),
        ], 'layouts/app-shell');
    }

    public function novaRegraComunicacao(): void
    {
        Auth::requireRole(['admin', 'rh']);
        $this->renderFormularioRegra('create', null, '');
    }

    public function criarRegraComunicacao(): void
    {
        Auth::requireRole(['admin', 'rh']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'CSRF inválido';
            return;
        }

        $resultado = $this->regraService->salvar($_POST);
        if (!($resultado['ok'] ?? false)) {
            $this->renderFormularioRegra('create', $_POST, (string)($resultado['error'] ?? 'Falha ao salvar a regra.'));
            return;
        }
        redirect('/admin/configuracoes/integracoes?ok=' . urlencode('Regra de comunicação cadastrada com sucesso.'));
    }

    public function editarRegraComunicacao(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        $regra = $this->regraRepository->find((int)$id);
        if (!$regra) {
            http_response_code(404);
            echo 'Regra não encontrada';
            return;
        }
        $this->renderFormularioRegra('edit', $regra, '');
    }

    public function atualizarRegraComunicacao(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'CSRF inválido';
            return;
        }

        $existing = $this->regraRepository->find((int)$id);
        if (!$existing) {
            http_response_code(404);
            echo 'Regra não encontrada';
            return;
        }

        $resultado = $this->regraService->salvar($_POST, (int)$id);
        if (!($resultado['ok'] ?? false)) {
            $dadosForm = $_POST;
            $dadosForm['id'] = (int)$id;
            $this->renderFormularioRegra('edit', $dadosForm, (string)($resultado['error'] ?? 'Falha ao atualizar a regra.'));
            return;
        }
        redirect('/admin/configuracoes/integracoes?ok=' . urlencode('Regra de comunicação atualizada com sucesso.'));
    }

    private function renderFormularioRegra(string $mode, ?array $regra, string $error): void
    {
        $this->view->render('admin/configuracoes/integracoes/regra-form', [
            'mode' => $mode,
            'regra' => $regra,
            'csrf' => Security::csrfToken(),
            'error' => $error,
            'eventos' => IntegracaoRegraComunicacaoService::eventosDisponiveis(),
            'etapas' => PipelineStage::all(),
            'mensagensAtivas' => array_values(array_filter(
                Mensagem::all(),
                static fn(array $m): bool => (int)($m['ativo'] ?? 0) === 1
            )),
        ], 'layouts/app-shell');
    }

    public function saveSetting(): void
    {
        Auth::requireRole(['admin']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'CSRF inválido';
            return;
        }

        try {
            $this->service->saveSetting([
                'enabled' => isset($_POST['enabled']) ? '1' : '0',
                'webhook_url' => trim((string)($_POST['webhook_url'] ?? '')),
            ]);
            redirect('/admin/configuracoes/integracoes?ok=' . urlencode('Configuração de webhook salva com sucesso.'));
        } catch (Throwable $e) {
            redirect('/admin/configuracoes/integracoes?erro=' . urlencode($e->getMessage()));
        }
    }

    public function regenerateSecret(): void
    {
        Auth::requireRole(['admin']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'CSRF inválido';
            return;
        }

        try {
            $secret = $this->service->regenerateSecret();
            // Segredo exibido apenas uma vez, via sessão (nunca via querystring/log) — ver index().
            $_SESSION['flash_webhook_secret'] = ['secret' => $secret];
            redirect('/admin/configuracoes/integracoes?ok=' . urlencode('Segredo gerado com sucesso. Copie agora: ele não será exibido novamente.'));
        } catch (Throwable $e) {
            redirect('/admin/configuracoes/integracoes?erro=' . urlencode($e->getMessage()));
        }
    }

    public function testWebhook(): void
    {
        Auth::requireRole(['admin', 'rh']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'CSRF inválido';
            return;
        }

        try {
            $result = $this->service->sendTestWebhook();
            $_SESSION['flash_webhook_test_result'] = [
                'ok' => (bool)($result['ok'] ?? false),
                'status_code' => $result['status_code'] ?? null,
                'tested_at' => (new DateTimeImmutable())->format('d/m/Y H:i:s'),
                'error' => $result['error'] ?? null,
            ];
            $message = ($result['ok'] ?? false)
                ? 'Teste enviado com sucesso.'
                : 'Teste enviado, porém o destino respondeu com falha.';
            redirect('/admin/configuracoes/integracoes?ok=' . urlencode($message));
        } catch (Throwable $e) {
            redirect('/admin/configuracoes/integracoes?erro=' . urlencode($e->getMessage()));
        }
    }

    public function retryEvent(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'CSRF inválido';
            return;
        }

        try {
            $result = $this->service->retryEvent((int)$id);
            $message = ($result['ok'] ?? false)
                ? 'Evento reenviado com sucesso.'
                : 'Reenvio executado, porém o evento permaneceu com falha.';
            redirect('/admin/configuracoes/integracoes?ok=' . urlencode($message));
        } catch (Throwable $e) {
            redirect('/admin/configuracoes/integracoes?erro=' . urlencode($e->getMessage()));
        }
    }

    public function processPending(): void
    {
        Auth::requireRole(['admin', 'rh']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'CSRF inválido';
            return;
        }

        try {
            $result = $this->service->processPending(25);
            $message = sprintf(
                'Fila processada: %d entregue(s), %d com falha.',
                (int)($result['processed'] ?? 0),
                (int)($result['failed'] ?? 0)
            );
            redirect('/admin/configuracoes/integracoes?ok=' . urlencode($message));
        } catch (Throwable $e) {
            redirect('/admin/configuracoes/integracoes?erro=' . urlencode($e->getMessage()));
        }
    }
}
