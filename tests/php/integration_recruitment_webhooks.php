<?php
require __DIR__ . '/../../app/core/bootstrap.php';

RecruitmentWebhookSchemaService::ensureSchema();

$pdo = Database::conn();
$empresaId = null;
$vagaId = null;
$candidaturaId = null;
$vagaSemEmpresaId = null;
$candidaturaSemEmpresaId = null;
$createdUserId = null;
$webhookEventId = null;
$webhookEventIdSemEmpresa = null;
$testWebhookEventId = null;
$webhookEventIdCandidaturaCriada = null;

// A configuração de webhook agora é única e global (uma só linha, id=1) — preserva o
// estado real do usuário para restaurar ao final, em vez de criar/apagar linhas isoladas
// como no modelo antigo por empresa.
$originalSetting = $pdo->query(
    'SELECT enabled, webhook_url, webhook_secret_encrypted FROM recruitment_webhook_global_settings WHERE id = 1'
)->fetch(PDO::FETCH_ASSOC);

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
    $settingRepository->save(true, 'https://appmadeplant.com/api/webhooks/recrutamento');
    $webhookSecret = $settingRepository->generateSecret();

    // SSRF: URL local deve ser rejeitada ao salvar (defesa em profundidade também existe no envio).
    $adminService = new RecruitmentWebhookAdminService($settingRepository, new WebhookEventRepository($pdo));
    $ssrfBlocked = false;
    try {
        $adminService->saveSetting(['enabled' => '1', 'webhook_url' => 'http://127.0.0.1/webhook-malicioso']);
    } catch (InvalidArgumentException $e) {
        $ssrfBlocked = true;
    }
    if (!$ssrfBlocked) {
        throw new RuntimeException('URL de webhook apontando para 127.0.0.1 deveria ter sido rejeitada (SSRF).');
    }
    // A tentativa acima não persiste nada (validação falha antes do save) — restaura mesmo assim por segurança.
    $settingRepository->save(true, 'https://appmadeplant.com/api/webhooks/recrutamento');

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
        if (($stage['slug'] ?? '') === 'entrevista-rh') {
            $entrevistaRhId = (int)$stage['id'];
            break;
        }
    }
    if ($entrevistaRhId <= 0) {
        throw new RuntimeException('Etapa Entrevista RH não encontrada.');
    }

    $entrevistaRhMetadata = [
        'interview_date' => '20/06/2026',
        'interview_time' => '14:30',
        'interview_location' => 'Sala RH',
        'interview_link' => 'https://meet.exemplo.com/candidato-webhook',
    ];

    $fakeHttpClient = new class extends RecruitmentWebhookHttpClient {
        public array $requests = [];

        public function postJson(string $url, array $payload, array $headers = []): array
        {
            $this->requests[] = ['url' => $url, 'payload' => $payload, 'headers' => $headers];
            return ['status_code' => 202, 'body' => '{"ok":true}'];
        }
    };

    $eventRepository = new WebhookEventRepository($pdo);
    $deliveryService = new RecruitmentWebhookDeliveryService($eventRepository, $fakeHttpClient, $settingRepository);
    $dispatcher = new RecruitmentEventDispatcher($settingRepository, $eventRepository, $deliveryService);
    $pipelineService = new RecruitmentPipelineService($dispatcher);

    // recrutamento.candidatura.criada: disparado logo após a inscrição, antes de qualquer
    // movimentação de Kanban — replica o momento em que HomeController::candidatar() dispara
    // o evento (candidatura ainda na etapa inicial "nova-inscricao").
    $candidatoRecemCriado = Candidatura::find($candidaturaId);
    $webhookEventIdCandidaturaCriada = $dispatcher->dispatchCandidaturaCriada([
        'candidate' => $candidatoRecemCriado,
        'occurred_at' => (new DateTimeImmutable())->format(DATE_ATOM),
    ]);
    if (!$webhookEventIdCandidaturaCriada) {
        throw new RuntimeException('dispatchCandidaturaCriada deveria retornar o id do evento enfileirado.');
    }

    $eventRowCandidaturaCriada = $pdo->query(
        'SELECT * FROM webhook_events WHERE id = ' . (int)$webhookEventIdCandidaturaCriada
    )->fetch(PDO::FETCH_ASSOC);
    if (!$eventRowCandidaturaCriada) {
        throw new RuntimeException('Evento recrutamento.candidatura.criada não foi registrado.');
    }
    if ((string)($eventRowCandidaturaCriada['event_type'] ?? '') !== 'recrutamento.candidatura.criada') {
        throw new RuntimeException('event_type incorreto para o evento de candidatura criada.');
    }
    if ((string)($eventRowCandidaturaCriada['status'] ?? '') !== 'processed') {
        throw new RuntimeException('O evento de candidatura criada deveria ter sido entregue com sucesso.');
    }
    if ($eventRowCandidaturaCriada['movement_id'] !== null) {
        throw new RuntimeException('candidatura.criada nunca deve estar vinculado a um movement_id de Kanban.');
    }

    $payloadCandidaturaCriada = json_decode((string)$eventRowCandidaturaCriada['payload_json'], true);
    if (!is_array($payloadCandidaturaCriada)) {
        throw new RuntimeException('Payload do evento de candidatura criada não está em JSON válido.');
    }
    $protocoloEsperado = Candidatura::formatProtocol($candidaturaId, (string)($candidatoRecemCriado['created_at'] ?? ''));
    if (($payloadCandidaturaCriada['protocolo'] ?? '') !== $protocoloEsperado) {
        throw new RuntimeException('protocolo do payload não corresponde ao esperado: ' . ($payloadCandidaturaCriada['protocolo'] ?? '(vazio)'));
    }
    if ((int)($payloadCandidaturaCriada['empresa']['id'] ?? 0) !== $empresaId) {
        throw new RuntimeException('empresa.id do payload de candidatura criada não corresponde à empresa da vaga.');
    }
    if ((int)($payloadCandidaturaCriada['candidato']['id'] ?? 0) !== $candidaturaId) {
        throw new RuntimeException('candidato.id incorreto no payload de candidatura criada.');
    }
    $linkEsperado = rtrim((string)(Config::app()['base_url'] ?? ''), '/') . '/admin/candidaturas/' . $candidaturaId;
    if (($payloadCandidaturaCriada['link_candidatura'] ?? '') !== $linkEsperado) {
        throw new RuntimeException('link_candidatura incorreto no payload: ' . ($payloadCandidaturaCriada['link_candidatura'] ?? '(vazio)'));
    }
    if (strpos((string)$payloadCandidaturaCriada['link_candidatura'], 'rhmadeplant.test') !== false) {
        throw new RuntimeException('link_candidatura nunca deve usar o domínio de desenvolvimento.');
    }
    if (array_key_exists('etapa', $payloadCandidaturaCriada) || array_key_exists('dados_adicionais', $payloadCandidaturaCriada)) {
        throw new RuntimeException('payload de candidatura criada não deve conter campos de movimentação de etapa (responsabilidade única).');
    }

    $lastRequestCandidaturaCriada = end($fakeHttpClient->requests);
    $headerMapCandidaturaCriada = [];
    foreach (($lastRequestCandidaturaCriada['headers'] ?? []) as $headerLine) {
        [$headerName, $headerValue] = array_map('trim', explode(':', (string)$headerLine, 2));
        $headerMapCandidaturaCriada[$headerName] = $headerValue;
    }
    if (($headerMapCandidaturaCriada['X-Webhook-Event'] ?? '') !== 'recrutamento.candidatura.criada') {
        throw new RuntimeException('Header X-Webhook-Event incorreto para o evento de candidatura criada.');
    }
    if (strpos((string)($headerMapCandidaturaCriada['X-Webhook-Signature'] ?? ''), 'sha256=') !== 0) {
        throw new RuntimeException('candidatura.criada deveria usar a mesma assinatura HMAC dos demais eventos.');
    }

    $moveResult = $pipelineService->moveCandidateToStage($candidaturaId, $entrevistaRhId, $actorId, $entrevistaRhMetadata);
    if (!($moveResult['ok'] ?? false)) {
        throw new RuntimeException('Falha ao mover candidatura no pipeline: ' . ($moveResult['message'] ?? 'erro desconhecido'));
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
    if (trim((string)($eventRow['event_id'] ?? '')) === '') {
        throw new RuntimeException('O evento deveria ter um event_id estável gravado.');
    }
    $originalEventId = (string)$eventRow['event_id'];
    if ((int)($eventRow['movement_id'] ?? 0) <= 0) {
        throw new RuntimeException('O evento deveria estar vinculado à movimentação de pipeline (movement_id).');
    }
    if ((int)($eventRow['empresa_id'] ?? 0) !== $empresaId) {
        throw new RuntimeException('empresa_id do evento não corresponde à empresa da vaga.');
    }

    $payload = json_decode((string)$eventRow['payload_json'], true);
    if (!is_array($payload)) {
        throw new RuntimeException('Payload do webhook não está em JSON válido.');
    }

    if (($payload['event_type'] ?? '') !== 'recrutamento.candidato.etapa_alterada') {
        throw new RuntimeException('Tipo de evento inesperado no payload.');
    }
    if (($payload['event_id'] ?? '') !== $originalEventId) {
        throw new RuntimeException('event_id do payload não corresponde ao event_id gravado no evento.');
    }
    if ((int)($payload['empresa']['id'] ?? 0) !== $empresaId) {
        throw new RuntimeException('empresa.id do payload não corresponde à empresa da vaga.');
    }
    if (($payload['etapa']['atual']['codigo'] ?? '') !== 'entrevista-rh') {
        throw new RuntimeException('Código da nova etapa incorreto no payload.');
    }
    if (($payload['etapa']['atual']['nome'] ?? '') !== 'Entrevista RH') {
        throw new RuntimeException('Nome da nova etapa incorreto no payload.');
    }
    // Candidaturas agora nascem já na etapa inicial do pipeline (Candidatura::create atribui
    // stage_id = PipelineStage::defaultStageId(), nunca mais NULL — ver correção do bug do
    // Kanban em 2026-07-13), então etapa.anterior reflete "Nova Inscrição", não null.
    if (($payload['etapa']['anterior']['codigo'] ?? null) !== 'nova-inscricao') {
        throw new RuntimeException('etapa.anterior deveria ser "nova-inscricao": candidatura nasce já na etapa inicial do pipeline.');
    }
    if (($payload['etapa']['anterior']['nome'] ?? null) !== 'Nova Inscrição') {
        throw new RuntimeException('Nome da etapa anterior incorreto no payload.');
    }
    if (($payload['dados_adicionais']['local_entrevista'] ?? '') !== 'Sala RH') {
        throw new RuntimeException('Dado adicional de local de entrevista não foi incluído no payload.');
    }
    if (($payload['dados_adicionais']['link_entrevista'] ?? '') !== 'https://meet.exemplo.com/candidato-webhook') {
        throw new RuntimeException('Dado adicional de link de entrevista não foi incluído no payload.');
    }
    if (array_key_exists('rejection_reason', $payload['dados_adicionais'] ?? []) || array_key_exists('rejection_reason', $payload)) {
        throw new RuntimeException('rejection_reason nunca deve aparecer em nenhum payload (decisão de produto).');
    }
    if ((int)($payload['responsavel']['id'] ?? 0) !== $actorId) {
        throw new RuntimeException('responsavel.id do payload não corresponde ao usuário responsável pela movimentação.');
    }
    if (empty($fakeHttpClient->requests)) {
        throw new RuntimeException('O cliente HTTP fake deveria ter recebido uma requisição.');
    }

    $lastRequest = end($fakeHttpClient->requests);
    $headerMap = [];
    foreach (($lastRequest['headers'] ?? []) as $headerLine) {
        [$headerName, $headerValue] = array_map('trim', explode(':', (string)$headerLine, 2));
        $headerMap[$headerName] = $headerValue;
    }
    if (($headerMap['X-Webhook-Event'] ?? '') !== 'recrutamento.candidato.etapa_alterada') {
        throw new RuntimeException('Header X-Webhook-Event ausente ou incorreto.');
    }
    if (($headerMap['X-Webhook-Id'] ?? '') !== $originalEventId) {
        throw new RuntimeException('Header X-Webhook-Id ausente ou não corresponde ao event_id.');
    }
    $timestamp = $headerMap['X-Webhook-Timestamp'] ?? '';
    if ($timestamp === '' || !ctype_digit($timestamp)) {
        throw new RuntimeException('Header X-Webhook-Timestamp ausente ou inválido.');
    }
    $canonicalJson = json_encode($lastRequest['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $expectedSignature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $canonicalJson, $webhookSecret);
    if (($headerMap['X-Webhook-Signature'] ?? '') !== $expectedSignature) {
        throw new RuntimeException('Assinatura HMAC do webhook não confere com o segredo configurado.');
    }

    // Reenvio manual deve preservar o mesmo event_id (idempotência para o consumidor externo).
    $eventRepository->requeue($webhookEventId);
    $retryResult = $deliveryService->processEvent($webhookEventId);
    if (!($retryResult['ok'] ?? false)) {
        throw new RuntimeException('Reenvio manual deveria ter sido entregue com sucesso.');
    }
    $eventRowAfterRetry = $pdo->query('SELECT event_id FROM webhook_events WHERE id = ' . (int)$webhookEventId)->fetch(PDO::FETCH_ASSOC);
    if ((string)($eventRowAfterRetry['event_id'] ?? '') !== $originalEventId) {
        throw new RuntimeException('event_id não deveria mudar em um reenvio.');
    }

    // Vaga sem empresa vinculada: o payload deve trazer "empresa": null, sem bloquear o evento.
    $vagaSemEmpresaId = Vaga::create([
        'titulo' => 'Vaga Webhook Sem Empresa',
        'descricao' => 'Teste de evento sem empresa vinculada.',
        'requisitos' => 'PHP, MariaDB e webhooks.',
        'area' => 'RH',
        'local' => 'Remoto',
        'empresa_id' => null,
        'ativo' => 1,
    ]);
    $candidaturaSemEmpresaId = Candidatura::create([
        'vaga_id' => $vagaSemEmpresaId,
        'nome' => 'Candidato Sem Empresa',
        'email' => 'candidato.sememp.' . time() . '@teste.local',
        'telefone' => '67999999998',
        'cpf' => substr((string)(time() + 1), -11),
        'cargo_pretendido' => 'Analista de RH',
        'experiencia' => 'Teste de evento sem empresa.',
        'pdf_path' => 'teste-webhook-sem-empresa.pdf',
        'status' => 'novo',
        'indicacao_colaborador' => 0,
    ]);
    $moveSemEmpresaResult = $pipelineService->moveCandidateToStage($candidaturaSemEmpresaId, $entrevistaRhId, $actorId, $entrevistaRhMetadata);
    if (!($moveSemEmpresaResult['ok'] ?? false)) {
        throw new RuntimeException('Falha ao mover candidatura sem empresa no pipeline: ' . ($moveSemEmpresaResult['message'] ?? 'erro desconhecido'));
    }
    $eventRowSemEmpresa = $pdo->query('SELECT * FROM webhook_events ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $webhookEventIdSemEmpresa = (int)$eventRowSemEmpresa['id'];
    if ($eventRowSemEmpresa['empresa_id'] !== null) {
        throw new RuntimeException('empresa_id deveria ser NULL para vaga sem empresa vinculada.');
    }
    $payloadSemEmpresa = json_decode((string)$eventRowSemEmpresa['payload_json'], true);
    if (!array_key_exists('empresa', $payloadSemEmpresa) || $payloadSemEmpresa['empresa'] !== null) {
        throw new RuntimeException('payload.empresa deveria ser null quando a vaga não tem empresa vinculada.');
    }
    if ((string)($eventRowSemEmpresa['status'] ?? '') !== 'processed') {
        throw new RuntimeException('Evento sem empresa não deveria ser bloqueado — deveria ter sido entregue normalmente.');
    }

    // Evento de teste manual (recrutamento.webhook.teste), sem dados reais de candidato.
    $adminServiceComFake = new RecruitmentWebhookAdminService($settingRepository, $eventRepository, $deliveryService);
    $testResult = $adminServiceComFake->sendTestWebhook();
    if (!($testResult['ok'] ?? false)) {
        throw new RuntimeException('Teste de webhook deveria ter sido entregue com sucesso.');
    }
    $testEventRow = $pdo->query("SELECT * FROM webhook_events WHERE event_type = 'recrutamento.webhook.teste' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$testEventRow) {
        throw new RuntimeException('Evento de teste de webhook não foi registrado.');
    }
    $testWebhookEventId = (int)$testEventRow['id'];
    $testPayload = json_decode((string)$testEventRow['payload_json'], true);
    if (($testPayload['test'] ?? false) !== true) {
        throw new RuntimeException('Payload de teste deveria conter test:true.');
    }
    if (isset($testPayload['candidato']) || isset($testPayload['empresa'])) {
        throw new RuntimeException('Payload de teste não deve conter dados reais de candidato/empresa.');
    }

    // Contadores de fila usados pela tela administrativa.
    $counts = $eventRepository->countsByStatus();
    if (($counts['processed'] ?? 0) < 1) {
        throw new RuntimeException('countsByStatus() deveria refletir ao menos os eventos processados neste teste.');
    }

    echo "RECRUITMENT_WEBHOOK_FLOW_OK\n";
} finally {
    if ($testWebhookEventId) {
        $pdo->prepare('DELETE FROM webhook_events WHERE id = ?')->execute([$testWebhookEventId]);
    }
    if ($webhookEventIdSemEmpresa) {
        $pdo->prepare('DELETE FROM webhook_events WHERE id = ?')->execute([$webhookEventIdSemEmpresa]);
    }
    if ($webhookEventId) {
        $pdo->prepare('DELETE FROM webhook_events WHERE id = ?')->execute([$webhookEventId]);
    }
    if ($webhookEventIdCandidaturaCriada) {
        $pdo->prepare('DELETE FROM webhook_events WHERE id = ?')->execute([$webhookEventIdCandidaturaCriada]);
    }
    if ($candidaturaSemEmpresaId) {
        $pdo->prepare('DELETE FROM candidatura_historico WHERE candidatura_id = ?')->execute([$candidaturaSemEmpresaId]);
        $pdo->prepare('DELETE FROM pipeline_movements WHERE candidatura_id = ?')->execute([$candidaturaSemEmpresaId]);
        $pdo->prepare('DELETE FROM candidaturas WHERE id = ?')->execute([$candidaturaSemEmpresaId]);
    }
    if ($vagaSemEmpresaId) {
        $pdo->prepare('DELETE FROM vagas WHERE id = ?')->execute([$vagaSemEmpresaId]);
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
        $pdo->prepare('DELETE FROM empresas WHERE id = ?')->execute([$empresaId]);
    }
    if ($createdUserId) {
        $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$createdUserId]);
    }

    // Restaura a configuração global real do usuário, exatamente como estava antes do teste.
    if ($originalSetting) {
        $pdo->prepare('UPDATE recruitment_webhook_global_settings SET enabled = ?, webhook_url = ?, webhook_secret_encrypted = ? WHERE id = 1')
            ->execute([$originalSetting['enabled'], $originalSetting['webhook_url'], $originalSetting['webhook_secret_encrypted']]);
    }
}
