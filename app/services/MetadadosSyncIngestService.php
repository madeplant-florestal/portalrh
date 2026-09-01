<?php

/**
 * Recebe e aplica um lote de sincronização METADADOS enviado por
 * scripts/sync_metadados_producao.php (rodando dentro da rede Madeplant) via
 * POST /internal/metadados/colaboradores/sync.
 *
 * Nunca confia no cliente, mesmo autenticado: 1) verifica assinatura HMAC + janela de replay
 * (MetadadosSyncSignature); 2) decodifica e valida a FORMA do payload (MetadadosSyncRequestValidator)
 * — rejeita o lote inteiro se a estrutura for inválida, antes de qualquer escrita; 3) delega a
 * persistência a MetadadosSyncService::applyRows() já validado (Fase 1) — mesma transação única,
 * mesmo upsert idempotente, mesma proteção contra mistura de origem (Fase corretiva de
 * pureza) — nada disso é duplicado aqui.
 *
 * Este endpoint NUNCA acessa o SQL Server do METADADOS — só recebe dados já extraídos/normalizados
 * pelo sender e escreve no MySQL local, exatamente como a sincronização direta já fazia.
 */
class MetadadosSyncIngestService
{
    private MetadadosSyncService $syncService;

    public function __construct(?MetadadosSyncService $syncService = null)
    {
        $this->syncService = $syncService ?? new MetadadosSyncService();
    }

    /**
     * @param array<string,string> $headers Nomes de cabeçalho case-insensitive.
     * @param array|null $configOverride Injeção de teste — sem isso, lê Config::get()['metadados_sync'].
     * @return array{http_status:int, body:array}
     */
    public function receberLote(string $corpoBruto, array $headers, ?array $configOverride = null): array
    {
        $config = $configOverride ?? (Config::get()['metadados_sync'] ?? []);
        $segredo = (string)($config['shared_secret'] ?? '');
        $janela = (int)($config['replay_window_seconds'] ?? 300);
        $maxBatch = (int)($config['max_batch_size'] ?? 2000);

        $timestamp = self::header($headers, MetadadosSyncSignature::HEADER_TIMESTAMP);
        $assinatura = self::header($headers, MetadadosSyncSignature::HEADER_SIGNATURE);

        $verificacao = MetadadosSyncSignature::verificar($timestamp, $assinatura, $corpoBruto, $segredo, $janela);
        if (!$verificacao['ok']) {
            Logger::warning('Sincronização METADADOS recusada: falha de autenticação', ['motivo' => $verificacao['motivo']]);
            return ['http_status' => 401, 'body' => ['ok' => false, 'error' => 'Autenticação inválida.']];
        }

        $payload = json_decode($corpoBruto, true);
        if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
            return ['http_status' => 400, 'body' => ['ok' => false, 'error' => 'JSON inválido.']];
        }

        $validacao = MetadadosSyncRequestValidator::validar($payload, $maxBatch);
        if (!$validacao['ok']) {
            Logger::warning('Sincronização METADADOS recusada: payload inválido', ['erros' => $validacao['errors']]);
            return ['http_status' => 400, 'body' => ['ok' => false, 'error' => 'Payload inválido.', 'detalhes' => $validacao['errors']]];
        }

        try {
            $resumo = $this->syncService->applyRows($validacao['registros'], $validacao['origem'], false);
        } catch (Throwable $e) {
            Logger::exception($e, 'ERROR', ['endpoint' => 'internal/metadados/colaboradores/sync']);
            return ['http_status' => 409, 'body' => ['ok' => false, 'error' => $e->getMessage()]];
        }

        return [
            'http_status' => 200,
            'body' => [
                'ok' => ($resumo['errors'] ?? 0) === 0,
                'recebidos' => count($validacao['registros']),
                'inseridos' => $resumo['inserted'] ?? 0,
                'atualizados' => $resumo['updated'] ?? 0,
                'inalterados' => $resumo['unchanged'] ?? 0,
                'erros' => $resumo['errors'] ?? 0,
                'origem' => $resumo['origem'] ?? $validacao['origem'],
            ],
        ];
    }

    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string)$key, $name) === 0) {
                return (string)$value;
            }
        }
        return null;
    }
}
