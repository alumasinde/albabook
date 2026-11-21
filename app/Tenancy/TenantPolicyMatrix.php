<?php

declare(strict_types=1);

namespace App\Tenancy;

/**
 * Account-level access rules (multi-tenancy.md §3). The database is the source
 * of truth; these code defaults are used only when the policy table is empty.
 */
final class TenantPolicyMatrix
{
    public const DEFAULTS = [
        'pending' => ['dashboard.view', 'onboarding.manage'],
        'trial' => ['dashboard.view', 'booking.create', 'business.manage'],
        'active' => ['*'],
        'suspended' => [],
        'cancelled' => ['dashboard.view', 'billing.view', 'data.export'],
        'archived' => [],
    ];

    /** @var array<string, array<string, bool>> */
    private array $allow = [];

    /** @param list<array{status: string, operation: string, allowed: bool}> $rows */
    public function __construct(array $rows)
    {
        if ($rows === []) {
            foreach (self::DEFAULTS as $status => $operations) {
                foreach ($operations as $operation) {
                    $this->allow[$status][$operation] = true;
                }
            }

            return;
        }

        foreach ($rows as $row) {
            $this->allow[$row['status']][$row['operation']] = $row['allowed'];
        }
    }

    public function allows(string $status, string $operation): bool
    {
        return ($this->allow[$status]['*'] ?? false) === true
            || ($this->allow[$status][$operation] ?? false) === true;
    }
}
