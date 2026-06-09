<?php
class AuditLog
{
    public static function log(?int $actorUserId, ?int $targetUserId, string $action, ?string $details = null, ?string $ip = null): void
    {
        $sql = 'INSERT INTO auditoria_usuarios (actor_usuario_id, target_usuario_id, action, details, ip) VALUES (?, ?, ?, ?, ?)';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute([$actorUserId, $targetUserId, $action, $details, $ip]);
    }
}

