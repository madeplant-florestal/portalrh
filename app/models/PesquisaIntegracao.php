<?php

/**
 * Pesquisa de Integração (Onboarding) — Sprint "Fundação do Dashboard de Integração" (migration
 * 2026-09-17-pesquisa-integracao.sql).
 *
 * Vínculo canônico: `colaborador_id` (FK -> colaboradores.id) — nunca candidatura, CPF ou e-mail.
 * `integracao_status = 'realizada'` em `colaboradores` continua sendo a definição oficial de
 * integração concluída (dado operacional do Portal); `colaboradores.metadados_id` NÃO é exigido.
 *
 * 1 pesquisa por EVENTO de integração: `UNIQUE KEY` em (colaborador_id,
 * integracao_data_relacionada) — ver PesquisaIntegracaoService::criarParaIntegracao() para a
 * idempotência completa (findByColaboradorEData() antes do INSERT).
 *
 * Acesso público via TOKEN: só o HASH (`token_hash`, sha256) fica armazenado — mesmo padrão de
 * segurança de `PasswordReset`/`PesquisaExperiencia`.
 */
class PesquisaIntegracao
{
    private static bool $ensured = false;

    public static function findByColaboradorEData(int $colaboradorId, string $integracaoData): ?array
    {
        self::ensureSchema();
        $stmt = Database::conn()->prepare(
            'SELECT * FROM pesquisas_integracao WHERE colaborador_id = ? AND integracao_data_relacionada = ? LIMIT 1'
        );
        $stmt->execute([$colaboradorId, $integracaoData]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function find(int $id): ?array
    {
        self::ensureSchema();
        $stmt = Database::conn()->prepare('SELECT * FROM pesquisas_integracao WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Usado pela página pública — nunca guarda/recebe o token bruto, só o hash para comparar. */
    public static function findByRawToken(string $rawToken): ?array
    {
        self::ensureSchema();
        $stmt = Database::conn()->prepare('SELECT * FROM pesquisas_integracao WHERE token_hash = ? LIMIT 1');
        $stmt->execute([hash('sha256', $rawToken)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Cria a linha (chamado só por PesquisaIntegracaoService::criarParaIntegracao(), que trata
     * idempotência). O UNIQUE KEY em (colaborador_id, integracao_data_relacionada) fica como
     * defesa em profundidade contra corrida entre duas chamadas simultâneas.
     */
    public static function create(int $colaboradorId, string $integracaoData, string $tokenHash): int
    {
        self::ensureSchema();
        $stmt = Database::conn()->prepare(
            'INSERT INTO pesquisas_integracao (colaborador_id, integracao_data_relacionada, token_hash) VALUES (?, ?, ?)'
        );
        $stmt->execute([$colaboradorId, $integracaoData, $tokenHash]);
        return (int)Database::conn()->lastInsertId();
    }

    /**
     * Grava a resposta — rejeita se já havia sido respondida (impede alteração posterior pelo
     * mesmo link, mesma regra de PesquisaExperiencia::responder()). NPS: inteiro 0-10. Satisfação:
     * inteiro 1-5 nas 5 perguntas. Comentário opcional.
     *
     * @return array{ok:bool,error?:string}
     */
    public static function responder(
        int $id,
        int $notaNps,
        int $notaClareza,
        int $notaAcolhimento,
        int $notaNormas,
        int $notaUtilidade,
        int $notaSatisfacaoGeral,
        ?string $comentarios
    ): array {
        self::ensureSchema();
        $pesquisa = self::find($id);
        if ($pesquisa === null) {
            return ['ok' => false, 'error' => 'Pesquisa não encontrada.'];
        }
        if (!empty($pesquisa['respondida_em'])) {
            return ['ok' => false, 'error' => 'Esta avaliação já foi registrada.'];
        }
        if ($notaNps < 0 || $notaNps > 10) {
            return ['ok' => false, 'error' => 'A nota de recomendação (NPS) deve ser um número inteiro de 0 a 10.'];
        }
        foreach ([
            'clareza' => $notaClareza,
            'acolhimento' => $notaAcolhimento,
            'normas' => $notaNormas,
            'utilidade' => $notaUtilidade,
            'satisfacao geral' => $notaSatisfacaoGeral,
        ] as $nota) {
            if ($nota < 1 || $nota > 5) {
                return ['ok' => false, 'error' => 'Cada pergunta de satisfação deve ser um número inteiro de 1 a 5.'];
            }
        }
        $comentariosTratado = $comentarios !== null && trim($comentarios) !== '' ? trim($comentarios) : null;
        if ($comentariosTratado !== null && mb_strlen($comentariosTratado) > 2000) {
            return ['ok' => false, 'error' => 'Comentário muito longo (máximo 2000 caracteres).'];
        }

        $stmt = Database::conn()->prepare(
            'UPDATE pesquisas_integracao
             SET nota_nps = ?, nota_clareza = ?, nota_acolhimento = ?, nota_normas = ?,
                 nota_utilidade = ?, nota_satisfacao_geral = ?, comentarios = ?, respondida_em = NOW()
             WHERE id = ? AND respondida_em IS NULL'
        );
        $stmt->execute([
            $notaNps, $notaClareza, $notaAcolhimento, $notaNormas, $notaUtilidade, $notaSatisfacaoGeral,
            $comentariosTratado, $id,
        ]);
        if ($stmt->rowCount() === 0) {
            // Corrida: outra requisição respondeu entre o find() e o UPDATE acima.
            return ['ok' => false, 'error' => 'Esta avaliação já foi registrada.'];
        }

        return ['ok' => true];
    }

    /**
     * Rede de segurança para ambientes onde a migration 2026-09-17-pesquisa-integracao.sql ainda
     * não rodou (não há runner de migrations neste projeto — ver CLAUDE.md §3.7).
     */
    public static function ensureSchema(): void
    {
        if (self::$ensured) {
            return;
        }
        self::$ensured = true;

        Database::conn()->exec("CREATE TABLE IF NOT EXISTS pesquisas_integracao (
            id INT AUTO_INCREMENT PRIMARY KEY,
            colaborador_id INT NOT NULL,
            integracao_data_relacionada DATE NOT NULL,
            token_hash CHAR(64) NOT NULL,
            nota_nps TINYINT NULL,
            nota_clareza TINYINT NULL,
            nota_acolhimento TINYINT NULL,
            nota_normas TINYINT NULL,
            nota_utilidade TINYINT NULL,
            nota_satisfacao_geral TINYINT NULL,
            comentarios TEXT NULL,
            respondida_em DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_pesquisas_integracao_evento (colaborador_id, integracao_data_relacionada),
            UNIQUE KEY uk_pesquisas_integracao_token (token_hash),
            KEY idx_pesquisas_integracao_colaborador (colaborador_id),
            CONSTRAINT fk_pesquisas_integracao_colaborador FOREIGN KEY (colaborador_id) REFERENCES colaboradores(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
