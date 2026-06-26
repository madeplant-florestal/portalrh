<?php
class RecruitmentWebhookDeliveryService
{
    private WebhookEventRepository $eventRepository;
    private RecruitmentWebhookHttpClient $httpClient;

    public function __construct(
        ?WebhookEventRepository $eventRepository = null,
        ?RecruitmentWebhookHttpClient $httpClient = null
    ) {
        $this->eventRepository = $eventRepository ?? new WebhookEventRepository();
        $this->httpClient = $httpClient ?? new RecruitmentWebhookHttpClient();
    }

    public function processEvent(int $eventId): array
    {
        $event = $this->eventRepository->find($eventId);
        if (!$event) {
            throw new InvalidArgumentException('Evento de webhook não encontrado.');
        }

        $status = (string)($event['status'] ?? '');
        if ($status === 'disabled' || $status === 'skipped') {
            return ['ok' => false, 'status' => $status];
        }

        $url = trim((string)($event['webhook_url'] ?? ''));
        if ($url === '') {
            $retryCount = (int)($event['retry_count'] ?? 0) + 1;
            $this->eventRepository->markFailed($eventId, $retryCount, null, null, 'Webhook sem URL configurada.');
            return ['ok' => false, 'status' => 'failed'];
        }

        $this->eventRepository->markProcessing($eventId);

        try {
            $payload = json_decode((string)$event['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            $response = $this->httpClient->postJson($url, is_array($payload) ? $payload : []);
            $statusCode = (int)($response['status_code'] ?? 0);
            $body = (string)($response['body'] ?? '');

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->eventRepository->markProcessed($eventId, $statusCode, $body);
                return ['ok' => true, 'status' => 'processed', 'status_code' => $statusCode];
            }

            $retryCount = (int)($event['retry_count'] ?? 0) + 1;
            $this->eventRepository->markFailed($eventId, $retryCount, $statusCode, $body, 'Webhook retornou status HTTP não esperado.');
            return ['ok' => false, 'status' => 'failed', 'status_code' => $statusCode];
        } catch (Throwable $e) {
            $retryCount = (int)($event['retry_count'] ?? 0) + 1;
            $this->eventRepository->markFailed($eventId, $retryCount, null, null, $e->getMessage());
            Logger::warning('Falha ao entregar webhook de recrutamento', [
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    public function processPending(int $limit = 25): array
    {
        $processed = 0;
        $failed = 0;

        foreach ($this->eventRepository->pending($limit) as $event) {
            $result = $this->processEvent((int)$event['id']);
            if ($result['ok'] ?? false) {
                $processed++;
            } else {
                $failed++;
            }
        }

        return [
            'processed' => $processed,
            'failed' => $failed,
        ];
    }
}
