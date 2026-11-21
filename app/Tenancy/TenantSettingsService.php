<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Core\Database\Database;

/**
 * Typed tenant_settings access. Defaults mirror the migration so a missing row
 * never changes access rules.
 */
final class TenantSettingsService
{
    public function __construct(private readonly Database $db) {}

    /** @return array<string, mixed> */
    public function find(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT tenant_id, date_format, default_dashboard_view, support_write_enabled, onboarding_progress, preferences, schema_version
             FROM tenant_settings WHERE tenant_id = :tenant_id LIMIT 1',
        );
        $stmt->execute(['tenant_id' => $tenantId]);
        $row = $stmt->fetch();

        return $row ?: [
            'tenant_id' => $tenantId,
            'date_format' => 'd/m/Y',
            'default_dashboard_view' => 'overview',
            'support_write_enabled' => 1,
            'onboarding_progress' => null,
            'preferences' => null,
            'schema_version' => 1,
        ];
    }

    public function supportWriteEnabled(int $tenantId): bool
    {
        return (bool) $this->find($tenantId)['support_write_enabled'];
    }

    /** Tenant-level MFA requirement for roles holding privileged permissions. */
    public function mfaRequiredForPrivilegedRoles(int $tenantId): bool
    {
        $preferences = $this->preferences($tenantId);

        return (bool) ($preferences['mfa_required'] ?? false);
    }

    /** @return array<string, mixed> */
    public function preferences(int $tenantId): array
    {
        $raw = $this->find($tenantId)['preferences'];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($raw) ? $raw : [];
    }

    /** @param array<string, mixed> $values */
    public function mergePreferences(int $tenantId, array $values): void
    {
        $preferences = array_replace($this->preferences($tenantId), $values);
        $this->db->pdo()->prepare(
            'UPDATE tenant_settings SET preferences = :preferences WHERE tenant_id = :tenant_id',
        )->execute([
            'preferences' => json_encode($preferences, JSON_THROW_ON_ERROR),
            'tenant_id' => $tenantId,
        ]);
    }
}
