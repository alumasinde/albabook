<?php

declare(strict_types=1);

namespace App\Modules\Settings\Repositories;

use App\Core\Database\Database;

/**
 * Writer for the tenant-wide booking_policies row (branch_id NULL,
 * branch_scope 0). Branch-specific rows are honoured by the engine but not
 * yet edited from the dashboard.
 */
final class BookingPolicyRepository
{
    public function __construct(private readonly Database $db) {}

    /** @return array<string, mixed>|null */
    public function tenantRow(int $tenantId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM booking_policies WHERE tenant_id = :t AND branch_scope = 0 LIMIT 1',
        );
        $stmt->execute(['t' => $tenantId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Upsert the tenant-wide rules. policy_version is bumped on every change
     * so new bookings snapshot the rules that applied when they were made.
     *
     * @param array<string, mixed> $rules validated by BookingRules::normalise()
     */
    public function saveTenantRules(int $tenantId, array $rules): void
    {
        $columns = array_keys($rules);
        $insertCols = implode(', ', $columns);
        $insertVals = implode(', ', array_map(static fn(string $c): string => ':' . $c, $columns));
        $updates = implode(', ', array_map(static fn(string $c): string => "{$c} = VALUES({$c})", $columns));

        $this->db->pdo()->prepare(
            "INSERT INTO booking_policies (tenant_id, branch_id, {$insertCols})
             VALUES (:t, NULL, {$insertVals})
             ON DUPLICATE KEY UPDATE {$updates}, policy_version = policy_version + 1",
        )->execute(['t' => $tenantId] + $rules);
    }
}
