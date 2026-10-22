<?php

/**
 * Regras evento/etapa → modelo de mensagem (Configurações → Integrações, Etapa 3, 2026-10). Só
 * guarda a ASSOCIAÇÃO — texto/variáveis/canal de template continuam em `mensagens`
 * (ver app/models/Mensagem.php). Ver migration 2026-10-22-integracao-regras-comunicacao.sql.
 */
class IntegracaoRegraComunicacaoRepository
{
    private static bool $ensured = false;
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        self::ensureSchema();
        $this->pdo = $pdo ?? Database::conn();
    }

    public function connection(): PDO
    {
        return $this->pdo;
    }

    /** Listagem para a tela, já com os dados de exibição de etapa/mensagem (sem duplicar texto). */
    public function all(): array
    {
        $stmt = $this->pdo->query(
            'SELECT r.*, ps.nome AS etapa_nome, ps.slug AS etapa_slug,
                    m.titulo AS mensagem_titulo, m.codigo AS mensagem_codigo, m.ativo AS mensagem_ativo
             FROM integracao_regras_comunicacao r
             LEFT JOIN pipeline_stages ps ON ps.id = r.pipeline_stage_id
             JOIN mensagens m ON m.id = r.mensagem_id
             ORDER BY r.event_type ASC, ps.ordem ASC, r.canal ASC'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM integracao_regras_comunicacao WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Autoridade real da unicidade (cobre também `pipeline_stage_id` NULL, caso em que o
     * UNIQUE KEY do banco não protege por si só — ver comentário na migration).
     */
    public function existeConflito(string $eventType, ?int $pipelineStageId, string $canal, ?int $excetoId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM integracao_regras_comunicacao WHERE event_type = ? AND canal = ? AND '
            . ($pipelineStageId === null ? 'pipeline_stage_id IS NULL' : 'pipeline_stage_id = ?');
        $params = [$eventType, $canal];
        if ($pipelineStageId !== null) {
            $params[] = $pipelineStageId;
        }
        if ($excetoId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excetoId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO integracao_regras_comunicacao (event_type, pipeline_stage_id, mensagem_id, canal, ativo)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['event_type'],
            $data['pipeline_stage_id'],
            $data['mensagem_id'],
            $data['canal'],
            !empty($data['ativo']) ? 1 : 0,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE integracao_regras_comunicacao
             SET event_type = ?, pipeline_stage_id = ?, mensagem_id = ?, canal = ?, ativo = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $data['event_type'],
            $data['pipeline_stage_id'],
            $data['mensagem_id'],
            $data['canal'],
            !empty($data['ativo']) ? 1 : 0,
            $id,
        ]);
    }

    private static function ensureSchema(): void
    {
        if (self::$ensured) {
            return;
        }
        self::$ensured = true;

        Database::conn()->exec("CREATE TABLE IF NOT EXISTS integracao_regras_comunicacao (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_type VARCHAR(80) NOT NULL,
            pipeline_stage_id INT NULL,
            mensagem_id INT NOT NULL,
            canal VARCHAR(20) NOT NULL DEFAULT 'whatsapp',
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_integracao_regras_combo (event_type, pipeline_stage_id, canal),
            KEY idx_integracao_regras_mensagem (mensagem_id),
            KEY idx_integracao_regras_event_type (event_type),
            CONSTRAINT fk_integracao_regras_mensagem FOREIGN KEY (mensagem_id) REFERENCES mensagens(id) ON DELETE RESTRICT,
            CONSTRAINT fk_integracao_regras_stage FOREIGN KEY (pipeline_stage_id) REFERENCES pipeline_stages(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
