<?php
class RecruitmentEventDispatcher
{
    public const EVENT_CANDIDATE_STAGE_CHANGED = 'CANDIDATE_STAGE_CHANGED';

    private RecruitmentWebhookSettingRepository $settingRepository;
    private WebhookEventRepository $eventRepository;
    private RecruitmentWebhookDeliveryService $deliveryService;

    public function __construct(
        ?RecruitmentWebhookSettingRepository $settingRepository = null,
        ?WebhookEventRepository $eventRepository = null,
        ?RecruitmentWebhookDeliveryService $deliveryService = null
    ) {
        RecruitmentWebhookSchemaService::ensureSchema();
        $this->settingRepository = $settingRepository ?? new RecruitmentWebhookSettingRepository();
        $this->eventRepository = $eventRepository ?? new WebhookEventRepository();
        $this->deliveryService = $deliveryService ?? new RecruitmentWebhookDeliveryService($this->eventRepository);
    }

    public function dispatchCandidateStageChanged(array $context, ?PDO $pdo = null, bool $deliverImmediately = true): ?int
    {
        $payload = $this->buildCandidateStageChangedPayload($context);
        $tenantId = (int)($payload['tenant_id'] ?? 0);
        $resolvedSetting = $this->resolveSetting($tenantId > 0 ? $tenantId : null);

        $status = 'pending';
        $lastError = null;
        $webhookUrl = trim((string)($resolvedSetting['webhook_url'] ?? ''));

        if (!$resolvedSetting) {
            $status = 'skipped';
            $lastError = 'Nenhuma configuração de webhook encontrada para o tenant.';
        } elseif ((int)($resolvedSetting['enabled'] ?? 0) !== 1) {
            $status = 'disabled';
            $lastError = 'Webhook desabilitado para o tenant resolvido.';
        } elseif ($webhookUrl === '') {
            $status = 'failed';
            $lastError = 'Webhook habilitado sem URL configurada.';
        }

        $eventId = $this->eventRepository->create([
            'tenant_id' => $tenantId > 0 ? $tenantId : null,
            'event_type' => self::EVENT_CANDIDATE_STAGE_CHANGED,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'webhook_url' => $webhookUrl !== '' ? $webhookUrl : null,
            'status' => $status,
            'last_error' => $lastError,
            'processed_at' => in_array($status, ['disabled', 'skipped'], true) ? date('Y-m-d H:i:s') : null,
        ], $pdo);

        Logger::info('Evento interno de recrutamento registrado', [
            'event_type' => self::EVENT_CANDIDATE_STAGE_CHANGED,
            'webhook_event_id' => $eventId,
            'tenant_id' => $tenantId > 0 ? $tenantId : null,
            'candidate_id' => $payload['candidate_id'] ?? null,
            'new_stage' => $payload['new_stage'] ?? null,
        ]);

        if ($deliverImmediately && $status === 'pending' && (!$pdo || !$pdo->inTransaction())) {
            $this->deliveryService->processEvent($eventId);
        }

        return $eventId;
    }

    public function deliverQueuedEvent(int $eventId): array
    {
        return $this->deliveryService->processEvent($eventId);
    }

    private function buildCandidateStageChangedPayload(array $context): array
    {
        $candidate = $context['candidate'] ?? [];
        $metadata = $context['metadata'] ?? [];
        $newStage = (string)($context['new_stage'] ?? ($candidate['stage_nome'] ?? ''));

        $payload = [
            'event' => 'candidate_stage_changed',
            'tenant_id' => $this->resolveTenantId($candidate, $context),
            'candidate_id' => (int)($candidate['id'] ?? 0),
            'candidate_name' => (string)($candidate['nome'] ?? ''),
            'candidate_email' => (string)($candidate['email'] ?? ''),
            'candidate_phone' => $this->normalizePhoneForWebhook((string)($candidate['telefone'] ?? '')),
            'job_id' => (int)($candidate['vaga_id'] ?? 0),
            'job_title' => (string)($candidate['vaga_titulo'] ?? ''),
            'previous_stage' => (string)($context['previous_stage'] ?? ''),
            'new_stage' => $newStage,
            'changed_by' => (string)($context['changed_by'] ?? 'Sistema'),
            'changed_at' => (string)($context['changed_at'] ?? date(DATE_ATOM)),
        ];

        $normalizedStage = PipelineStage::normalizeName($newStage);
        if (in_array($normalizedStage, ['entrevista rh', 'entrevista gestor'], true)) {
            $this->appendIfNotEmpty($payload, 'interview_date', $this->formatDateForWebhook($metadata['interview_date'] ?? null));
            $this->appendIfNotEmpty($payload, 'interview_time', $this->formatTimeForWebhook($metadata['interview_time'] ?? null));
            $this->appendIfNotEmpty($payload, 'interview_location', $metadata['interview_location'] ?? null);
            $this->appendIfNotEmpty($payload, 'interview_link', $metadata['interview_link'] ?? null);
        }

        if ($normalizedStage === 'admissao') {
            $this->appendIfNotEmpty($payload, 'admission_date', $this->formatDateForWebhook($metadata['admission_date'] ?? null));
            $this->appendIfNotEmpty($payload, 'admission_notes', $metadata['admission_notes'] ?? null);
        }

        if ($normalizedStage === 'testes') {
            $this->appendIfNotEmpty($payload, 'test_name', $metadata['test_name'] ?? null);
            $this->appendIfNotEmpty($payload, 'deadline', $this->formatDateForWebhook($metadata['deadline'] ?? null));
        }

        return $payload;
    }

    private function resolveTenantId(array $candidate, array $context): int
    {
        $tenantId = (int)($candidate['vaga_empresa_id'] ?? $candidate['empresa_id'] ?? $context['tenant_id'] ?? 0);
        if ($tenantId > 0) {
            return $tenantId;
        }

        $default = $this->settingRepository->resolveForTenant(null);
        if ((int)($default['empresa_id'] ?? 0) > 0) {
            return (int)$default['empresa_id'];
        }

        return (int)($this->settingRepository->firstActiveEmpresaId() ?? 0);
    }

    private function resolveSetting(?int $tenantId): ?array
    {
        $setting = $this->settingRepository->resolveForTenant($tenantId);
        if ($setting) {
            return $setting;
        }

        $fallbackTenantId = $this->settingRepository->firstActiveEmpresaId();
        if ($fallbackTenantId) {
            $fallbackSetting = $this->settingRepository->resolveForTenant($fallbackTenantId);
            if ($fallbackSetting) {
                return $fallbackSetting;
            }
        }

        return null;
    }

    private function normalizePhoneForWebhook(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) === 11) {
            return '55' . $digits;
        }
        return $digits;
    }

    private function formatDateForWebhook(?string $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->format('Y-m-d');
        } catch (Throwable $e) {
            return null;
        }
    }

    private function formatTimeForWebhook(?string $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->format('H:i:s');
        } catch (Throwable $e) {
            return null;
        }
    }

    private function appendIfNotEmpty(array &$payload, string $key, $value): void
    {
        if ($value === null) {
            return;
        }
        $stringValue = trim((string)$value);
        if ($stringValue === '') {
            return;
        }
        $payload[$key] = $stringValue;
    }
}
