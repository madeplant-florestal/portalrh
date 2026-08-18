<?php
class RecruitmentWebhookSettingRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        RecruitmentWebhookSchemaService::ensureSchema();
        $this->pdo = $pdo ?? Database::conn();
    }

    public function connection(): PDO
    {
        return $this->pdo;
    }

    public function get(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, enabled, webhook_url, webhook_secret_encrypted, created_at, updated_at
             FROM recruitment_webhook_global_settings
             WHERE id = 1
             LIMIT 1'
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: ['id' => 1, 'enabled' => 0, 'webhook_url' => null, 'webhook_secret_encrypted' => null];
    }

    public function getSecret(): ?string
    {
        return Cipher::decrypt($this->get()['webhook_secret_encrypted'] ?? null);
    }

    public function hasSecret(): bool
    {
        return !empty($this->get()['webhook_secret_encrypted']);
    }

    public function save(bool $enabled, ?string $webhookUrl): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE recruitment_webhook_global_settings SET enabled = ?, webhook_url = ? WHERE id = 1'
        );
        $stmt->execute([$enabled ? 1 : 0, $webhookUrl]);
    }

    public function generateSecret(): string
    {
        $plaintext = bin2hex(random_bytes(32));
        $encrypted = Cipher::encrypt($plaintext);

        $stmt = $this->pdo->prepare(
            'UPDATE recruitment_webhook_global_settings SET webhook_secret_encrypted = ? WHERE id = 1'
        );
        $stmt->execute([$encrypted]);

        return $plaintext;
    }
}
