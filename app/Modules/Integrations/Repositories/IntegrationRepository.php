<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Repositories;

use App\Core\Database\Database;

/** Tenant-scoped webhook endpoints + delivery history. */
final class IntegrationRepository
{
    public function __construct(private readonly Database $db) {}

    /** @return list<array<string, mixed>> */
    public function endpoints(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT uuid, url, event_types, is_active, last_success_at, last_failure_at, created_at
             FROM webhook_endpoints WHERE tenant_id = ? ORDER BY created_at DESC',
        );
        $stmt->execute([$tenantId]);

        /** @var list<array<string, mixed>> */
        return array_values($stmt->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function findEndpoint(int $tenantId, string $uuid): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, url, event_types, is_active, secret_encrypted
             FROM webhook_endpoints WHERE tenant_id = ? AND uuid = ?',
        );
        $stmt->execute([$tenantId, $uuid]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @param array{uuid: string, url: string, event_types: string, secret_encrypted: string} $data */
    public function insertEndpoint(int $tenantId, array $data): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO webhook_endpoints (uuid, tenant_id, url, secret_encrypted, event_types)
             VALUES (:uuid, :tid, :url, :secret, :events)',
        )->execute([
            'uuid' => $data['uuid'],
            'tid' => $tenantId,
            'url' => $data['url'],
            'secret' => $data['secret_encrypted'],
            'events' => $data['event_types'],
        ]);
    }

    /** @param array{url?: string, event_types?: string, is_active?: int} $data */
    public function updateEndpoint(int $tenantId, int $id, array $data): void
    {
        $sets = [];
        $params = ['tid' => $tenantId, 'id' => $id];
        foreach ($data as $column => $value) {
            $sets[] = "{$column} = :{$column}";
            $params[$column] = $value;
        }
        if ($sets === []) {
            return;
        }
        $this->db->pdo()->prepare(
            'UPDATE webhook_endpoints SET ' . implode(', ', $sets)
            . ' WHERE tenant_id = :tid AND id = :id',
        )->execute($params);
    }

    public function deleteEndpoint(int $tenantId, int $id): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM webhook_endpoints WHERE tenant_id = ? AND id = ?',
        )->execute([$tenantId, $id]);
    }

    /** Recent deliveries for an endpoint (or all endpoints).
     * @return list<array<string, mixed>>
     */
    public function deliveries(int $tenantId, ?int $endpointId = null): array
    {
        $sql = 'SELECT d.uuid, d.endpoint_id, d.event_type, d.status, d.attempt,
                       d.response_code, d.error, d.created_at, d.delivered_at
                FROM webhook_deliveries d
                WHERE d.tenant_id = :tid';
        $params = ['tid' => $tenantId];
        if ($endpointId !== null) {
            $sql .= ' AND d.endpoint_id = :eid';
            $params['eid'] = $endpointId;
        }
        $sql .= ' ORDER BY d.created_at DESC LIMIT 30';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        /** @var list<array<string, mixed>> */
        return array_values($stmt->fetchAll());
    }
}
