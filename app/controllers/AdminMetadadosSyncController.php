<?php

/**
 * Sincronização operacional do METADADOS sob demanda — camada Portal RH (Etapa 1).
 *
 * O Dashboard de Indicadores aciona `solicitar()`; o Portal cria a linha da solicitação em
 * metadados_sync_execucoes, dispara o webhook da camada de orquestração interna (n8n) com um
 * timeout curto e responde 202 na hora — NUNCA segura a requisição web enquanto a sincronização
 * inteira ocorre. A conclusão chega depois pelo receiver já existente
 * (POST /internal/metadados/colaboradores/sync), que fecha a mesma linha pelo correlacao_id.
 *
 * Este controller NUNCA acessa o SQL Server do METADADOS, nunca chama MetadadosDatabase::conn(),
 * nunca recebe credenciais de SQL Server. Só fala com o MySQL local e com o webhook do
 * orquestrador (cuja URL/segredo vivem só em local.php).
 */
class AdminMetadadosSyncController extends Controller
{
    /** Janela para considerar uma solicitação "em andamento" — impede execução simultânea. */
    private const JANELA_EM_ANDAMENTO_SEGUNDOS = 900;
    /** Após isto, uma solicitação que não concluiu é marcada como expirada na leitura de status. */
    private const EXPIRACAO_SEGUNDOS = 900;

    private const RL_MAX = 5;
    private const RL_JANELA = 600;
    private const RL_LOCKOUT = 300;

    public function solicitar(): void
    {
        Auth::requireRole(['admin', 'rh']);
        header('Content-Type: application/json; charset=utf-8');

        $input = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = [];
        }
        if (!Security::csrfCheck($input['csrf'] ?? '')) {
            http_response_code(400);
            echo json_encode(['erro' => 'Sessão expirada. Recarregue a página e tente de novo.']);
            return;
        }

        $config = Config::get()['metadados_sync'] ?? [];
        $orchestratorUrl = (string)($config['orchestrator_url'] ?? '');
        $orchestratorSecret = (string)($config['orchestrator_secret'] ?? '');
        if (!preg_match('#^https://#i', $orchestratorUrl) || $orchestratorSecret === '') {
            http_response_code(503);
            echo json_encode(['erro' => 'A sincronização sob demanda ainda não está configurada neste ambiente.']);
            return;
        }

        $usuarioId = (int)($_SESSION['user_id'] ?? 0) ?: null;

        $rl = Security::rateLimitCheck('metadados_sync_trigger', (string)($usuarioId ?? 'anon'), self::RL_MAX, self::RL_JANELA, self::RL_LOCKOUT);
        if ($rl['blocked']) {
            http_response_code(429);
            echo json_encode(['erro' => 'Muitas solicitações em pouco tempo. Aguarde alguns minutos antes de tentar de novo.']);
            return;
        }

        try {
            $repo = new MetadadosSyncExecucaoRepository();

            if ($repo->existeEmAndamento(self::JANELA_EM_ANDAMENTO_SEGUNDOS)) {
                http_response_code(429);
                echo json_encode(['erro' => 'Já existe uma sincronização em andamento. Aguarde a conclusão antes de solicitar outra.']);
                return;
            }

            $correlacaoId = self::uuidV4();
            $repo->criarSolicitacao($correlacaoId, $usuarioId, 'manual_dashboard');
        } catch (Throwable $e) {
            Logger::exception($e, 'ERROR', ['controller' => 'AdminMetadadosSyncController', 'acao' => 'solicitar']);
            http_response_code(500);
            echo json_encode(['erro' => 'Não foi possível registrar a solicitação de sincronização agora.']);
            return;
        }

        Security::rateLimitHit($rl['file'], $rl['data'], false, self::RL_LOCKOUT, self::RL_MAX, self::RL_JANELA);

        $disparo = $this->dispararOrquestrador($orchestratorUrl, $orchestratorSecret, $correlacaoId);
        if (!$disparo['ok']) {
            try {
                $repo->registrarResultado($correlacaoId, MetadadosSyncExecucaoRepository::STATUS_FALHA, [
                    'concluido_em' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                    'mensagem_tecnica' => MetadadosSyncExecucaoRepository::sanitizarMensagem($disparo['erro']),
                ]);
            } catch (Throwable $e) {
                Logger::warning('Falha ao registrar erro de disparo do orquestrador', ['erro' => $e->getMessage()]);
            }
            Logger::error('Não foi possível acionar o orquestrador de sincronização do METADADOS', ['motivo' => $disparo['erro']]);
            http_response_code(502);
            echo json_encode([
                'erro' => 'Não foi possível acionar o serviço de sincronização agora. Tente novamente em alguns minutos.',
                'correlacao_id' => $correlacaoId,
            ]);
            return;
        }

        http_response_code(202);
        echo json_encode(['correlacao_id' => $correlacaoId, 'status' => MetadadosSyncExecucaoRepository::STATUS_SOLICITADA]);
    }

    public function status(): void
    {
        Auth::requireRole(['admin', 'rh']);
        header('Content-Type: application/json; charset=utf-8');

        $correlacaoId = Security::sanitizeString($_GET['correlacao_id'] ?? '');
        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $correlacaoId)) {
            http_response_code(400);
            echo json_encode(['erro' => 'Identificador inválido.']);
            return;
        }
        $correlacaoId = strtolower($correlacaoId);

        try {
            $repo = new MetadadosSyncExecucaoRepository();
            $repo->marcarExpiradas(self::EXPIRACAO_SEGUNDOS);
            $exec = $repo->buscarPorCorrelacao($correlacaoId);
            $ultima = $repo->ultimaSincronizacaoValida();
        } catch (Throwable $e) {
            Logger::exception($e, 'ERROR', ['controller' => 'AdminMetadadosSyncController', 'acao' => 'status']);
            http_response_code(500);
            echo json_encode(['erro' => 'Não foi possível consultar o andamento da sincronização.']);
            return;
        }

        if ($exec === null) {
            http_response_code(404);
            echo json_encode(['erro' => 'Solicitação não encontrada.']);
            return;
        }

        $status = (string)$exec['status'];
        $terminal = in_array($status, [
            MetadadosSyncExecucaoRepository::STATUS_SUCESSO,
            MetadadosSyncExecucaoRepository::STATUS_SUCESSO_COM_ERROS,
            MetadadosSyncExecucaoRepository::STATUS_FALHA,
            MetadadosSyncExecucaoRepository::STATUS_EXPIRADA,
        ], true);
        $sucesso = in_array($status, [
            MetadadosSyncExecucaoRepository::STATUS_SUCESSO,
            MetadadosSyncExecucaoRepository::STATUS_SUCESSO_COM_ERROS,
        ], true);

        echo json_encode([
            'status' => $status,
            'concluido' => $terminal,
            'sucesso' => $sucesso,
            'mensagem' => $sucesso ? null : MetadadosSyncExecucaoRepository::sanitizarMensagem($exec['mensagem_tecnica'] ?? null),
            'contadores' => ($terminal && $sucesso) ? [
                'recebidos' => (int)$exec['registros_recebidos'],
                'inseridos' => (int)$exec['inseridos'],
                'atualizados' => (int)$exec['atualizados'],
                'inalterados' => (int)$exec['inalterados'],
                'erros' => (int)$exec['erros'],
            ] : null,
            'ultima_atualizacao' => self::formatarData($ultima['concluido_em'] ?? null),
        ], JSON_UNESCAPED_UNICODE);
    }

    /** @return array{ok:bool, erro:?string} */
    private function dispararOrquestrador(string $url, string $secret, string $correlacaoId): array
    {
        $corpo = json_encode([
            'correlacao_id' => $correlacaoId,
            'solicitado_em' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            'gatilho' => 'manual_dashboard',
            'origem_esperada' => 'RHMADEPLANT',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $timestamp = (string)time();
        $assinatura = MetadadosSyncSignature::assinar($timestamp, $corpo, $secret);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $corpo,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                MetadadosSyncSignature::HEADER_TIMESTAMP . ': ' . $timestamp,
                MetadadosSyncSignature::HEADER_SIGNATURE . ': ' . $assinatura,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $resposta = curl_exec($ch);
        if ($resposta === false) {
            $erro = curl_error($ch);
            curl_close($ch);
            return ['ok' => false, 'erro' => 'Rede ao acionar o orquestrador: ' . $erro];
        }
        $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($codigo < 200 || $codigo >= 300) {
            return ['ok' => false, 'erro' => 'Orquestrador respondeu HTTP ' . $codigo . '.'];
        }
        return ['ok' => true, 'erro' => null];
    }

    private static function formatarData(?string $dataHora): ?string
    {
        if ($dataHora === null || $dataHora === '') {
            return null;
        }
        try {
            $d = new DateTimeImmutable($dataHora);
        } catch (Throwable $e) {
            return null;
        }
        return $d->format('d/m/Y') . ' às ' . $d->format('H:i');
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
