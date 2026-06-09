<?php
class PipelineStage
{
    public static function all(): array
    {
        $sql = 'SELECT * FROM pipeline_stages ORDER BY ordem ASC';
        $stmt = Database::conn()->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function find(int $id): ?array
    {
        $sql = 'SELECT * FROM pipeline_stages WHERE id = ?';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute([$id]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ?: null;
    }
}
