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

    public function findByScopeKey(string $scopeKey): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, scope_key, empresa_id, enabled, webhook_url, created_at, updated_at
             FROM recruitment_webhook_settings
             WHERE scope_key = ?
             LIMIT 1'
        );
        $stmt->execute([$scopeKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function resolveForTenant(?int $empresaId): ?array
    {
        if (($empresaId ?? 0) > 0) {
            $setting = $this->findByScopeKey($this->tenantScopeKey($empresaId));
            if ($setting) {
                return $setting;
            }
        }

        return $this->findByScopeKey('default');
    }

    public function save(string $scopeKey, ?int $empresaId, bool $enabled, ?string $webhookUrl): void
    {
        $existing = $this->findByScopeKey($scopeKey);
        $params = [
            $empresaId,
            $enabled ? 1 : 0,
            $webhookUrl,
        ];

        if ($existing) {
            $stmt = $this->pdo->prepare(
                'UPDATE recruitment_webhook_settings
                 SET empresa_id = ?, enabled = ?, webhook_url = ?
                 WHERE scope_key = ?'
            );
            $params[] = $scopeKey;
            $stmt->execute($params);
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO recruitment_webhook_settings (scope_key, empresa_id, enabled, webhook_url)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$scopeKey, $empresaId, $enabled ? 1 : 0, $webhookUrl]);
    }

    public function adminRows(): array
    {
        $rows = [];
        $default = $this->findByScopeKey('default');
        $rows[] = [
            'scope_key' => 'default',
            'scope_label' => 'Padrão do recrutamento',
            'empresa_id' => (int)($default['empresa_id'] ?? 0),
            'enabled' => (int)($default['enabled'] ?? 0),
            'webhook_url' => (string)($default['webhook_url'] ?? ''),
            'is_default' => 1,
        ];

        $stmt = $this->pdo->query(
            "SELECT e.id, e.nome, e.slug, e.ativo,
                    s.enabled, s.webhook_url
             FROM empresas e
             LEFT JOIN recruitment_webhook_settings s
               ON s.scope_key = CONCAT('empresa:', e.id)
             ORDER BY e.nome ASC"
        );

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'scope_key' => self::tenantScopeKey((int)$row['id']),
                'scope_label' => (string)$row['nome'],
                'empresa_id' => (int)$row['id'],
                'enabled' => (int)($row['enabled'] ?? 0),
                'webhook_url' => (string)($row['webhook_url'] ?? ''),
                'ativo' => (int)($row['ativo'] ?? 0),
                'is_default' => 0,
            ];
        }

        return $rows;
    }

    public function firstActiveEmpresaId(): ?int
    {
        $stmt = $this->pdo->query('SELECT id FROM empresas WHERE ativo = 1 ORDER BY id ASC LIMIT 1');
        $value = $stmt->fetchColumn();

        return $value !== false ? (int)$value : null;
    }

    public static function tenantScopeKey(int $empresaId): string
    {
        return 'empresa:' . $empresaId;
    }
}
