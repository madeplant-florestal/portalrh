<?php
class AdminRecruitmentWebhooksController extends Controller
{
    private RecruitmentWebhookAdminService $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = new RecruitmentWebhookAdminService();
    }

    public function index(): void
    {
        Auth::requireRole(['admin', 'rh']);
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

        $this->view->render('admin/recruitment_webhooks/index', [
            'setting' => $data['setting'],
            'queue' => $data['queue'],
            'lastProcessedAt' => $data['last_processed_at'],
            'history' => $data['history'],
            'csrf' => Security::csrfToken(),
            'flashError' => Security::sanitizeString($_GET['erro'] ?? ''),
            'flashSuccess' => Security::sanitizeString($_GET['ok'] ?? ''),
            'revealSecret' => $revealSecret,
            'testResult' => $testResult,
        ], 'layouts/admin');
    }

    public function saveSetting(): void
    {
        Auth::requireRole(['admin']);
        // TEMPORARIO (investigacao): log do POST bruto recebido. Remover apos concluir o diagnostico.
        Logger::info('[INVESTIGACAO SSRF] saveSetting POST recebido', [
            'tem_csrf' => isset($_POST['csrf']),
            'enabled_presente' => isset($_POST['enabled']),
            'webhook_url_bruto' => $_POST['webhook_url'] ?? null,
        ]);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            Logger::info('[INVESTIGACAO SSRF] saveSetting rejeitado por CSRF invalido');
            http_response_code(400);
            echo 'CSRF inválido';
            return;
        }

        try {
            $this->service->saveSetting([
                'enabled' => isset($_POST['enabled']) ? '1' : '0',
                'webhook_url' => trim((string)($_POST['webhook_url'] ?? '')),
            ]);
            Logger::info('[INVESTIGACAO SSRF] saveSetting concluido com sucesso');
            redirect('/admin/recruitment-webhooks?ok=' . urlencode('Configuração de webhook salva com sucesso.'));
        } catch (Throwable $e) {
            Logger::info('[INVESTIGACAO SSRF] saveSetting lancou excecao', [
                'classe' => get_class($e),
                'mensagem' => $e->getMessage(),
            ]);
            redirect('/admin/recruitment-webhooks?erro=' . urlencode($e->getMessage()));
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
            redirect('/admin/recruitment-webhooks?ok=' . urlencode('Segredo gerado com sucesso. Copie agora: ele não será exibido novamente.'));
        } catch (Throwable $e) {
            redirect('/admin/recruitment-webhooks?erro=' . urlencode($e->getMessage()));
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
            // TEMPORARIO (investigacao): log do estado lido no momento do teste. Remover apos concluir o diagnostico.
            Logger::info('[INVESTIGACAO SSRF] testWebhook iniciado');
            $result = $this->service->sendTestWebhook();
            Logger::info('[INVESTIGACAO SSRF] testWebhook resultado', ['result' => $result]);
            $_SESSION['flash_webhook_test_result'] = [
                'ok' => (bool)($result['ok'] ?? false),
                'status_code' => $result['status_code'] ?? null,
                'tested_at' => (new DateTimeImmutable())->format('d/m/Y H:i:s'),
                'error' => $result['error'] ?? null,
            ];
            $message = ($result['ok'] ?? false)
                ? 'Teste enviado com sucesso.'
                : 'Teste enviado, porém o destino respondeu com falha.';
            redirect('/admin/recruitment-webhooks?ok=' . urlencode($message));
        } catch (Throwable $e) {
            Logger::info('[INVESTIGACAO SSRF] testWebhook lancou excecao', [
                'classe' => get_class($e),
                'mensagem' => $e->getMessage(),
            ]);
            redirect('/admin/recruitment-webhooks?erro=' . urlencode($e->getMessage()));
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
            redirect('/admin/recruitment-webhooks?ok=' . urlencode($message));
        } catch (Throwable $e) {
            redirect('/admin/recruitment-webhooks?erro=' . urlencode($e->getMessage()));
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
            redirect('/admin/recruitment-webhooks?ok=' . urlencode($message));
        } catch (Throwable $e) {
            redirect('/admin/recruitment-webhooks?erro=' . urlencode($e->getMessage()));
        }
    }
}
