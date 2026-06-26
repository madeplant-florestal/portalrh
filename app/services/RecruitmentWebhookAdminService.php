<?php
class RecruitmentWebhookAdminService
{
    private RecruitmentWebhookSettingRepository $settingRepository;
    private WebhookEventRepository $eventRepository;
    private RecruitmentWebhookDeliveryService $deliveryService;
    private EmpresaRepository $empresaRepository;

    public function __construct(
        ?RecruitmentWebhookSettingRepository $settingRepository = null,
        ?WebhookEventRepository $eventRepository = null,
        ?RecruitmentWebhookDeliveryService $deliveryService = null,
        ?EmpresaRepository $empresaRepository = null
    ) {
        $this->settingRepository = $settingRepository ?? new RecruitmentWebhookSettingRepository();
        $this->eventRepository = $eventRepository ?? new WebhookEventRepository();
        $this->deliveryService = $deliveryService ?? new RecruitmentWebhookDeliveryService($this->eventRepository);
        $this->empresaRepository = $empresaRepository ?? new EmpresaRepository();
    }

    public function dashboardData(int $page = 1): array
    {
        $history = $this->eventRepository->paginateAdmin($page, 25);
        $items = [];

        foreach ($history['items'] as $item) {
            $payload = json_decode((string)($item['payload_json'] ?? ''), true);
            $item['payload'] = is_array($payload) ? $payload : [];
            $items[] = $item;
        }

        $history['items'] = $items;

        return [
            'settings' => $this->settingRepository->adminRows(),
            'companies' => $this->empresaRepository->allOptions(),
            'history' => $history,
        ];
    }

    public function saveSetting(array $input): void
    {
        $scopeKey = trim((string)($input['scope_key'] ?? ''));
        if ($scopeKey === '') {
            throw new InvalidArgumentException('Configuração de escopo inválida.');
        }

        $enabled = isset($input['enabled']) && (string)$input['enabled'] === '1';
        $webhookUrl = trim((string)($input['webhook_url'] ?? ''));
        $empresaId = ctype_digit((string)($input['empresa_id'] ?? '')) ? (int)$input['empresa_id'] : null;

        if ($scopeKey !== 'default' && ($empresaId ?? 0) <= 0) {
            throw new InvalidArgumentException('Selecione uma empresa válida para o webhook por tenant.');
        }
        if ($scopeKey === 'default' && ($empresaId ?? 0) <= 0) {
            throw new InvalidArgumentException('Selecione uma empresa padrão para resolver o tenant do recrutamento.');
        }
        if (($empresaId ?? 0) > 0 && !$this->empresaRepository->exists((int)$empresaId)) {
            throw new InvalidArgumentException('Empresa não encontrada para a configuração do webhook.');
        }
        if ($webhookUrl !== '' && filter_var($webhookUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Informe uma URL de webhook válida.');
        }

        $this->settingRepository->save($scopeKey, $empresaId, $enabled, $webhookUrl !== '' ? $webhookUrl : null);
    }

    public function retryEvent(int $eventId): array
    {
        $event = $this->eventRepository->find($eventId);
        if (!$event) {
            throw new InvalidArgumentException('Evento de webhook não encontrado para reenvio.');
        }

        $this->eventRepository->requeue($eventId);
        return $this->deliveryService->processEvent($eventId);
    }

    public function processPending(int $limit = 25): array
    {
        return $this->deliveryService->processPending($limit);
    }
}
