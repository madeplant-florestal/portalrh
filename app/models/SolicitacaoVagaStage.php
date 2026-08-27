<?php
/**
 * Catálogo de etapas do Kanban de Solicitações de Vaga (situação operacional da vaga).
 *
 * Deliberadamente independente de PipelineStage (catálogo do Kanban de candidatos): são
 * ciclos de vida diferentes (ver app/services/SolicitacaoVagaPipelineService.php) e
 * pipeline_stages nem possui migration própria hoje (só existe via dump de produção).
 */
class SolicitacaoVagaStage
{
    private const CANONICAL_STAGES = [
        ['nome' => 'Em aprovação', 'slug' => 'em-aprovacao', 'ordem' => 1, 'cor' => '#f59e0b'],
        ['nome' => 'Aprovada', 'slug' => 'aprovada', 'ordem' => 2, 'cor' => '#1d4ed8'],
        ['nome' => 'Em recrutamento', 'slug' => 'em-recrutamento', 'ordem' => 3, 'cor' => '#0ea5e9'],
        ['nome' => 'Em processo seletivo', 'slug' => 'em-processo-seletivo', 'ordem' => 4, 'cor' => '#8b5cf6'],
        ['nome' => 'Fechada', 'slug' => 'fechada', 'ordem' => 5, 'cor' => '#059669'],
        ['nome' => 'Cancelada', 'slug' => 'cancelada', 'ordem' => 6, 'cor' => '#ef4444'],
    ];

    private static bool $ensured = false;

    public static function all(): array
    {
        self::ensureSchema();
        $stmt = Database::conn()->query('SELECT * FROM solicitacao_vaga_stages ORDER BY ordem ASC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function find(int $id): ?array
    {
        self::ensureSchema();
        $stmt = Database::conn()->prepare('SELECT * FROM solicitacao_vaga_stages WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function findBySlug(string $slug): ?array
    {
        self::ensureSchema();
        $stmt = Database::conn()->prepare('SELECT * FROM solicitacao_vaga_stages WHERE slug = ?');
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function defaultStageId(): int
    {
        self::ensureSchema();
        $stmt = Database::conn()->prepare('SELECT id FROM solicitacao_vaga_stages ORDER BY ordem ASC LIMIT 1');
        $stmt->execute();
        return (int)($stmt->fetchColumn() ?: 0);
    }

    /**
     * Rede de segurança para ambientes onde a migration 2026-08-25-solicitacao-vaga-kanban.sql
     * ainda não rodou (não há runner de migrations neste projeto — ver CLAUDE.md §3.7).
     */
    private static function ensureSchema(): void
    {
        if (self::$ensured) {
            return;
        }
        self::$ensured = true;

        $pdo = Database::conn();
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS solicitacao_vaga_stages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nome VARCHAR(60) NOT NULL,
                slug VARCHAR(50) NOT NULL,
                ordem INT NOT NULL DEFAULT 0,
                cor VARCHAR(7) NOT NULL DEFAULT '#cccccc',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_solicitacao_vaga_stages_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $insert = $pdo->prepare(
                'INSERT IGNORE INTO solicitacao_vaga_stages (nome, slug, ordem, cor) VALUES (?, ?, ?, ?)'
            );
            foreach (self::CANONICAL_STAGES as $stage) {
                $insert->execute([$stage['nome'], $stage['slug'], $stage['ordem'], $stage['cor']]);
            }
        } catch (Throwable $e) {
            // Mantém compatibilidade se o ambiente não puder criar a tabela em runtime.
        }
    }
}
