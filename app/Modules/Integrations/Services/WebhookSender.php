<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Services;

use App\Core\Database\Database;
use App\Shared\Http\HttpClient;
use App\Shared\Security\Encrypter;

/**
 * Posts pending webhook_deliveries to their endpoint URLs, signed with
 * the per-endpoint secret (plan.md §12: sign payloads, the secret is shown
 * once at creation and stored encrypted). At-least-once: each delivery is
 * a single attempt row; failed rows are retried by re-queuing through
 * `attempt` and the pending status.
 */
final class WebhookSender
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly Database $db,
        private readonly Encrypter $encrypter,
        private readonly HttpClient $http,
    ) {}

    /** Process up to $limit pending deliveries; returns the number handled. */
    public function dispatchBatch(int $limit = 20): int
    {
        $deliveries = $this->due($limit);
        foreach ($deliveries as $delivery) {
            $this->send($delivery);
        }

        return count($deliveries);
    }

    /** @return list<array<string, mixed>> */
    private function due(int $limit): array
    {
        return $this->db->transaction(function () use ($limit): array {
            $stmt = $this->db->pdo()->prepare(
                "SELECT d.id, d.event_type, d.payload, d.attempt,
                        e.url, e.secret_encrypted, e.id AS endpoint_id
                   FROM webhook_deliveries d
                   JOIN webhook_endpoints e ON e.id = d.endpoint_id
                  WHERE d.status = 'pending' AND e.is_active = 1
                  ORDER BY d.id LIMIT :n
                  FOR UPDATE SKIP LOCKED",
            );
            $stmt->bindValue('n', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = array_values($stmt->fetchAll());
            if ($rows === []) {
                return [];
            }
            $ids = implode(',', array_map(static fn(array $r): int => (int) $r['id'], $rows));
            $this->db->pdo()->exec("UPDATE webhook_deliveries SET status = 'processing' WHERE id IN ({$ids})");

            return $rows;
        });
    }

    /** @param array<string, mixed> $delivery */
    private function send(array $delivery): void
    {
        $id = (int) $delivery['id'];
        $body = (string) $delivery['payload'];
        $secret = $this->encrypter->decrypt((string) $delivery['secret_encrypted']);

        try {
            $response = $this->http->post(
                (string) $delivery['url'],
                [
                    'Content-Type' => 'application/json',
                    'X-Albabook-Event' => (string) $delivery['event_type'],
                    'X-Albabook-Signature' => 'sha256=' . hash_hmac('sha256', $body, $secret),
                ],
                $body,
            );

            if ($response['status'] >= 200 && $response['status'] < 300) {
                $this->db->pdo()->prepare(
                    "UPDATE webhook_deliveries SET status = 'succeeded', response_code = :c,
                            response_body = :b, error = NULL, delivered_at = UTC_TIMESTAMP() WHERE id = :id",
                )->execute([
                    'c' => $response['status'],
                    'b' => mb_substr($response['body'], 0, 2000),
                    'id' => $id,
                ]);
                $this->touchEndpoint((int) $delivery['endpoint_id'], true);

                return;
            }

            throw new \RuntimeException("Endpoint answered HTTP {$response['status']}");
        } catch (\Throwable $e) {
            $attempt = (int) $delivery['attempt'] + 1;
            $status = $attempt > self::MAX_ATTEMPTS ? 'failed' : 'pending';
            $this->db->pdo()->prepare(
                'UPDATE webhook_deliveries SET status = :s, attempt = :a, error = :e WHERE id = :id',
            )->execute([
                's' => $status,
                'a' => $attempt,
                'e' => mb_substr($e->getMessage(), 0, 500),
                'id' => $id,
            ]);
            $this->touchEndpoint((int) $delivery['endpoint_id'], false);
        }
    }

    private function touchEndpoint(int $endpointId, bool $success): void
    {
        $column = $success ? 'last_success_at' : 'last_failure_at';
        $this->db->pdo()->prepare(
            "UPDATE webhook_endpoints SET {$column} = UTC_TIMESTAMP() WHERE id = :id",
        )->execute(['id' => $endpointId]);
    }
}
