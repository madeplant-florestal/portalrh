<?php
require __DIR__ . '/../app/core/bootstrap.php';

/**
 * Sender interno da Fase 4 — roda DENTRO da rede Madeplant (mesma máquina/rede que já executa
 * scripts/sync_metadados_colaboradores.php contra o SQL Server). Lê o METADADOS oficial via
 * MetadadosSyncService::fetchSourceRows() (SELECT — nunca escreve no SQL Server, reaproveita a
 * mesma query/normalização já validada, nunca duplicada aqui), monta um lote assinado com HMAC e
 * envia por HTTPS para o endpoint receptor em produção
 * (POST /internal/metadados/colaboradores/sync — ver InternalMetadadosSyncController).
 *
 * Modo padrão é SEMPRE seguro: monta o lote, valida o tamanho, mostra um resumo local e NÃO
 * envia. Só envia de verdade com --enviar, explícito, sem prompt interativo — mesmo padrão de
 * scripts/aplicar_vinculos_colaboradores_metadados.php.
 *
 * Nunca imprime segredo HMAC, senha, CPF, nome, salário individual ou o payload completo — só
 * contagens, origem, horário, status e hash do lote.
 */

function montarLote(array $rows, string $origem, ?string $correlacaoId = null): array
{
    $lote = [
        'versao' => '1',
        'origem_metadados' => $origem,
        'gerado_em' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        'total' => count($rows),
        'registros' => $rows,
    ];
    // Opcional — repassado pela camada de orquestração (n8n) quando a sincronização nasceu de uma
    // solicitação manual do Dashboard, para o receiver fechar exatamente aquela solicitação no
    // histórico (metadados_sync_execucoes). Ausente numa execução direta por CLI/agendamento.
    if ($correlacaoId !== null && $correlacaoId !== '') {
        $lote['correlacao_id'] = $correlacaoId;
    }
    return $lote;
}

function enviarLote(string $url, string $corpoBruto, array $headers): array
{
    $headersFormatados = [];
    foreach ($headers as $nome => $valor) {
        $headersFormatados[] = "{$nome}: {$valor}";
    }
    $headersFormatados[] = 'Content-Type: application/json';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $corpoBruto,
        CURLOPT_HTTPHEADER => $headersFormatados,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        $erro = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("Falha de rede ao enviar o lote: {$erro}");
    }
    $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status_code' => $statusCode, 'body' => (string)$body];
}

try {
    $options = getopt('', ['enviar', 'correlacao-id:']);
    $enviar = array_key_exists('enviar', $options);
    $correlacaoId = isset($options['correlacao-id']) ? trim((string)$options['correlacao-id']) : null;
    if ($correlacaoId !== null && !preg_match('/^[0-9a-fA-F-]{36}$/', $correlacaoId)) {
        throw new RuntimeException('--correlacao-id, quando informado, precisa ser um UUID.');
    }

    $config = Config::get()['metadados_sync'] ?? [];
    $segredo = (string)($config['shared_secret'] ?? '');
    $endpointUrl = (string)($config['endpoint_url'] ?? '');
    $maxBatch = (int)($config['max_batch_size'] ?? 2000);

    if ($segredo === '') {
        throw new RuntimeException('metadados_sync.shared_secret não configurado em local.php — configure antes de usar este script.');
    }
    if ($enviar && !preg_match('#^https://#i', $endpointUrl)) {
        throw new RuntimeException('metadados_sync.endpoint_url precisa ser uma URL HTTPS válida para enviar de verdade.');
    }

    $syncService = new MetadadosSyncService();
    $rows = $syncService->fetchSourceRows();
    $origem = MetadadosDatabase::sourceLabel();
    if ($origem === '') {
        throw new RuntimeException('Não foi possível determinar a origem (Database= do DSN do METADADOS) — verifique local.php.');
    }

    if (count($rows) > $maxBatch) {
        throw new RuntimeException(
            'Lote (' . count($rows) . ") excede o tamanho máximo configurado (metadados_sync.max_batch_size={$maxBatch}). "
            . 'Este script não implementa paginação — trate antes de prosseguir (ver Fase 4, seção 14).'
        );
    }

    $lote = montarLote($rows, $origem, $correlacaoId);
    $corpoBruto = json_encode($lote, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tamanhoBytes = strlen($corpoBruto);
    $hashLote = hash('sha256', $corpoBruto);

    $resumo = [
        'modo' => $enviar ? 'envio' : 'simulacao',
        'generated_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        'origem' => $origem,
        'correlacao_id' => $correlacaoId,
        'total_registros' => count($rows),
        'tamanho_bytes' => $tamanhoBytes,
        'hash_lote' => $hashLote,
    ];

    if (!$enviar) {
        $resumo['status'] = 'SIMULADO_NAO_ENVIADO';
        echo json_encode($resumo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    $timestamp = (string)time();
    $assinatura = MetadadosSyncSignature::assinar($timestamp, $corpoBruto, $segredo);
    $headers = [
        MetadadosSyncSignature::HEADER_TIMESTAMP => $timestamp,
        MetadadosSyncSignature::HEADER_SIGNATURE => $assinatura,
    ];

    $resposta = enviarLote($endpointUrl, $corpoBruto, $headers);
    $corpoResposta = json_decode($resposta['body'], true);

    $resumo['status_code'] = $resposta['status_code'];
    $resumo['resposta'] = is_array($corpoResposta) ? $corpoResposta : ['bruto' => '(resposta não-JSON, omitida)'];
    $resumo['status'] = ($resposta['status_code'] >= 200 && $resposta['status_code'] < 300) ? 'ENVIADO' : 'FALHOU';

    $reportPath = STORAGE_PATH . DIRECTORY_SEPARATOR . 'imports' . DIRECTORY_SEPARATOR
        . 'metadados-sync-producao-' . date('Ymd-His') . '.json';
    @mkdir(dirname($reportPath), 0775, true);
    file_put_contents($reportPath, json_encode($resumo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $resumo['report_path'] = $reportPath;

    echo json_encode($resumo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($resumo['status'] === 'ENVIADO' ? 0 : 1);
} catch (Throwable $e) {
    Logger::exception($e, 'ERROR', ['script' => 'sync_metadados_producao.php']);
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
