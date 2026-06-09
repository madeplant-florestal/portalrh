<?php
class SchemaManager
{
    private static bool $initialized = false;

    public static function ensure(): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        $pdo = Database::conn();

        $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            token_hash VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_password_reset_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
            INDEX idx_password_resets_usuario (usuario_id),
            INDEX idx_password_resets_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS auditoria_usuarios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            actor_usuario_id INT NULL,
            target_usuario_id INT NULL,
            action VARCHAR(80) NOT NULL,
            details TEXT NULL,
            ip VARCHAR(45) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_auditoria_actor FOREIGN KEY (actor_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
            CONSTRAINT fk_auditoria_target FOREIGN KEY (target_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
            INDEX idx_auditoria_action (action),
            INDEX idx_auditoria_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $checkSupervisor = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'is_supervisor'");
        if ((int)$checkSupervisor->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN is_supervisor TINYINT(1) NOT NULL DEFAULT 0");
        }

        $checkVerified = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'email_verified_at'");
        if ((int)$checkVerified->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN email_verified_at DATETIME NULL");
        }

        $checkResetAt = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'last_password_reset_at'");
        if ((int)$checkResetAt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN last_password_reset_at DATETIME NULL");
        }
    }
}

