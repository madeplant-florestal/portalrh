<?php
class WebhookEventRepository
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

    public function create(array $data, ?PDO $pdo = null): int
    {
        $conn = $pdo ?? $this->pdo;
        $stmt = $conn->prepare(
            'INSERT INTO webhook_events (tenant_id, event_type, payload_json, webhook_url, status, response_code, response_body, last_error, processed_at, retry_count)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['tenant_id'] ?? null,
            $data['event_type'],
            $data['payload_json'],
            $data['webhook_url'] ?? null,
            $data['status'],
            $data['response_code'] ?? null,
            $data['response_body'] ?? null,
            $data['last_error'] ?? null,
            $data['processed_at'] ?? null,
            (int)($data['retry_count'] ?? 0),
        ]);

        return (int)$conn->lastInsertId();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM webhook_events WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function paginateAdmin(int $page = 1, int $perPage = 50): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $total = (int)$this->pdo->query('SELECT COUNT(*) FROM webhook_events')->fetchColumn();
        $pages = max(1, (int)ceil($total / $perPage));
        if ($page > $pages) {
            $page = $pages;
            $offset = ($page - 1) * $perPage;
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM webhook_events ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?'
        );
        $stmt->execute([$perPage, $offset]);

        return [
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
        ];
    }

    public function markProcessing(int $id): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE webhook_events
             SET status = 'processing', last_error = NULL
             WHERE id = ?"
        );
        $stmt->execute([$id]);
    }

    public function markProcessed(int $id, int $responseCode, ?string $responseBody): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE webhook_events
             SET status = 'processed',
                 response_code = ?,
                 response_body = ?,
                 processed_at = NOW(),
                 last_error = NULL
             WHERE id = ?"
        );
        $stmt->execute([$responseCode, $responseBody, $id]);
    }

    public function markFailed(int $id, int $retryCount, ?int $responseCode, ?string $responseBody, string $lastError): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE webhook_events
             SET status = 'failed',
                 retry_count = ?,
                 response_code = ?,
                 response_body = ?,
                 last_error = ?,
                 processed_at = NOW()
             WHERE id = ?"
        );
        $stmt->execute([$retryCount, $responseCode, $responseBody, $lastError, $id]);
    }

    public function markSkipped(int $id, string $status, string $message): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE webhook_events
             SET status = ?, last_error = ?, processed_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([$status, $message, $id]);
    }

    public function requeue(int $id): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE webhook_events
             SET status = 'pending',
                 processed_at = NULL,
                 last_error = NULL,
                 response_code = NULL,
                 response_body = NULL
             WHERE id = ?"
        );
        $stmt->execute([$id]);
    }

    public function pending(int $limit = 25): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM webhook_events
             WHERE status = 'pending'
             ORDER BY created_at ASC, id ASC
             LIMIT ?"
        );
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
