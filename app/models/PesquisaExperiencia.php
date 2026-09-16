<?php
/**
 * Pesquisa de Experiência do Candidato (Sprint "Histórico de Comunicação + Experiência do
 * Candidato" — migration 2026-09-16-pesquisa-experiencia.sql).
 *
 * Pertence à PARTICIPAÇÃO no processo (`candidatura_id`), nunca ao CPF/cadastro global do
 * candidato — o mesmo candidato pode responder uma pesquisa por processo em que participou.
 * `UNIQUE KEY` em `candidatura_id` garante 1 pesquisa por participação a nível de banco.
 *
 * Acesso público via TOKEN: só o HASH (`token_hash`, sha256) fica armazenado — mesmo padrão de
 * segurança de `PasswordReset` (nunca guardar o token bruto).
 */
class PesquisaExperiencia
{
    private static bool $ensured = false;

    public static function findByCandidatura(int $candidaturaId): ?array
    {
        self::ensureSchema();
        $stmt = Database::conn()->prepare('SELECT * FROM pesquisas_experiencia WHERE candidatura_id = ? LIMIT 1');
        $stmt->execute([$candidaturaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function find(int $id): ?array
    {
        self::ensureSchema();
        $stmt = Database::conn()->prepare('SELECT * FROM pesquisas_experiencia WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Usado pela página pública — nunca guarda/recebe o token bruto, só o hash para comparar. */
    public static function findByRawToken(string $rawToken): ?array
    {
        self::ensureSchema();
        $stmt = Database::conn()->prepare('SELECT * FROM pesquisas_experiencia WHERE token_hash = ? LIMIT 1');
        $stmt->execute([hash('sha256', $rawToken)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Cria a linha (chamado só por PesquisaExperienciaService::criarParaParticipacao(), que trata
     * idempotência). Deixa o UNIQUE KEY em candidatura_id como defesa em profundidade contra
     * corrida entre duas chamadas simultâneas.
     */
    public static function create(int $candidaturaId, string $tokenHash): int
    {
        self::ensureSchema();
        $stmt = Database::conn()->prepare(
            'INSERT INTO pesquisas_experiencia (candidatura_id, token_hash) VALUES (?, ?)'
        );
        $stmt->execute([$candidaturaId, $tokenHash]);
        return (int)Database::conn()->lastInsertId();
    }

    /**
     * Grava a resposta — rejeita se já havia sido respondida (token não pode responder duas
     * vezes). Notas: inteiro 1–5. Comentário opcional.
     *
     * @return array{ok:bool,error?:string}
     */
    public static function responder(int $id, int $notaClareza, int $notaTempoRetorno, int $notaAtendimento, ?string $comentarios): array
    {
        self::ensureSchema();
        $pesquisa = self::find($id);
        if ($pesquisa === null) {
            return ['ok' => false, 'error' => 'Pesquisa não encontrada.'];
        }
        if (!empty($pesquisa['respondida_em'])) {
            return ['ok' => false, 'error' => 'Esta avaliação já foi registrada.'];
        }
        foreach (['notaClareza' => $notaClareza, 'notaTempoRetorno' => $notaTempoRetorno, 'notaAtendimento' => $notaAtendimento] as $campo => $nota) {
            if ($nota < 1 || $nota > 5) {
                return ['ok' => false, 'error' => 'Cada nota deve ser um número inteiro de 1 a 5.'];
            }
        }
        $comentariosTratado = $comentarios !== null && trim($comentarios) !== '' ? trim($comentarios) : null;
        if ($comentariosTratado !== null && mb_strlen($comentariosTratado) > 2000) {
            return ['ok' => false, 'error' => 'Comentário muito longo (máximo 2000 caracteres).'];
        }

        $stmt = Database::conn()->prepare(
            'UPDATE pesquisas_experiencia
             SET nota_clareza = ?, nota_tempo_retorno = ?, nota_atendimento = ?, comentarios = ?, respondida_em = NOW()
             WHERE id = ? AND respondida_em IS NULL'
        );
        $stmt->execute([$notaClareza, $notaTempoRetorno, $notaAtendimento, $comentariosTratado, $id]);
        if ($stmt->rowCount() === 0) {
            // Corrida: outra requisição respondeu entre o find() e o UPDATE acima.
            return ['ok' => false, 'error' => 'Esta avaliação já foi registrada.'];
        }

        return ['ok' => true];
    }

    /**
     * Rede de segurança para ambientes onde a migration 2026-09-16-pesquisa-experiencia.sql ainda
     * não rodou (não há runner de migrations neste projeto — ver CLAUDE.md §3.7).
     */
    public static function ensureSchema(): void
    {
        if (self::$ensured) {
            return;
        }
        self::$ensured = true;

        Database::conn()->exec("CREATE TABLE IF NOT EXISTS pesquisas_experiencia (
            id INT AUTO_INCREMENT PRIMARY KEY,
            candidatura_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            nota_clareza TINYINT NULL,
            nota_tempo_retorno TINYINT NULL,
            nota_atendimento TINYINT NULL,
            comentarios TEXT NULL,
            respondida_em DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_pesquisas_experiencia_candidatura (candidatura_id),
            UNIQUE KEY uk_pesquisas_experiencia_token (token_hash),
            CONSTRAINT fk_pesquisas_experiencia_candidatura FOREIGN KEY (candidatura_id) REFERENCES candidaturas(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
