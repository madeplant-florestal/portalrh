<?php
class PipelineStage
{
    private const CANONICAL_RECRUITMENT_STAGES = [
        ['nome' => 'Nova Inscrição', 'slug' => 'nova-inscricao', 'ordem' => 1, 'cor' => '#3b82f6', 'aliases' => ['nova inscricao', 'novo']],
        ['nome' => 'Triagem RH', 'slug' => 'triagem-rh', 'ordem' => 2, 'cor' => '#f59e0b', 'aliases' => ['triagem rh', 'triagem']],
        ['nome' => 'Entrevista RH', 'slug' => 'entrevista-rh', 'ordem' => 3, 'cor' => '#8b5cf6', 'aliases' => ['entrevista rh', 'entrevista']],
        ['nome' => 'Entrevista Gestor', 'slug' => 'entrevista-gestor', 'ordem' => 4, 'cor' => '#6366f1', 'aliases' => ['entrevista gestor']],
        ['nome' => 'Testes', 'slug' => 'testes', 'ordem' => 5, 'cor' => '#0ea5e9', 'aliases' => ['testes', 'teste']],
        ['nome' => 'Aprovado', 'slug' => 'aprovado', 'ordem' => 6, 'cor' => '#1d4ed8', 'aliases' => ['aprovado', 'proposta']],
        ['nome' => 'Admissão', 'slug' => 'admissao', 'ordem' => 7, 'cor' => '#059669', 'aliases' => ['admissao', 'contratado']],
        ['nome' => 'Banco de Talentos', 'slug' => 'banco-de-talentos', 'ordem' => 8, 'cor' => '#7c3aed', 'aliases' => ['banco de talentos', 'banco talentos']],
        ['nome' => 'Reprovado', 'slug' => 'reprovado', 'ordem' => 9, 'cor' => '#ef4444', 'aliases' => ['reprovado', 'rejeitado']],
    ];

    private static bool $recruitmentLifecycleEnsured = false;

    public static function all(): array
    {
        self::ensureRecruitmentLifecycle();
        $sql = 'SELECT * FROM pipeline_stages ORDER BY ordem ASC';
        $stmt = Database::conn()->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function find(int $id): ?array
    {
        self::ensureRecruitmentLifecycle();
        $sql = 'SELECT * FROM pipeline_stages WHERE id = ?';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute([$id]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ?: null;
    }

    public static function defaultStageId(): int
    {
        self::ensureRecruitmentLifecycle();
        $stmt = Database::conn()->prepare('SELECT id FROM pipeline_stages ORDER BY ordem ASC LIMIT 1');
        $stmt->execute();
        return (int)($stmt->fetchColumn() ?: 0);
    }

    public static function ensureRecruitmentLifecycle(): void
    {
        if (self::$recruitmentLifecycleEnsured) {
            return;
        }
        self::$recruitmentLifecycleEnsured = true;

        try {
            $pdo = Database::conn();
            $check = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $check->execute(['pipeline_stages']);
            if ((int)$check->fetchColumn() === 0) {
                return;
            }

            self::ensureSlugColumn($pdo);

            $existing = $pdo->query('SELECT id, nome, slug, ordem, cor FROM pipeline_stages ORDER BY ordem ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
            if ($existing === []) {
                $insert = $pdo->prepare('INSERT INTO pipeline_stages (nome, slug, ordem, cor) VALUES (?, ?, ?, ?)');
                foreach (self::CANONICAL_RECRUITMENT_STAGES as $stage) {
                    $insert->execute([$stage['nome'], $stage['slug'], $stage['ordem'], $stage['cor']]);
                }
                return;
            }

            $usedIds = [];
            $update = $pdo->prepare('UPDATE pipeline_stages SET nome = ?, slug = ?, ordem = ?, cor = ? WHERE id = ?');
            $insert = $pdo->prepare('INSERT INTO pipeline_stages (nome, slug, ordem, cor) VALUES (?, ?, ?, ?)');

            foreach (self::CANONICAL_RECRUITMENT_STAGES as $stage) {
                $matched = self::findMatchingStage($existing, $stage['aliases'], $usedIds);
                if ($matched) {
                    $update->execute([$stage['nome'], $stage['slug'], $stage['ordem'], $stage['cor'], (int)$matched['id']]);
                    $usedIds[] = (int)$matched['id'];
                    continue;
                }

                $insert->execute([$stage['nome'], $stage['slug'], $stage['ordem'], $stage['cor']]);
            }
        } catch (Throwable $e) {
            // Mantém compatibilidade se o ambiente não puder ajustar o catálogo em runtime.
        }
    }

    private static function ensureSlugColumn(PDO $pdo): void
    {
        $check = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $check->execute(['pipeline_stages', 'slug']);
        if ((int)$check->fetchColumn() > 0) {
            return;
        }
        $pdo->exec('ALTER TABLE pipeline_stages ADD COLUMN slug VARCHAR(50) NULL AFTER nome');
        try {
            $pdo->exec('ALTER TABLE pipeline_stages ADD UNIQUE KEY uk_pipeline_stages_slug (slug)');
        } catch (Throwable $e) {
            // Ignora se o índice já existir por outro caminho de migração.
        }
    }

    private static function findMatchingStage(array $existing, array $aliases, array $usedIds): ?array
    {
        foreach ($existing as $row) {
            $rowId = (int)($row['id'] ?? 0);
            if ($rowId <= 0 || in_array($rowId, $usedIds, true)) {
                continue;
            }

            $normalized = self::normalizeName((string)($row['nome'] ?? ''));
            foreach ($aliases as $alias) {
                if ($normalized === self::normalizeName($alias)) {
                    return $row;
                }
            }
        }

        return null;
    }

    public static function normalizeName(string $value): string
    {
        $value = trim(mb_strtolower($value, 'UTF-8'));
        $replace = [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c',
        ];
        $value = strtr($value, $replace);
        return preg_replace('/\s+/', ' ', $value) ?? $value;
    }
}
