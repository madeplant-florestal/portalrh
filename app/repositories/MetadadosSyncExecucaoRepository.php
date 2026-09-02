<?php

/**
 * Histórico operacional das sincronizações do METADADOS recebidas pelo Portal
 * (ver database/migrations/2026-09-01-metadados-sync-execucoes.sql).
 *
 * Geração nova (Repository puro, como ColaboradorMetadadosRepository): recebe o PDO por
 * construtor para ser testável, expõe métodos de escrita/leitura com prepared statements
 * posicionais. NUNCA grava segredo, senha, payload, CPF, nome ou salário — só contagens,
 * origem, horários, status e o hash do lote.
 *
 * O registro do histórico é OBSERVABILIDADE, não parte do contrato de sincronização: quem
 * chama (MetadadosSyncIngestService) deve isolar falhas de gravação aqui para nunca quebrar
 * uma sincronização que já foi aplicada com sucesso.
 */
class MetadadosSyncExecucaoRepository
{
    public const STATUS_SOLICITADA = 'solicitada';
    public const STATUS_PROCESSANDO = 'processando';
    public const STATUS_SUCESSO = 'sucesso';
    public const STATUS_SUCESSO_COM_ERROS = 'sucesso_com_erros';
    public const STATUS_FALHA = 'falha';
    public const STATUS_EXPIRADA = 'expirada';

    /** Colunas que registrarResultado() aceita atualizar/inserir — whitelist, nunca vem do request. */
    private const CAMPOS_RESULTADO = [
        'origem', 'iniciado_em', 'concluido_em', 'registros_recebidos', 'inseridos',
        'atualizados', 'inalterados', 'erros', 'hash_lote', 'mensagem_tecnica',
    ];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::conn();
    }

    public function connection(): PDO
    {
        return $this->pdo;
    }

    /**
     * Cria a linha da solicitação de sincronização manual disparada pelo Dashboard.
     * @return int id da linha criada.
     */
    public function criarSolicitacao(string $correlacaoId, ?int $usuarioId, string $gatilho): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO metadados_sync_execucoes
                (correlacao_id, gatilho, solicitado_por_usuario_id, status, solicitado_em)
             VALUES (?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$correlacaoId, $gatilho, $usuarioId, self::STATUS_SOLICITADA]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Há uma sincronização ainda em aberto (solicitada/processando) dentro da janela? Usado para
     * impedir múltiplas execuções simultâneas. Fora da janela, considera-se abandonada e não
     * bloqueia mais (marcarExpiradas() faz a limpeza formal na leitura de status).
     */
    public function existeEmAndamento(int $janelaSegundos): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM metadados_sync_execucoes
             WHERE status IN ('" . self::STATUS_SOLICITADA . "', '" . self::STATUS_PROCESSANDO . "')
               AND COALESCE(solicitado_em, created_at) >= (NOW() - INTERVAL ? SECOND)"
        );
        $stmt->execute([$janelaSegundos]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function buscarPorCorrelacao(string $correlacaoId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM metadados_sync_execucoes WHERE correlacao_id = ? ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$correlacaoId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Fecha (ou registra) o resultado de uma sincronização.
     *
     * Se `correlacaoId` casar com uma linha ainda ABERTA (solicitada/processando), atualiza
     * aquela linha — é a solicitação manual do Dashboard sendo concluída pelo receiver. Caso
     * contrário insere uma linha nova (sincronização iniciada fora do Portal: CLI, agendamento).
     *
     * @param array<string,mixed> $dados Subconjunto de self::CAMPOS_RESULTADO. Chaves fora da
     *                                   whitelist são ignoradas.
     */
    public function registrarResultado(?string $correlacaoId, string $status, array $dados): void
    {
        $campos = ['status' => $status];
        foreach (self::CAMPOS_RESULTADO as $campo) {
            if (array_key_exists($campo, $dados)) {
                $campos[$campo] = $dados[$campo];
            }
        }

        if ($correlacaoId !== null && $correlacaoId !== '') {
            $stmt = $this->pdo->prepare(
                "SELECT id FROM metadados_sync_execucoes
                 WHERE correlacao_id = ? AND status IN ('" . self::STATUS_SOLICITADA . "', '" . self::STATUS_PROCESSANDO . "')
                 ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([$correlacaoId]);
            $idAberto = $stmt->fetchColumn();
            if ($idAberto !== false) {
                $sets = [];
                $valores = [];
                foreach ($campos as $coluna => $valor) {
                    $sets[] = "{$coluna} = ?";
                    $valores[] = $valor;
                }
                $valores[] = (int)$idAberto;
                $this->pdo->prepare(
                    "UPDATE metadados_sync_execucoes SET " . implode(', ', $sets) . " WHERE id = ?"
                )->execute($valores);
                return;
            }
        }

        $campos['correlacao_id'] = $correlacaoId ?: null;
        $campos['gatilho'] = 'desconhecido';
        $colunas = array_keys($campos);
        $this->pdo->prepare(
            "INSERT INTO metadados_sync_execucoes (" . implode(', ', $colunas) . ")
             VALUES (" . implode(', ', array_fill(0, count($colunas), '?')) . ")"
        )->execute(array_values($campos));
    }

    /**
     * Marca como expirada toda solicitação/processamento que ficou aberto além da janela.
     * @return int linhas afetadas.
     */
    public function marcarExpiradas(int $janelaSegundos): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE metadados_sync_execucoes
             SET status = '" . self::STATUS_EXPIRADA . "',
                 concluido_em = NOW(),
                 mensagem_tecnica = COALESCE(mensagem_tecnica, 'A sincronização não foi concluída dentro do tempo esperado.')
             WHERE status IN ('" . self::STATUS_SOLICITADA . "', '" . self::STATUS_PROCESSANDO . "')
               AND COALESCE(solicitado_em, created_at) < (NOW() - INTERVAL ? SECOND)"
        );
        $stmt->execute([$janelaSegundos]);
        return $stmt->rowCount();
    }

    /**
     * Última sincronização VÁLIDA (sucesso ou sucesso com erros por linha) — fonte oficial do
     * "Última atualização" do Dashboard. Nunca deriva de updated_at de colaborador.
     */
    public function ultimaSincronizacaoValida(): ?array
    {
        $stmt = $this->pdo->query(
            "SELECT * FROM metadados_sync_execucoes
             WHERE status IN ('" . self::STATUS_SUCESSO . "', '" . self::STATUS_SUCESSO_COM_ERROS . "')
               AND concluido_em IS NOT NULL
             ORDER BY concluido_em DESC, id DESC LIMIT 1"
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Sanitiza texto técnico antes de persistir/exibir: colapsa espaços, substitui qualquer
     * sequência hexadecimal longa (possível segredo/hash bruto) por [omitido] e trunca em 500.
     */
    public static function sanitizarMensagem(?string $mensagem): ?string
    {
        if ($mensagem === null) {
            return null;
        }
        $mensagem = trim($mensagem);
        if ($mensagem === '') {
            return null;
        }
        $mensagem = preg_replace('/[A-Fa-f0-9]{24,}/', '[omitido]', $mensagem);
        $mensagem = preg_replace('/\s+/', ' ', (string)$mensagem);
        return mb_substr((string)$mensagem, 0, 500);
    }
}
