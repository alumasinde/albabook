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
        // Pending businesses finish setup (services, staff, branches) while
        // awaiting approval; operational booking stays off until trial/active.
        // Keep in step with TenantStatusPolicySeeder (the database wins).
        'pending' => ['dashboard.view', 'onboarding.manage', 'business.manage', 'billing.view'],
        'trial' => ['dashboard.view', 'booking.create', 'business.manage', 'billing.view', 'onboarding.manage'],
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

    /**
     * Operations a status allows; ['*'] means unrestricted. Used to stamp
     * the membership so per-permission checks honour tenant status.
     * @return list<string>
     */
    public function operations(string $status): array
    {
        if (($this->allow[$status]['*'] ?? false) === true) {
            return ['*'];
        }

        return array_keys(array_filter(
            $this->allow[$status] ?? [],
            static fn ($allowed): bool => $allowed === true,
        ));
    }

    /**
     * Maps a membership permission to the account-level operation the
     * policy matrix gates. Reads degrade to dashboard.view so every status
     * keeps its data readable unless the matrix says otherwise.
     */
    public static function operationForPermission(string $permission): string
    {
        return match (true) {
            str_starts_with($permission, 'onboarding.') => 'onboarding.manage',
            str_starts_with($permission, 'billing.') => 'billing.view',
            $permission === 'reports.export',
                str_starts_with($permission, 'exports.'),
                str_starts_with($permission, 'data.') => 'data.export',
            preg_match('/\.view(_own|_notes|_financial)?$/', $permission) === 1 => 'dashboard.view',
            str_starts_with($permission, 'appointments.'),
                str_starts_with($permission, 'payments.') => 'booking.create',
            default => 'business.manage',
        };
    }
}
