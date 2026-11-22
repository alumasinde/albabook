<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Core\Database\Database;
use App\Core\Http\Request;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditLogger;
use App\Shared\Support\UuidFactory;

/**
 * The only way a tenant's status changes (multi-tenancy.md §3): the move
 * must be an edge of the lifecycle graph, and the status update, the audit
 * row and the `tenant.status_changed` outbox event commit together.
 */
final class TenantLifecycleService
{
    /** @var array<string, list<string>> multi-tenancy.md "Allowed transitions" */
    private const TRANSITIONS = [
        'pending' => ['trial', 'active', 'archived'],
        'trial' => ['active', 'suspended', 'cancelled'],
        'active' => ['suspended', 'cancelled'],
        'suspended' => ['active', 'cancelled'],
        'cancelled' => ['active', 'archived'],
        'archived' => [],
    ];

    public function __construct(
        private readonly Database $db,
        private readonly AuditLogger $audit,
        private readonly UuidFactory $uuids,
    ) {}

    /** @return list<string> statuses reachable from $from */
    public static function allowedFrom(string $from): array
    {
        return self::TRANSITIONS[$from] ?? [];
    }

    /**
     * Move a tenant to $to. Returns false (and changes nothing) when the move
     * is not an allowed edge from the tenant's current, locked status.
     */
    public function transition(int $tenantId, string $to, ?int $platformUserId, Request $request): bool
    {
        return $this->db->transaction(function () use ($tenantId, $to, $platformUserId, $request): bool {
            $pdo = $this->db->pdo();
            $stmt = $pdo->prepare('SELECT uuid, status FROM tenants WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $tenantId]);
            $row = $stmt->fetch();
            if ($row === false) {
                return false;
            }

            $from = (string) $row['status'];
            if (!in_array($to, self::allowedFrom($from), true)) {
                return false;
            }

            $pdo->prepare('UPDATE tenants SET status = :s WHERE id = :id AND status = :from')
                ->execute(['s' => $to, 'id' => $tenantId, 'from' => $from]);

            $this->audit->record(new AuditEntry(
                action: AuditAction::TENANT_STATUS_CHANGED,
                tenantId: $tenantId,
                actorType: 'platform_user',
                actorId: $platformUserId,
                targetType: 'tenant',
                targetId: (string) $row['uuid'],
                before: ['status' => $from],
                after: ['status' => $to],
                request: $request,
            ));

            $pdo->prepare(
                "INSERT INTO outbox_events (uuid, tenant_id, event_type, aggregate_type, aggregate_id, payload)
                 VALUES (:u, :t, 'tenant.status_changed', 'tenant', :a, :p)",
            )->execute([
                'u' => $this->uuids->generate(),
                't' => $tenantId,
                'a' => $tenantId,
                'p' => json_encode([
                    'tenant_uuid' => (string) $row['uuid'],
                    'from' => $from,
                    'to' => $to,
                ], JSON_THROW_ON_ERROR),
            ]);

            return true;
        });
    }
}
