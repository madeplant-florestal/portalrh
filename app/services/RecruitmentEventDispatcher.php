<?php
class RecruitmentEventDispatcher
{
    public const EVENT_CANDIDATE_STAGE_CHANGED = 'recrutamento.candidato.etapa_alterada';
    public const EVENT_CANDIDATURA_CRIADA = 'recrutamento.candidatura.criada';

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
        $this->deliveryService = $deliveryService ?? new RecruitmentWebhookDeliveryService($this->eventRepository, null, $this->settingRepository);
    }

    public function dispatchCandidateStageChanged(array $context, ?PDO $pdo = null, bool $deliverImmediately = true): ?int
    {
        $candidate = $context['candidate'] ?? [];
        $empresaId = (int)($candidate['vaga_empresa_id'] ?? 0);
        $eventId = WebhookEventRepository::generateEventId();

        $payload = $this->buildCandidateStageChangedPayload($context, $eventId, $empresaId > 0 ? $empresaId : null, $candidate);

        $setting = $this->settingRepository->get();
        $webhookUrl = trim((string)($setting['webhook_url'] ?? ''));

        $status = 'pending';
        $lastError = null;
        if ((int)($setting['enabled'] ?? 0) !== 1) {
            $status = 'disabled';
            $lastError = 'Integração de webhooks do recrutamento está desabilitada.';
        } elseif ($webhookUrl === '') {
            $status = 'failed';
            $lastError = 'Webhook habilitado sem URL configurada.';
        }

        $webhookEventId = $this->eventRepository->create([
            'event_id' => $eventId,
            'empresa_id' => $empresaId > 0 ? $empresaId : null,
            'movement_id' => $context['movement_id'] ?? null,
            'event_type' => self::EVENT_CANDIDATE_STAGE_CHANGED,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'webhook_url' => $webhookUrl !== '' ? $webhookUrl : null,
            'status' => $status,
            'last_error' => $lastError,
            'processed_at' => $status === 'disabled' ? date('Y-m-d H:i:s') : null,
        ], $pdo);

        Logger::info('Evento interno de recrutamento registrado', [
            'event_type' => self::EVENT_CANDIDATE_STAGE_CHANGED,
            'event_id' => $eventId,
            'webhook_event_id' => $webhookEventId,
            'empresa_id' => $empresaId > 0 ? $empresaId : null,
            'candidate_id' => $payload['candidato']['id'] ?? null,
            'new_stage' => $payload['etapa']['atual']['codigo'] ?? null,
        ]);

        if ($deliverImmediately && $status === 'pending' && (!$pdo || !$pdo->inTransaction())) {
            $this->deliveryService->processEvent($webhookEventId);
        }

        return $webhookEventId;
    }

    /**
     * Disparado exclusivamente quando um candidato conclui a inscrição (HomeController::candidatar).
     * Nunca deve ser usado para movimentação de Kanban — ver EVENT_CANDIDATE_STAGE_CHANGED.
     */
    public function dispatchCandidaturaCriada(array $context, ?PDO $pdo = null, bool $deliverImmediately = true): ?int
    {
        $candidate = $context['candidate'] ?? [];
        $empresaId = (int)($candidate['vaga_empresa_id'] ?? 0);
        $eventId = WebhookEventRepository::generateEventId();

        $payload = $this->buildCandidaturaCriadaPayload($context, $eventId, $empresaId > 0 ? $empresaId : null, $candidate);

        $setting = $this->settingRepository->get();
        $webhookUrl = trim((string)($setting['webhook_url'] ?? ''));

        $status = 'pending';
        $lastError = null;
        if ((int)($setting['enabled'] ?? 0) !== 1) {
            $status = 'disabled';
            $lastError = 'Integração de webhooks do recrutamento está desabilitada.';
        } elseif ($webhookUrl === '') {
            $status = 'failed';
            $lastError = 'Webhook habilitado sem URL configurada.';
        }

        $webhookEventId = $this->eventRepository->create([
            'event_id' => $eventId,
            'empresa_id' => $empresaId > 0 ? $empresaId : null,
            'movement_id' => null,
            'event_type' => self::EVENT_CANDIDATURA_CRIADA,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'webhook_url' => $webhookUrl !== '' ? $webhookUrl : null,
            'status' => $status,
            'last_error' => $lastError,
            'processed_at' => $status === 'disabled' ? date('Y-m-d H:i:s') : null,
        ], $pdo);

        Logger::info('Evento interno de recrutamento registrado', [
            'event_type' => self::EVENT_CANDIDATURA_CRIADA,
            'event_id' => $eventId,
            'webhook_event_id' => $webhookEventId,
            'empresa_id' => $empresaId > 0 ? $empresaId : null,
            'candidate_id' => $payload['candidato']['id'] ?? null,
            'protocolo' => $payload['protocolo'] ?? null,
        ]);

        if ($deliverImmediately && $status === 'pending' && (!$pdo || !$pdo->inTransaction())) {
            $this->deliveryService->processEvent($webhookEventId);
        }

        return $webhookEventId;
    }

    public function deliverQueuedEvent(int $eventId): array
    {
        return $this->deliveryService->processEvent($eventId);
    }

    private function buildCandidateStageChangedPayload(array $context, string $eventId, ?int $empresaId, array $candidate): array
    {
        $stageFrom = $context['stage_from'] ?? null;
        $stageTo = $context['stage_to'] ?? [];
        $actor = $context['actor'] ?? [];
        $stageToSlug = (string)($stageTo['slug'] ?? '');

        return [
            'event_id' => $eventId,
            'event_type' => self::EVENT_CANDIDATE_STAGE_CHANGED,
            'occurred_at' => (string)($context['occurred_at'] ?? (new DateTimeImmutable())->format(DATE_ATOM)),
            'empresa' => $empresaId !== null ? [
                'id' => $empresaId,
                'nome' => (string)($candidate['vaga_empresa_nome'] ?? ''),
            ] : null,
            'candidato' => [
                'id' => (int)($candidate['id'] ?? 0),
                'nome' => (string)($candidate['nome'] ?? ''),
                'telefone' => $this->normalizePhoneForWebhook((string)($candidate['telefone'] ?? '')),
                'email' => (string)($candidate['email'] ?? ''),
            ],
            'vaga' => [
                'id' => (int)($candidate['vaga_id'] ?? 0),
                'titulo' => (string)($candidate['vaga_titulo'] ?? ''),
            ],
            'etapa' => [
                'anterior' => $stageFrom !== null ? [
                    'id' => $stageFrom['id'] ?? null,
                    'codigo' => $stageFrom['slug'] ?? null,
                    'nome' => $stageFrom['name'] ?? null,
                ] : null,
                'atual' => [
                    'id' => $stageTo['id'] ?? null,
                    'codigo' => $stageTo['slug'] ?? null,
                    'nome' => $stageTo['name'] ?? null,
                ],
            ],
            'dados_adicionais' => $this->buildDadosAdicionais($stageToSlug, $candidate),
            'responsavel' => [
                'id' => $actor['id'] ?? null,
                'nome' => (string)($actor['name'] ?? 'Sistema'),
            ],
        ];
    }

    private function buildCandidaturaCriadaPayload(array $context, string $eventId, ?int $empresaId, array $candidate): array
    {
        $candidateId = (int)($candidate['id'] ?? 0);
        $createdAt = (string)($candidate['created_at'] ?? '');

        return [
            'event_id' => $eventId,
            'event_type' => self::EVENT_CANDIDATURA_CRIADA,
            'occurred_at' => (string)($context['occurred_at'] ?? (new DateTimeImmutable())->format(DATE_ATOM)),
            'protocolo' => Candidatura::formatProtocol($candidateId, $createdAt),
            'empresa' => $empresaId !== null ? [
                'id' => $empresaId,
                'nome' => (string)($candidate['vaga_empresa_nome'] ?? ''),
            ] : null,
            'candidato' => [
                'id' => $candidateId,
                'nome' => (string)($candidate['nome'] ?? ''),
                'telefone' => $this->normalizePhoneForWebhook((string)($candidate['telefone'] ?? '')),
                'email' => (string)($candidate['email'] ?? ''),
            ],
            'vaga' => [
                'id' => (int)($candidate['vaga_id'] ?? 0),
                'titulo' => (string)($candidate['vaga_titulo'] ?? ''),
            ],
            'link_candidatura' => rtrim((string)(Config::app()['base_url'] ?? ''), '/') . '/admin/candidaturas/' . $candidateId,
        ];
    }

    private function buildDadosAdicionais(string $stageSlug, array $candidate): array
    {
        $dados = [
            'data_entrevista' => null,
            'horario_entrevista' => null,
            'local_entrevista' => null,
            'link_entrevista' => null,
            'prazo_documentos' => null,
            'observacoes_admissao' => null,
            'nome_teste' => null,
            'prazo_teste' => null,
        ];

        if (in_array($stageSlug, ['entrevista-rh', 'entrevista-gestor'], true)) {
            $dados['data_entrevista'] = $this->formatDateForWebhook($candidate['interview_date'] ?? null);
            $dados['horario_entrevista'] = $this->formatTimeForWebhook($candidate['interview_time'] ?? null);
            $dados['local_entrevista'] = $this->nullIfEmpty($candidate['interview_location'] ?? null);
            $dados['link_entrevista'] = $this->nullIfEmpty($candidate['interview_link'] ?? null);
        }

        if ($stageSlug === 'admissao') {
            $dados['prazo_documentos'] = $this->formatDateForWebhook($candidate['admission_date'] ?? null);
            $dados['observacoes_admissao'] = $this->nullIfEmpty($candidate['admission_notes'] ?? null);
        }

        if ($stageSlug === 'testes') {
            $dados['nome_teste'] = $this->nullIfEmpty($candidate['test_name'] ?? null);
            $dados['prazo_teste'] = $this->formatDateForWebhook($candidate['deadline'] ?? null);
        }

        return $dados;
    }

    private function nullIfEmpty(?string $value): ?string
    {
        $value = trim((string)$value);
        return $value !== '' ? $value : null;
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
}
