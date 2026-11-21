<?php

declare(strict_types=1);

namespace App\Core\Database\Seeders;

use App\Core\Database\Database;

/**
 * Account-level capability matrix (multi-tenancy.md §3). Rows are seeded from
 * the code defaults so the table is the source of truth at runtime.
 */
final class TenantStatusPolicySeeder
{
    private const OPERATIONS = [
        'dashboard.view',
        'booking.create',
        'business.manage',
        'billing.view',
        'data.export',
        'onboarding.manage',
    ];

    /** @var array<string, list<string>> */
    private const ALLOWED = [
        'pending' => ['dashboard.view', 'onboarding.manage'],
        'trial' => ['dashboard.view', 'booking.create', 'business.manage'],
        'active' => ['dashboard.view', 'booking.create', 'business.manage', 'billing.view', 'data.export', 'onboarding.manage'],
        'suspended' => [],
        'cancelled' => ['dashboard.view', 'billing.view', 'data.export'],
        'archived' => [],
    ];

    public function __construct(private readonly Database $db) {}

    public function run(): int
    {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO tenant_status_policies (status, operation, allowed)
             VALUES (:status, :operation, :allowed)
             ON DUPLICATE KEY UPDATE allowed = VALUES(allowed)',
        );

        $count = 0;
        foreach (self::ALLOWED as $status => $allowedOperations) {
            foreach (self::OPERATIONS as $operation) {
                $statement->execute([
                    'status' => $status,
                    'operation' => $operation,
                    'allowed' => in_array($operation, $allowedOperations, true) ? 1 : 0,
                ]);
                $count++;
            }
        }

        return $count;
    }
}
