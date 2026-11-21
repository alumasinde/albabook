<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Services;

use App\Core\Database\Database;
use App\Shared\Support\UuidFactory;

/**
 * Fans pending outbox_events out to webhook_deliveries, one per matching
 * active endpoint. At-least-once delivery: the webhook worker retries its
 * own rows, and consumers are expected to be idempotent on the event UUID
 * carried in the payload (plan.md §11.8).
 */
final class OutboxDispatcher
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly Database $db,
        private readonly UuidFactory $uuids,
    ) {}

    /** Process up to $limit pending events; returns the number claimed. */
    public function dispatchBatch(int $limit = 50): int
    {
        $events = $this->claim($limit);
        foreach ($events as $event) {
            $this->fanOut($event);
        }

        return count($events);
    }

    /** @return list<array<string, mixed>> */
    private function claim(int $limit): array
    {
        return $this->db->transaction(function () use ($limit): array {
            $stmt = $this->db->pdo()->prepare(
                "SELECT id, uuid, tenant_id, event_type, payload
                   FROM outbox_events
                  WHERE status = 'pending' AND available_at <= UTC_TIMESTAMP()
                  ORDER BY id LIMIT :n
                  FOR UPDATE SKIP LOCKED",
            );
            $stmt->bindValue('n', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            $events = array_values($stmt->fetchAll());
            if ($events === []) {
                return [];
            }
            $ids = implode(',', array_map(static fn(array $e): int => (int) $e['id'], $events));
            $this->db->pdo()->exec("UPDATE outbox_events SET status = 'processing' WHERE id IN ({$ids})");

            return $events;
        });
    }

    /** @param array<string, mixed> $event */
    private function fanOut(array $event): void
    {
        $id = (int) $event['id'];
        $tenantId = $event['tenant_id'] === null ? null : (int) $event['tenant_id'];

        try {
            if ($tenantId !== null) {
                foreach ($this->endpoints($tenantId, (string) $event['event_type']) as $endpoint) {
                    $this->db->pdo()->prepare(
                        "INSERT INTO webhook_deliveries (uuid, tenant_id, endpoint_id, event_type, payload, status)
                         VALUES (:u, :t, :e, :et, :p, 'pending')",
                    )->execute([
                        'u' => $this->uuids->generate(),
                        't' => $tenantId,
                        'e' => (int) $endpoint['id'],
                        'et' => (string) $event['event_type'],
                        'p' => (string) $event['payload'],
                    ]);
                }
            }
            $this->db->pdo()->prepare(
                "UPDATE outbox_events SET status = 'delivered', processed_at = UTC_TIMESTAMP() WHERE id = :id",
            )->execute(['id' => $id]);
        } catch (\Throwable $e) {
            $stmt = $this->db->pdo()->prepare('SELECT attempts FROM outbox_events WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $attempts = (int) $stmt->fetchColumn() + 1;
            $status = $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending';
            $this->db->pdo()->prepare(
                "UPDATE outbox_events SET status = :s, attempts = :a, last_error = :e,
                        available_at = :retry WHERE id = :id",
            )->execute([
                's' => $status,
                'a' => $attempts,
                'e' => mb_substr($e->getMessage(), 0, 500),
                'retry' => gmdate('Y-m-d H:i:s', time() + 300 * $attempts),
                'id' => $id,
            ]);
        }
    }

    /** @return list<array<string, mixed>> */
    private function endpoints(int $tenantId, string $eventType): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM webhook_endpoints
              WHERE tenant_id = :t AND is_active = 1
                AND (JSON_CONTAINS(event_types, :e) OR JSON_CONTAINS(event_types, "\"*\""))',
        );
        $stmt->execute(['t' => $tenantId, 'e' => json_encode($eventType, JSON_THROW_ON_ERROR)]);

        return array_values($stmt->fetchAll());
    }
}
