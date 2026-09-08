<?php
require __DIR__ . '/../app/core/bootstrap.php';

/**
 * Sender interno — roda DENTRO da rede Madeplant (mesma máquina/rede que já executa
 * scripts/sync_metadados_colaboradores.php contra o SQL Server). Lê o METADADOS oficial via
 * {Empresa|Unidade}MetadadosSyncService / MetadadosSyncService::fetchSourceRows() (SELECT — nunca
 * escreve no SQL Server, reaproveita a mesma query/normalização já validada), monta um lote
 * assinado com HMAC e envia por HTTPS para os endpoints receptores em produção
 * (POST /internal/metadados/{empresas|unidades|colaboradores}/sync — ver
 * InternalMetadadosSyncController).
 *
 * Fase 5.1A: passa a cobrir TRÊS dimensões. Uma execução padrão sincroniza, nesta ordem:
 *   1) empresas   2) unidades   3) colaboradores
 * — a ordem importa: unidades resolvem empresa_id contra as empresas já sincronizadas.
 *
 * Modo padrão é SEMPRE seguro: monta o(s) lote(s), valida tamanho, mostra um resumo local e NÃO
 * envia. Só envia de verdade com --enviar, explícito, sem prompt interativo.
 *
 * Opções:
 *   --dimensao=empresas|unidades|colaboradores|todas   (padrão: todas)
 *   --dry-run                                          (explícito; é o padrão de qualquer forma)
 *   --enviar                                           (envio real — decisão separada, ainda não autorizada)
 *   --correlacao-id=<uuid>                             (repassado só à etapa de colaboradores em "todas")
 *
 * Nunca imprime segredo HMAC, senha, CPF, nome, salário individual ou o payload completo — só
 * contagens, origem, horário, status e hash do lote.
 */

const DIMENSOES_SUPORTADAS = ['empresas', 'unidades', 'colaboradores'];
const ORDEM_TODAS = ['empresas', 'unidades', 'colaboradores'];

function montarLote(array $rows, string $origem, ?string $correlacaoId = null): array
{
    $lote = [
        'versao' => '1',
        'origem_metadados' => $origem,
        'gerado_em' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        'total' => count($rows),
        'registros' => $rows,
    ];
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

/** SELECT no SQL Server, sem escrever — reaproveita o serviço de cada dimensão. */
function lerOrigem(string $dimensao): array
{
    switch ($dimensao) {
        case 'empresas':
            return (new EmpresaMetadadosSyncService())->fetchSourceRows();
        case 'unidades':
            return (new UnidadeMetadadosSyncService())->fetchSourceRows();
        case 'colaboradores':
            return (new MetadadosSyncService())->fetchSourceRows();
        default:
            throw new RuntimeException("Dimensão não suportada: {$dimensao}");
    }
}

/**
 * Prévia SOMENTE LEITURA da reconciliação Empresas: RHEMPRESAS (lido agora) x `empresas` local.
 * Reutiliza EXATAMENTE a decisão de EmpresaMetadadosSyncService::planejar() — a mesma que o
 * primeiro envio real executaria. Não escreve nada. Se o MySQL do Portal não estiver acessível
 * desta máquina, devolve o erro sem derrubar o dry-run do lote.
 *
 * ATENÇÃO: a prévia reflete o estado da tabela `empresas` do banco MySQL configurado em
 * `local.php` DESTA máquina. Confirme que é o banco de produção (ou uma cópia fiel) antes de
 * tirar conclusões.
 */
function previewReconciliacaoEmpresas(array $rows, string $origem): array
{
    try {
        $plano = (new EmpresaMetadadosSyncService())->planejar($rows, $origem);
    } catch (Throwable $e) {
        return ['erro' => 'Não foi possível montar a prévia (MySQL do Portal acessível desta máquina?): ' . $e->getMessage()];
    }

    $contagem = [];
    foreach ($plano['itens'] as $item) {
        $contagem[$item['acao']] = ($contagem[$item['acao']] ?? 0) + 1;
    }

    $itens = array_map(static function (array $item): array {
        return [
            'codigo_empresa' => $item['codigo_empresa'],
            'razao_social' => $item['razao_social'],
            'acao' => $item['acao'],
            'empresa_local_id' => $item['empresa_local_id'],
            'empresa_local_candidata' => $item['nome_local'],
            'nome_local_atual' => $item['nome_local'],
            'criterio' => $item['criterio'],
        ];
    }, $plano['itens']);

    return [
        'rhempresas_lidas' => count($rows),
        'aviso_fonte' => 'Prévia contra a tabela `empresas` do MySQL configurado em local.php desta máquina.',
        'contagem_por_acao' => $contagem,
        'itens' => $itens,
        'locais_sem_correspondencia' => $plano['locais_sem_correspondencia'],
    ];
}

function endpointDaDimensao(array $config, string $dimensao): string
{
    $explicito = $config['endpoints'][$dimensao] ?? '';
    if (is_string($explicito) && $explicito !== '') {
        return $explicito;
    }
    $base = (string)($config['endpoint_url'] ?? '');
    // endpoint_url histórico aponta para .../colaboradores/sync — derivamos as outras dimensões.
    return str_replace('/colaboradores/sync', "/{$dimensao}/sync", $base);
}

/**
 * @return array{dimensao:string, status:string, ...}
 */
function sincronizarDimensao(string $dimensao, array $config, bool $enviar, ?string $correlacaoId): array
{
    $segredo = (string)($config['shared_secret'] ?? '');
    $maxBatch = (int)($config['max_batch_size'] ?? 2000);

    $rows = lerOrigem($dimensao);
    $origem = MetadadosDatabase::sourceLabel();
    if ($origem === '') {
        throw new RuntimeException('Não foi possível determinar a origem (Database= do DSN do METADADOS) — verifique local.php.');
    }
    if (count($rows) > $maxBatch) {
        throw new RuntimeException(
            "Lote de {$dimensao} (" . count($rows) . ") excede metadados_sync.max_batch_size={$maxBatch}. "
            . 'Este script não implementa paginação.'
        );
    }

    $lote = montarLote($rows, $origem, $correlacaoId);
    $corpoBruto = json_encode($lote, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $hashLote = hash('sha256', $corpoBruto);

    $resumo = [
        'dimensao' => $dimensao,
        'modo' => $enviar ? 'envio' : 'simulacao',
        'origem' => $origem,
        'correlacao_id' => $correlacaoId,
        'total_registros' => count($rows),
        'tamanho_bytes' => strlen($corpoBruto),
        'hash_lote' => $hashLote,
    ];

    if (!$enviar) {
        $resumo['status'] = 'SIMULADO_NAO_ENVIADO';
        if ($dimensao === 'empresas') {
            $resumo['preview_reconciliacao'] = previewReconciliacaoEmpresas($rows, $origem);
        }
        return $resumo;
    }

    $endpointUrl = endpointDaDimensao($config, $dimensao);
    if (!preg_match('#^https://#i', $endpointUrl)) {
        throw new RuntimeException("Endpoint de {$dimensao} não é uma URL HTTPS válida (verifique metadados_sync.endpoint_url / endpoints).");
    }

    $timestamp = (string)time();
    $assinatura = MetadadosSyncSignature::assinar($timestamp, $corpoBruto, $segredo);
    $resposta = enviarLote($endpointUrl, $corpoBruto, [
        MetadadosSyncSignature::HEADER_TIMESTAMP => $timestamp,
        MetadadosSyncSignature::HEADER_SIGNATURE => $assinatura,
    ]);
    $corpoResposta = json_decode($resposta['body'], true);

    $resumo['status_code'] = $resposta['status_code'];
    $resumo['resposta'] = is_array($corpoResposta) ? $corpoResposta : ['bruto' => '(resposta não-JSON, omitida)'];
    $resumo['status'] = ($resposta['status_code'] >= 200 && $resposta['status_code'] < 300) ? 'ENVIADO' : 'FALHOU';
    return $resumo;
}

try {
    $options = getopt('', ['enviar', 'dry-run', 'dimensao:', 'correlacao-id:']);
    $enviar = array_key_exists('enviar', $options);

    $dimensaoOpt = strtolower(trim((string)($options['dimensao'] ?? 'todas')));
    if ($dimensaoOpt === '' || $dimensaoOpt === 'todas') {
        $dimensoes = ORDEM_TODAS;
    } elseif (in_array($dimensaoOpt, DIMENSOES_SUPORTADAS, true)) {
        $dimensoes = [$dimensaoOpt];
    } else {
        throw new RuntimeException('--dimensao deve ser empresas, unidades, colaboradores ou todas.');
    }

    $correlacaoId = isset($options['correlacao-id']) ? trim((string)$options['correlacao-id']) : null;
    if ($correlacaoId !== null && !preg_match('/^[0-9a-fA-F-]{36}$/', $correlacaoId)) {
        throw new RuntimeException('--correlacao-id, quando informado, precisa ser um UUID.');
    }

    $config = Config::get()['metadados_sync'] ?? [];
    if ((string)($config['shared_secret'] ?? '') === '') {
        throw new RuntimeException('metadados_sync.shared_secret não configurado em local.php — configure antes de usar este script.');
    }

    $resultados = [];
    $falhou = false;
    foreach ($dimensoes as $dimensao) {
        // correlacao_id (fechamento de solicitação do Dashboard) só faz sentido para colaboradores
        // hoje — em "todas" as demais dimensões registram execução própria no histórico.
        $correlacaoDaDimensao = ($dimensao === 'colaboradores' || count($dimensoes) === 1) ? $correlacaoId : null;
        try {
            $resultado = sincronizarDimensao($dimensao, $config, $enviar, $correlacaoDaDimensao);
        } catch (Throwable $e) {
            $resultado = ['dimensao' => $dimensao, 'status' => 'ERRO', 'erro' => $e->getMessage()];
        }
        $resultados[] = $resultado;

        if (($resultado['status'] ?? '') === 'ERRO' || ($resultado['status'] ?? '') === 'FALHOU') {
            $falhou = true;
            if ($enviar) {
                // Em envio real, não continuar para as próximas dimensões após uma falha.
                break;
            }
        }
    }

    $saida = [
        'ok' => !$falhou,
        'modo' => $enviar ? 'envio' : 'simulacao',
        'generated_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        'dimensoes' => $dimensoes,
        'resultados' => $resultados,
    ];

    if ($enviar) {
        $reportPath = STORAGE_PATH . DIRECTORY_SEPARATOR . 'imports' . DIRECTORY_SEPARATOR
            . 'metadados-sync-producao-' . date('Ymd-His') . '.json';
        @mkdir(dirname($reportPath), 0775, true);
        file_put_contents($reportPath, json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $saida['report_path'] = $reportPath;
    }

    echo json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($falhou ? 1 : 0);
} catch (Throwable $e) {
    Logger::exception($e, 'ERROR', ['script' => 'sync_metadados_producao.php']);
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
