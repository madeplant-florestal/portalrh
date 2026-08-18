<?php
class RecruitmentWebhookAdminService
{
    private RecruitmentWebhookSettingRepository $settingRepository;
    private WebhookEventRepository $eventRepository;
    private RecruitmentWebhookDeliveryService $deliveryService;

    public function __construct(
        ?RecruitmentWebhookSettingRepository $settingRepository = null,
        ?WebhookEventRepository $eventRepository = null,
        ?RecruitmentWebhookDeliveryService $deliveryService = null
    ) {
        $this->settingRepository = $settingRepository ?? new RecruitmentWebhookSettingRepository();
        $this->eventRepository = $eventRepository ?? new WebhookEventRepository();
        $this->deliveryService = $deliveryService ?? new RecruitmentWebhookDeliveryService($this->eventRepository, null, $this->settingRepository);
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
        $setting = $this->settingRepository->get();

        return [
            'setting' => [
                'enabled' => (int)($setting['enabled'] ?? 0),
                'webhook_url' => (string)($setting['webhook_url'] ?? ''),
                'has_secret' => !empty($setting['webhook_secret_encrypted']),
            ],
            'queue' => $this->eventRepository->countsByStatus(),
            'last_processed_at' => $this->eventRepository->lastProcessedAt(),
            'history' => $history,
        ];
    }

    public function saveSetting(array $input): void
    {
        $enabled = isset($input['enabled']) && (string)$input['enabled'] === '1';
        $webhookUrl = trim((string)($input['webhook_url'] ?? ''));

        if ($webhookUrl !== '' && filter_var($webhookUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Informe uma URL de webhook válida.');
        }
        if ($webhookUrl !== '') {
            RecruitmentWebhookUrlGuard::assertSafe($webhookUrl);
        }
        if ($enabled && $webhookUrl === '') {
            throw new InvalidArgumentException('Informe a URL do webhook antes de habilitar o envio.');
        }

        $this->settingRepository->save($enabled, $webhookUrl !== '' ? $webhookUrl : null);
    }

    public function regenerateSecret(): string
    {
        return $this->settingRepository->generateSecret();
    }

    public function sendTestWebhook(): array
    {
        $setting = $this->settingRepository->get();
        $webhookUrl = trim((string)($setting['webhook_url'] ?? ''));
        if ($webhookUrl === '') {
            throw new InvalidArgumentException('Configure a URL do webhook antes de testar.');
        }
        RecruitmentWebhookUrlGuard::assertSafe($webhookUrl);

        $eventId = WebhookEventRepository::generateEventId();
        $payload = [
            'event_id' => $eventId,
            'event_type' => 'recrutamento.webhook.teste',
            'occurred_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'test' => true,
            'message' => 'Teste de integração realizado com sucesso.',
        ];

        $webhookEventId = $this->eventRepository->create([
            'event_id' => $eventId,
            'empresa_id' => null,
            'movement_id' => null,
            'event_type' => 'recrutamento.webhook.teste',
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'webhook_url' => $webhookUrl,
            'status' => 'pending',
        ]);

        return $this->deliveryService->processEvent($webhookEventId);
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
