<?php
class PasswordReset
{
    public static function create(int $userId, string $rawToken, int $ttlMinutes = 30): void
    {
        self::invalidateOpenTokens($userId);
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = (new DateTimeImmutable('now +' . $ttlMinutes . ' minutes'))->format('Y-m-d H:i:s');
        $sql = 'INSERT INTO password_resets (usuario_id, token_hash, expires_at) VALUES (?, ?, ?)';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute([$userId, $tokenHash, $expiresAt]);
    }

    public static function findValidByToken(string $rawToken): ?array
    {
        $tokenHash = hash('sha256', $rawToken);
        $sql = 'SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function markUsed(int $id): void
    {
        $sql = 'UPDATE password_resets SET used_at = NOW() WHERE id = ?';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute([$id]);
    }

    public static function invalidateOpenTokens(int $userId): void
    {
        $sql = 'UPDATE password_resets SET used_at = NOW() WHERE usuario_id = ? AND used_at IS NULL';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute([$userId]);
    }
}

