<?php

/**
 * Agendamento do Exame Admissional (pós-aceite da Carta Proposta) — UMA linha por candidatura;
 * reagendar é editar esta mesma linha (nunca criar outra, diferente de Carta Proposta que
 * versiona). SQL puro WHERE-guardado aqui; decisão de negócio (quando resetar status, quando
 * registrar evento) fica em ExameAdmissionalService — este repository só executa.
 */
class ExameAdmissionalRepository
{
    private static bool $ensured = false;
    private ?PDO $pdo = null;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    private function connection(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = Database::conn();
        }
        self::ensureSchema($this->pdo);
        return $this->pdo;
    }

    public function criar(int $candidaturaId, int $usuarioId): int
    {
        $stmt = $this->connection()->prepare(
            "INSERT INTO exames_admissionais (candidatura_id, status, criado_por_usuario_id) VALUES (?, 'rascunho', ?)"
        );
        $stmt->execute([$candidaturaId, $usuarioId]);
        return (int)$this->connection()->lastInsertId();
    }

    public function buscarPorCandidatura(int $candidaturaId): ?array
    {
        $stmt = $this->connection()->prepare('SELECT * FROM exames_admissionais WHERE candidatura_id = ? LIMIT 1');
        $stmt->execute([$candidaturaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->connection()->prepare(
            'SELECT ea.*, u1.nome AS criado_por_nome, u2.nome AS enviado_por_nome
             FROM exames_admissionais ea
             LEFT JOIN usuarios u1 ON u1.id = ea.criado_por_usuario_id
             LEFT JOIN usuarios u2 ON u2.id = ea.enviado_por_usuario_id
             WHERE ea.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @param array{clinica:?string,endereco:?string,data_exame:?string,horario:?string,telefone_contato:?string} $dados
     * $resetStatus true: a preparação/envio anterior é invalidada (volta para rascunho) porque um
     * dos 5 campos de agendamento mudou depois de já preparado/enviado.
     */
    public function salvarDados(int $id, array $dados, bool $resetStatus): bool
    {
        $sql = 'UPDATE exames_admissionais SET clinica = ?, endereco = ?, data_exame = ?, horario = ?, telefone_contato = ?';
        $params = [$dados['clinica'], $dados['endereco'], $dados['data_exame'], $dados['horario'], $dados['telefone_contato']];
        if ($resetStatus) {
            $sql .= ", status = 'rascunho', comunicacao_id = NULL, mensagem_preparada_em = NULL, enviado_em = NULL, enviado_por_usuario_id = NULL";
        }
        $sql .= ' WHERE id = ?';
        $params[] = $id;

        $stmt = $this->connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    /** rascunho|mensagem_preparada -> mensagem_preparada (idempotente: reabrir sem mudar dados não duplica). */
    public function marcarMensagemPreparada(int $id, int $comunicacaoId, string $agora): bool
    {
        $stmt = $this->connection()->prepare(
            "UPDATE exames_admissionais SET status = 'mensagem_preparada', comunicacao_id = ?, mensagem_preparada_em = ?
             WHERE id = ? AND status IN ('rascunho', 'mensagem_preparada')"
        );
        $stmt->execute([$comunicacaoId, $agora, $id]);
        return $stmt->rowCount() > 0;
    }

    /** mensagem_preparada -> enviado. Declaração manual do RH — nunca confirmação de entrega real. */
    public function confirmarEnvio(int $id, int $usuarioId, string $agora): bool
    {
        $stmt = $this->connection()->prepare(
            "UPDATE exames_admissionais SET status = 'enviado', enviado_em = ?, enviado_por_usuario_id = ?
             WHERE id = ? AND status = 'mensagem_preparada'"
        );
        $stmt->execute([$agora, $usuarioId, $id]);
        return $stmt->rowCount() > 0;
    }

    public function registrarEvento(int $exameId, string $tipoEvento, ?array $dadosAnteriores, ?array $dadosNovos, ?string $observacoes, ?int $usuarioId): void
    {
        $stmt = $this->connection()->prepare(
            'INSERT INTO exame_admissional_historico (exame_admissional_id, tipo_evento, dados_anteriores, dados_novos, observacoes, usuario_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $exameId,
            $tipoEvento,
            $dadosAnteriores !== null ? json_encode($dadosAnteriores, JSON_UNESCAPED_UNICODE) : null,
            $dadosNovos !== null ? json_encode($dadosNovos, JSON_UNESCAPED_UNICODE) : null,
            $observacoes,
            $usuarioId,
        ]);
    }

    public function historico(int $exameId): array
    {
        $stmt = $this->connection()->prepare(
            'SELECT eah.*, u.nome AS usuario_nome
             FROM exame_admissional_historico eah
             LEFT JOIN usuarios u ON u.id = eah.usuario_id
             WHERE eah.exame_admissional_id = ? ORDER BY eah.created_at DESC'
        );
        $stmt->execute([$exameId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Rede de segurança para ambientes onde a migration 2026-11-06-exames-admissionais.sql ainda
     * não rodou (não há runner de migrations neste projeto — ver CLAUDE.md §3.7). Mesmo padrão de
     * CartaPropostaRepository::ensureSchema().
     */
    public static function ensureSchema(PDO $pdo): void
    {
        if (self::$ensured) {
            return;
        }
        self::$ensured = true;

        // Dependência de FK: garante que `comunicacoes` já existe antes de criar esta tabela.
        Comunicacao::ensureSchema();

        $pdo->exec("CREATE TABLE IF NOT EXISTS exames_admissionais (
            id INT AUTO_INCREMENT PRIMARY KEY,
            candidatura_id INT NOT NULL,
            clinica VARCHAR(160) NULL,
            endereco VARCHAR(255) NULL,
            data_exame DATE NULL,
            horario TIME NULL,
            telefone_contato VARCHAR(30) NULL,
            status ENUM('rascunho','mensagem_preparada','enviado') NOT NULL DEFAULT 'rascunho',
            comunicacao_id INT NULL,
            mensagem_preparada_em DATETIME NULL,
            enviado_em DATETIME NULL,
            criado_por_usuario_id INT NOT NULL,
            enviado_por_usuario_id INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_exames_admissionais_candidatura (candidatura_id),
            KEY idx_exames_admissionais_status (status),
            CONSTRAINT fk_exames_admissionais_candidatura FOREIGN KEY (candidatura_id) REFERENCES candidaturas(id) ON DELETE CASCADE,
            CONSTRAINT fk_exames_admissionais_comunicacao FOREIGN KEY (comunicacao_id) REFERENCES comunicacoes(id) ON DELETE SET NULL,
            CONSTRAINT fk_exames_admissionais_criador FOREIGN KEY (criado_por_usuario_id) REFERENCES usuarios(id),
            CONSTRAINT fk_exames_admissionais_enviou FOREIGN KEY (enviado_por_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS exame_admissional_historico (
            id INT AUTO_INCREMENT PRIMARY KEY,
            exame_admissional_id INT NOT NULL,
            tipo_evento VARCHAR(30) NOT NULL,
            dados_anteriores TEXT NULL,
            dados_novos TEXT NULL,
            observacoes TEXT NULL,
            usuario_id INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_exame_hist_exame FOREIGN KEY (exame_admissional_id) REFERENCES exames_admissionais(id) ON DELETE CASCADE,
            CONSTRAINT fk_exame_hist_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
