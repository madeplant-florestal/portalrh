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
    private ?MetadadosSyncExecucaoRepository $execucaoRepository;

    public function __construct(
        ?MetadadosSyncService $syncService = null,
        ?MetadadosSyncExecucaoRepository $execucaoRepository = null
    ) {
        $this->syncService = $syncService ?? new MetadadosSyncService();
        $this->execucaoRepository = $execucaoRepository;
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

        $correlacaoId = $validacao['correlacao_id'] ?? null;
        $inicio = new DateTimeImmutable();
        $hashLote = hash('sha256', $corpoBruto);

        try {
            $resumo = $this->syncService->applyRows($validacao['registros'], $validacao['origem'], false);
        } catch (Throwable $e) {
            Logger::exception($e, 'ERROR', ['endpoint' => 'internal/metadados/colaboradores/sync']);
            $this->registrarHistorico($correlacaoId, MetadadosSyncExecucaoRepository::STATUS_FALHA, [
                'origem' => $validacao['origem'],
                'iniciado_em' => $inicio->format('Y-m-d H:i:s'),
                'concluido_em' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                'hash_lote' => $hashLote,
                'mensagem_tecnica' => MetadadosSyncExecucaoRepository::sanitizarMensagem($e->getMessage()),
            ]);
            return ['http_status' => 409, 'body' => ['ok' => false, 'error' => $e->getMessage()]];
        }

        $erros = (int)($resumo['errors'] ?? 0);
        $recebidos = count($validacao['registros']);
        $this->registrarHistorico(
            $correlacaoId,
            $erros === 0 ? MetadadosSyncExecucaoRepository::STATUS_SUCESSO : MetadadosSyncExecucaoRepository::STATUS_SUCESSO_COM_ERROS,
            [
                'origem' => $resumo['origem'] ?? $validacao['origem'],
                'iniciado_em' => $inicio->format('Y-m-d H:i:s'),
                'concluido_em' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                'hash_lote' => $hashLote,
                'registros_recebidos' => $recebidos,
                'inseridos' => (int)($resumo['inserted'] ?? 0),
                'atualizados' => (int)($resumo['updated'] ?? 0),
                'inalterados' => (int)($resumo['unchanged'] ?? 0),
                'erros' => $erros,
            ]
        );

        return [
            'http_status' => 200,
            'body' => [
                'ok' => $erros === 0,
                'recebidos' => $recebidos,
                'inseridos' => $resumo['inserted'] ?? 0,
                'atualizados' => $resumo['updated'] ?? 0,
                'inalterados' => $resumo['unchanged'] ?? 0,
                'erros' => $erros,
                'origem' => $resumo['origem'] ?? $validacao['origem'],
            ],
        ];
    }

    /**
     * Registra a execução no histórico operacional (metadados_sync_execucoes). É observabilidade,
     * NUNCA parte do contrato de sincronização: qualquer falha aqui (tabela ausente, erro de
     * escrita) é engolida com um aviso — uma sincronização já aplicada nunca é revertida nem
     * respondida como erro por causa do histórico.
     *
     * @param array<string,mixed> $dados
     */
    private function registrarHistorico(?string $correlacaoId, string $status, array $dados): void
    {
        try {
            $repo = $this->execucaoRepository ?? new MetadadosSyncExecucaoRepository();
            $repo->registrarResultado($correlacaoId, $status, $dados);
        } catch (\Throwable $e) {
            Logger::warning('Não foi possível registrar a execução da sincronização METADADOS no histórico', [
                'erro' => $e->getMessage(),
            ]);
        }
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
