<?php
require __DIR__ . '/../../app/core/bootstrap.php';

RecruitmentWebhookSchemaService::ensureSchema();

$pdo = Database::conn();
$empresaId = null;
$vagaId = null;
$candidaturaId = null;
$createdUserId = null;
$webhookEventId = null;

try {
    $userRow = $pdo->query("SELECT id FROM usuarios ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($userRow) {
        $actorId = (int)$userRow['id'];
    } else {
        $createdUserId = User::create('Usuário Integração Webhook', 'webhook.integracao.' . time() . '@teste.local', password_hash('12345678', PASSWORD_BCRYPT), 'admin');
        $actorId = $createdUserId;
    }

    $pdo->prepare("INSERT INTO empresas (nome, slug, ativo) VALUES (?, ?, 1)")
        ->execute(['EMPRESA WEBHOOK INTEGRACAO', 'empresa-webhook-integracao-' . time()]);
    $empresaId = (int)$pdo->lastInsertId();

    $settingRepository = new RecruitmentWebhookSettingRepository($pdo);
    $settingRepository->save(RecruitmentWebhookSettingRepository::tenantScopeKey($empresaId), $empresaId, true, 'https://appmadeplant.com/api/webhooks/recrutamento');
    $settingRepository->save('default', $empresaId, true, 'https://appmadeplant.com/api/webhooks/recrutamento');

    $vagaId = Vaga::create([
        'titulo' => 'Vaga Webhook Integração',
        'descricao' => 'Teste de integração do dispatcher.',
        'requisitos' => 'PHP, MariaDB e webhooks.',
        'area' => 'RH',
        'local' => 'Remoto',
        'empresa_id' => $empresaId,
        'ativo' => 1,
    ]);

    $candidaturaId = Candidatura::create([
        'vaga_id' => $vagaId,
        'nome' => 'Candidato Webhook',
        'email' => 'candidato.webhook.' . time() . '@teste.local',
        'telefone' => '67999999999',
        'cpf' => substr((string)time(), -11),
        'cargo_pretendido' => 'Analista de RH',
        'experiencia' => 'Teste automatizado de dispatcher e webhooks.',
        'pdf_path' => 'teste-webhook.pdf',
        'status' => 'novo',
        'indicacao_colaborador' => 0,
    ]);

    $stages = PipelineStage::all();
    $entrevistaRhId = 0;
    foreach ($stages as $stage) {
        if (PipelineStage::normalizeName((string)$stage['nome']) === 'entrevista rh') {
            $entrevistaRhId = (int)$stage['id'];
            break;
        }
    }
    if ($entrevistaRhId <= 0) {
        throw new RuntimeException('Etapa Entrevista RH não encontrada.');
    }

    if (!Candidatura::upsertStageMetadata($candidaturaId, [
        'interview_date' => '20/06/2026',
        'interview_time' => '14:30',
        'interview_location' => 'Sala RH',
        'interview_link' => 'https://meet.exemplo.com/candidato-webhook',
    ])) {
        throw new RuntimeException('Falha ao persistir metadados da etapa.');
    }

    $fakeHttpClient = new class extends RecruitmentWebhookHttpClient {
        public array $requests = [];

        public function postJson(string $url, array $payload, array $headers = []): array
        {
            $this->requests[] = ['url' => $url, 'payload' => $payload, 'headers' => $headers];
            return ['status_code' => 202, 'body' => '{"ok":true}'];
        }
    };

    $eventRepository = new WebhookEventRepository($pdo);
    $deliveryService = new RecruitmentWebhookDeliveryService($eventRepository, $fakeHttpClient);
    $dispatcher = new RecruitmentEventDispatcher(
        new RecruitmentWebhookSettingRepository($pdo),
        $eventRepository,
        $deliveryService
    );
    $pipelineService = new RecruitmentPipelineService($dispatcher);

    if (!$pipelineService->moveCandidateToStage($candidaturaId, $entrevistaRhId, $actorId)) {
        throw new RuntimeException('Falha ao mover candidatura no pipeline.');
    }

    $updatedCandidate = Candidatura::find($candidaturaId);
    if ((int)($updatedCandidate['stage_id'] ?? 0) !== $entrevistaRhId) {
        throw new RuntimeException('Etapa da candidatura não foi atualizada.');
    }

    $eventRow = $pdo->query('SELECT * FROM webhook_events ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!$eventRow) {
        throw new RuntimeException('Nenhum evento de webhook foi criado.');
    }
    $webhookEventId = (int)$eventRow['id'];

    if ((string)($eventRow['status'] ?? '') !== 'processed') {
        throw new RuntimeException('O webhook deveria ter sido entregue com sucesso no teste de integração.');
    }

    $payload = json_decode((string)$eventRow['payload_json'], true);
    if (!is_array($payload)) {
        throw new RuntimeException('Payload do webhook não está em JSON válido.');
    }

    if (($payload['event'] ?? '') !== 'candidate_stage_changed') {
        throw new RuntimeException('Tipo de evento inesperado no payload.');
    }
    if ((int)($payload['tenant_id'] ?? 0) !== $empresaId) {
        throw new RuntimeException('tenant_id do payload não corresponde à empresa da vaga.');
    }
    if (($payload['new_stage'] ?? '') !== 'Entrevista RH') {
        throw new RuntimeException('Nova etapa incorreta no payload.');
    }
    if (($payload['interview_location'] ?? '') !== 'Sala RH') {
        throw new RuntimeException('Metadado de entrevista não foi incluído no payload.');
    }
    if (($payload['interview_link'] ?? '') !== 'https://meet.exemplo.com/candidato-webhook') {
        throw new RuntimeException('Link de entrevista não foi incluído no payload.');
    }
    if (empty($fakeHttpClient->requests)) {
        throw new RuntimeException('O cliente HTTP fake deveria ter recebido uma requisição.');
    }

    echo "RECRUITMENT_WEBHOOK_FLOW_OK\n";
} finally {
    if ($webhookEventId) {
        $pdo->prepare('DELETE FROM webhook_events WHERE id = ?')->execute([$webhookEventId]);
    }
    if ($candidaturaId) {
        $pdo->prepare('DELETE FROM candidatura_stage_metadata WHERE candidatura_id = ?')->execute([$candidaturaId]);
        $pdo->prepare('DELETE FROM candidatura_historico WHERE candidatura_id = ?')->execute([$candidaturaId]);
        $pdo->prepare('DELETE FROM pipeline_movements WHERE candidatura_id = ?')->execute([$candidaturaId]);
        $pdo->prepare('DELETE FROM candidaturas WHERE id = ?')->execute([$candidaturaId]);
    }
    if ($vagaId) {
        $pdo->prepare('DELETE FROM vagas WHERE id = ?')->execute([$vagaId]);
    }
    if ($empresaId) {
        $pdo->prepare('DELETE FROM recruitment_webhook_settings WHERE empresa_id = ? OR scope_key = ?')->execute([$empresaId, 'default']);
        $pdo->prepare('DELETE FROM empresas WHERE id = ?')->execute([$empresaId]);
    }
    if ($createdUserId) {
        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$createdUserId]);
    }
}
