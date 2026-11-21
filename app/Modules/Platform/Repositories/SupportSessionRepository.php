<?php

declare(strict_types=1);

namespace App\Modules\Platform\Repositories;

use App\Core\Database\Database;

final class SupportSessionRepository
{
    public function __construct(private readonly Database $db) {}

    public function create(
        string $uuid,
        int $platformUserId,
        int $tenantId,
        string $reason,
        string $scope,
        string $expiresAt,
    ): int {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO support_access_sessions (uuid, platform_user_id, tenant_id, reason, scope, expires_at)
             VALUES (:uuid, :platform_user_id, :tenant_id, :reason, :scope, :expires)',
        )->execute([
            'uuid' => $uuid,
            'platform_user_id' => $platformUserId,
            'tenant_id' => $tenantId,
            'reason' => mb_substr($reason, 0, 500),
            'scope' => $scope,
            'expires' => $expiresAt,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findActiveById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.id, s.uuid, s.platform_user_id, s.tenant_id, s.reason, s.scope,
                    s.started_at, s.expires_at, s.ended_at,
                    t.name AS tenant_name, t.slug AS tenant_slug, t.status AS tenant_status
             FROM support_access_sessions s
             INNER JOIN tenants t ON t.id = s.tenant_id
             WHERE s.id = :id AND s.ended_at IS NULL AND s.expires_at > UTC_TIMESTAMP()
             LIMIT 1',
        );
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    /** @return array<string, mixed>|null */
    public function findActiveByUuid(string $uuid): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.id, s.uuid, s.platform_user_id, s.tenant_id, s.reason, s.scope,
                    s.started_at, s.expires_at, s.ended_at,
                    t.name AS tenant_name, t.slug AS tenant_slug, t.status AS tenant_status
             FROM support_access_sessions s
             INNER JOIN tenants t ON t.id = s.tenant_id
             WHERE s.uuid = :uuid AND s.ended_at IS NULL AND s.expires_at > UTC_TIMESTAMP()
             LIMIT 1',
        );
        $stmt->execute(['uuid' => $uuid]);

        return $stmt->fetch() ?: null;
    }

    /** @return array<string, mixed>|null includes ended and expired rows */
    public function findForAdmin(int $id, int $platformUserId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, uuid, platform_user_id, tenant_id, reason, scope, started_at, expires_at, ended_at
             FROM support_access_sessions WHERE id = :id AND platform_user_id = :platform_user_id LIMIT 1',
        );
        $stmt->execute(['id' => $id, 'platform_user_id' => $platformUserId]);

        return $stmt->fetch() ?: null;
    }

    public function end(int $id, string $at): void
    {
        $this->db->pdo()->prepare(
            'UPDATE support_access_sessions SET ended_at = COALESCE(ended_at, :at) WHERE id = :id',
        )->execute(['at' => $at, 'id' => $id]);
    }

    /** @return list<array<string, mixed>> */
    public function recentForAdmin(int $platformUserId, int $limit = 20): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.id, s.uuid, s.tenant_id, s.reason, s.scope, s.started_at, s.expires_at, s.ended_at,
                    t.name AS tenant_name, t.slug AS tenant_slug
             FROM support_access_sessions s
             INNER JOIN tenants t ON t.id = s.tenant_id
             WHERE s.platform_user_id = :platform_user_id
             ORDER BY s.started_at DESC LIMIT :limit',
        );
        $stmt->bindValue('platform_user_id', $platformUserId);
        $stmt->bindValue('limit', max(1, $limit), \PDO::PARAM_INT);
        $stmt->execute();

        return array_values($stmt->fetchAll());
    }
}
