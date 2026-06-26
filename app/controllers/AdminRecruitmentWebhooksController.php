<?php
class AdminRecruitmentWebhooksController extends Controller
{
    private RecruitmentWebhookAdminService $service;

    public function __construct()
    {
        $this->service = new RecruitmentWebhookAdminService();
    }

    public function index(): void
    {
        Auth::requireRole(['admin', 'rh']);
        $page = max(1, (int)($_GET['page'] ?? 1));
        $data = $this->service->dashboardData($page);

        $this->view->render('admin/recruitment_webhooks/index', [
            'settings' => $data['settings'],
            'history' => $data['history'],
            'csrf' => Security::csrfToken(),
            'flashError' => Security::sanitizeString($_GET['erro'] ?? ''),
            'flashSuccess' => Security::sanitizeString($_GET['ok'] ?? ''),
        ], 'layouts/admin');
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
                'scope_key' => Security::sanitizeString($_POST['scope_key'] ?? ''),
                'empresa_id' => $_POST['empresa_id'] ?? null,
                'enabled' => isset($_POST['enabled']) ? '1' : '0',
                'webhook_url' => trim((string)($_POST['webhook_url'] ?? '')),
            ]);
            redirect('/admin/recruitment-webhooks?ok=' . urlencode('Configuração de webhook salva com sucesso.'));
        } catch (Throwable $e) {
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
