<?php

/**
 * Recebe e aplica um lote de sincronização das dimensões EMPRESAS, UNIDADES, SETORES ou CARGOS
 * enviado pelo sender interno (scripts/sync_metadados_producao.php) via
 * POST /internal/metadados/{empresas|unidades|setores|cargos}/sync — Fases 5.1A e 5.2.
 *
 * Espelha MetadadosSyncIngestService (colaboradores), reaproveitando o que é comum e sem duplicar
 * mecanismo nenhum:
 *   1) MetadadosSyncEnvelope::abrir()  -> HMAC + janela de replay + decode JSON;
 *   2) MetadadosDimensaoSyncRequestValidator -> forma do envelope + chave lógica por registro;
 *   3) {Empresa|Unidade|Catalogo}MetadadosSyncService::applyRows() -> persistência (transação
 *      única, upsert idempotente, nunca DELETE) — a mesma lógica testável sem SQL Server;
 *   4) MetadadosSyncExecucaoRepository -> histórico operacional, com `dimensao` preenchida.
 *
 * Este endpoint NUNCA acessa o SQL Server do METADADOS — só recebe dados já extraídos pelo sender
 * e escreve no MySQL local.
 */
class MetadadosDimensaoSyncIngestService
{
    private const DIMENSOES = ['empresas', 'unidades', 'setores', 'cargos'];

    private string $dimensao;
    /** @var object Serviço de sincronização da dimensão — expõe applyRows(array, ?string): array. */
    private object $syncService;
    private ?MetadadosSyncExecucaoRepository $execucaoRepository;

    public function __construct(
        string $dimensao,
        ?object $syncService = null,
        ?MetadadosSyncExecucaoRepository $execucaoRepository = null
    ) {
        if (!in_array($dimensao, self::DIMENSOES, true)) {
            throw new \InvalidArgumentException("Dimensão não suportada: {$dimensao}");
        }
        $this->dimensao = $dimensao;
        $this->syncService = $syncService ?? self::servicoPadrao($dimensao);
        $this->execucaoRepository = $execucaoRepository;
    }

    private static function servicoPadrao(string $dimensao): object
    {
        switch ($dimensao) {
            case 'empresas':
                return new EmpresaMetadadosSyncService();
            case 'unidades':
                return new UnidadeMetadadosSyncService();
            case 'setores':
            case 'cargos':
                return new CatalogoMetadadosSyncService($dimensao);
        }
        throw new \InvalidArgumentException("Dimensão não suportada: {$dimensao}");
    }

    /**
     * @param array<string,string> $headers
     * @param array|null $configOverride Injeção de teste — sem isso lê Config::get()['metadados_sync'].
     * @return array{http_status:int, body:array}
     */
    public function receberLote(string $corpoBruto, array $headers, ?array $configOverride = null): array
    {
        $config = $configOverride ?? (Config::get()['metadados_sync'] ?? []);
        $segredo = (string)($config['shared_secret'] ?? '');
        $janela = (int)($config['replay_window_seconds'] ?? 300);
        $maxBatch = (int)($config['max_batch_size'] ?? 2000);

        $envelope = MetadadosSyncEnvelope::abrir($corpoBruto, $headers, $segredo, $janela);
        if (!$envelope['ok']) {
            return ['http_status' => $envelope['http_status'], 'body' => $envelope['body']];
        }

        $validacao = MetadadosDimensaoSyncRequestValidator::validar($envelope['payload'], $maxBatch, $this->dimensao);
        if (!$validacao['ok']) {
            Logger::warning('Sincronização METADADOS recusada: payload inválido', [
                'dimensao' => $this->dimensao,
                'erros' => $validacao['errors'],
            ]);
            return ['http_status' => 400, 'body' => ['ok' => false, 'error' => 'Payload inválido.', 'detalhes' => $validacao['errors']]];
        }

        $correlacaoId = $validacao['correlacao_id'] ?? null;
        $inicio = new DateTimeImmutable();
        $hashLote = hash('sha256', $corpoBruto);

        try {
            $resumo = $this->syncService->applyRows($validacao['registros'], $validacao['origem']);
        } catch (Throwable $e) {
            // applyRows() só chega aqui por falha real de infraestrutura (transação abortada) —
            // erros de negócio por linha são contados em 'errors', não lançados. Logo: 500, não 4xx.
            Logger::exception($e, 'ERROR', ['endpoint' => "internal/metadados/{$this->dimensao}/sync"]);
            $this->registrarHistorico($correlacaoId, MetadadosSyncExecucaoRepository::STATUS_FALHA, [
                'dimensao' => $this->dimensao,
                'origem' => $validacao['origem'],
                'iniciado_em' => $inicio->format('Y-m-d H:i:s'),
                'concluido_em' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                'hash_lote' => $hashLote,
                'mensagem_tecnica' => MetadadosSyncExecucaoRepository::sanitizarMensagem($e->getMessage()),
            ]);
            return ['http_status' => 500, 'body' => ['ok' => false, 'error' => 'Falha interna ao aplicar a sincronização.']];
        }

        $erros = (int)($resumo['errors'] ?? 0);
        $recebidos = count($validacao['registros']);
        $this->registrarHistorico(
            $correlacaoId,
            $erros === 0 ? MetadadosSyncExecucaoRepository::STATUS_SUCESSO : MetadadosSyncExecucaoRepository::STATUS_SUCESSO_COM_ERROS,
            [
                'dimensao' => $this->dimensao,
                'origem' => $resumo['origem'] ?? $validacao['origem'],
                'iniciado_em' => $inicio->format('Y-m-d H:i:s'),
                'concluido_em' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                'hash_lote' => $hashLote,
                'registros_recebidos' => $recebidos,
                'inseridos' => (int)($resumo['inserted'] ?? 0) + (int)($resumo['adopted'] ?? 0),
                'atualizados' => (int)($resumo['updated'] ?? 0),
                'inalterados' => (int)($resumo['unchanged'] ?? 0),
                'erros' => $erros,
            ]
        );

        return [
            'http_status' => 200,
            'body' => [
                'ok' => $erros === 0,
                'dimensao' => $this->dimensao,
                'recebidos' => $recebidos,
                'inseridos' => (int)($resumo['inserted'] ?? 0),
                'adotados' => (int)($resumo['adopted'] ?? 0),
                'atualizados' => (int)($resumo['updated'] ?? 0),
                'inalterados' => (int)($resumo['unchanged'] ?? 0),
                'erros' => $erros,
                'avisos' => $resumo['avisos'] ?? [],
                'origem' => $resumo['origem'] ?? $validacao['origem'],
            ],
        ];
    }

    /**
     * Observabilidade — nunca parte do contrato: qualquer falha aqui é engolida com aviso, uma
     * sincronização já aplicada nunca é revertida por causa do histórico.
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
                'dimensao' => $this->dimensao,
                'erro' => $e->getMessage(),
            ]);
        }
    }
}
