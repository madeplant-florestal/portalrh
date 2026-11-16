<?php
class PasswordReset
{
    /**
     * Instante de criação e expiração no MESMO referencial (UTC, via DateHelper) — a validação compara com o
     * "agora" também em UTC (findValidByToken). Antes o `expires_at` vinha do relógio/fuso do PHP e era comparado
     * com o NOW() do banco: um servidor PHP em outro fuso encurtava ou alongava a validade do token. A duração
     * (TTL) não muda. `$agora` existe para teste.
     */
    public static function create(int $userId, string $rawToken, int $ttlMinutes = 30, ?DateTimeImmutable $agora = null): void
    {
        self::invalidateOpenTokens($userId);
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = DateHelper::paraUtcSql(($agora ?? DateHelper::agoraUtc())->modify('+' . $ttlMinutes . ' minutes'));
        $sql = 'INSERT INTO password_resets (usuario_id, token_hash, expires_at) VALUES (?, ?, ?)';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute([$userId, $tokenHash, $expiresAt]);
    }

    public static function findValidByToken(string $rawToken, ?DateTimeImmutable $agora = null): ?array
    {
        $tokenHash = hash('sha256', $rawToken);
        $sql = 'SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > ? ORDER BY created_at DESC LIMIT 1';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute([$tokenHash, DateHelper::paraUtcSql($agora ?? DateHelper::agoraUtc())]);
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

