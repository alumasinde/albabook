<?php

declare(strict_types=1);

namespace App\Modules\Settings\Repositories;

use App\Core\Database\Database;

/**
 * Tenant-scoped settings access: workspace profile (tenants row), display
 * preferences (tenant_settings row) and service categories. Every query pins
 * tenant_id — settings can never cross tenants.
 */
final class SettingsRepository
{
    public function __construct(private readonly Database $db) {}

    /** Workspace profile columns from tenants.
     * @return array<string, mixed>
     */
    public function profile(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT name, slug, timezone, currency, default_locale, vat_registered, tax_rate_bp, kra_pin
               FROM tenants WHERE id = :t LIMIT 1',
        );
        $stmt->execute(['t' => $tenantId]);
        $row = $stmt->fetch();

        return $row === false ? [] : $row;
    }

    /** @param array<string, mixed> $data */
    public function updateProfile(int $tenantId, array $data): void
    {
        $sets = implode(', ', array_map(static fn(string $c): string => $c . ' = :' . $c, array_keys($data)));
        $this->db->pdo()->prepare(
            "UPDATE tenants SET {$sets}, settings_version = settings_version + 1 WHERE id = :__t",
        )->execute($data + ['__t' => $tenantId]);
    }

    /** Display preferences from tenant_settings.
     * @return array{date_format: string, default_dashboard_view: string, checkout_full_payment_required: bool}
     */
    public function preferences(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT date_format, default_dashboard_view, checkout_full_payment_required
             FROM tenant_settings WHERE tenant_id = :t LIMIT 1',
        );
        $stmt->execute(['t' => $tenantId]);
        $row = $stmt->fetch();

        return [
            'date_format' => $row === false ? 'd/m/Y' : (string) $row['date_format'],
            'default_dashboard_view' => $row === false ? 'overview' : (string) $row['default_dashboard_view'],
            'checkout_full_payment_required' => $row === false ? true : (bool) (int) $row['checkout_full_payment_required'],
        ];
    }

    /** @param array{date_format?: string, default_dashboard_view?: string, checkout_full_payment_required?: int} $data */
    public function updatePreferences(int $tenantId, array $data): void
    {
        $sets = implode(', ', array_map(static fn(string $c): string => $c . ' = :' . $c, array_keys($data)));
        $this->db->pdo()->prepare(
            "UPDATE tenant_settings SET {$sets} WHERE tenant_id = :t",
        )->execute($data + ['t' => $tenantId]);
    }

    /** Free-form keys in tenant_settings.preferences (JSON).
     * @return array<string, mixed>
     */
    public function jsonPreferences(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT preferences FROM tenant_settings WHERE tenant_id = :t LIMIT 1',
        );
        $stmt->execute(['t' => $tenantId]);
        $raw = $stmt->fetchColumn();
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** Merge keys into the preferences JSON blob. */
    public function updateJsonPreferences(int $tenantId, string $key, mixed $value): void
    {
        if (is_array($value)) {
            // Store as a JSON object, not a string. JSON_EXTRACT(:v, '$')
            // parses the text on both MySQL 8 and MariaDB (CAST AS JSON is
            // MySQL-only).
            $this->db->pdo()->prepare(
                "UPDATE tenant_settings
                 SET preferences = JSON_SET(COALESCE(preferences, JSON_OBJECT()), :path, JSON_EXTRACT(:value, '$'))
                 WHERE tenant_id = :t",
            )->execute(['path' => '$.' . $key, 'value' => json_encode($value, JSON_THROW_ON_ERROR), 't' => $tenantId]);

            return;
        }

        $this->db->pdo()->prepare(
            "UPDATE tenant_settings
             SET preferences = JSON_SET(COALESCE(preferences, JSON_OBJECT()), :path, :value)
             WHERE tenant_id = :t",
        )->execute(['path' => '$.' . $key, 'value' => $value, 't' => $tenantId]);
    }

    /** Active dashboard locales for the default-locale dropdown.
     * @return list<array{code: string, name: string}>
     */
    public function locales(): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT code, name FROM locales WHERE is_active = 1 AND is_dashboard = 1 ORDER BY sort_position, code',
        );
        $stmt->execute();

        /** @var list<array{code: string, name: string}> */
        return array_values($stmt->fetchAll());
    }

    /* -------------------------------------------------- service categories */

    /** @return list<array<string, mixed>> */
    public function categories(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.*,
                    (SELECT COUNT(*) FROM services s WHERE s.tenant_id = c.tenant_id AND s.service_category_id = c.id) AS service_count
               FROM service_categories c
              WHERE c.tenant_id = :t
              ORDER BY c.sort_position, c.name',
        );
        $stmt->execute(['t' => $tenantId]);

        /** @var list<array<string, mixed>> */
        return array_values($stmt->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function findCategoryByUuid(int $tenantId, string $uuid): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM service_categories WHERE tenant_id = :t AND uuid = :u LIMIT 1',
        );
        $stmt->execute(['t' => $tenantId, 'u' => $uuid]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $data */
    public function insertCategory(array $data): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO service_categories (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', array_map(static fn(string $c): string => ':' . $c, $columns)),
        );
        $this->db->pdo()->prepare($sql)->execute($data);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function updateCategory(int $tenantId, int $categoryId, array $data): void
    {
        $sets = implode(', ', array_map(static fn(string $c): string => $c . ' = :' . $c, array_keys($data)));
        $this->db->pdo()->prepare(
            "UPDATE service_categories SET {$sets} WHERE tenant_id = :__t AND id = :__id",
        )->execute($data + ['__t' => $tenantId, '__id' => $categoryId]);
    }

    public function deleteCategory(int $tenantId, int $categoryId): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM service_categories WHERE tenant_id = :t AND id = :i',
        )->execute(['t' => $tenantId, 'i' => $categoryId]);
    }

    /** Services referencing a category via RESTRICT FK. >0 blocks deletion. */
    public function categoryReferenceCount(int $tenantId, int $categoryId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM services WHERE tenant_id = :t AND service_category_id = :c',
        );
        $stmt->execute(['t' => $tenantId, 'c' => $categoryId]);

        return (int) $stmt->fetchColumn();
    }

    /** Next sort position for a new category. */
    public function nextCategoryPosition(int $tenantId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(MAX(sort_position), 0) + 1 FROM service_categories WHERE tenant_id = :t',
        );
        $stmt->execute(['t' => $tenantId]);

        return (int) $stmt->fetchColumn();
    }

    /* ------------------------------------------------------ tenant branding */

    /** Booking-page branding; defaults mirror the migration so a missing row
     * renders the stock theme.
     * @return array<string, mixed>
     */
    public function branding(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT primary_color, secondary_color, accent_color, background_color, text_color, appearance, font_family
               FROM tenant_branding WHERE tenant_id = :t LIMIT 1',
        );
        $stmt->execute(['t' => $tenantId]);
        $row = $stmt->fetch();

        return $row === false ? [
            'primary_color' => '#6D5CE8',
            'secondary_color' => '#171A2C',
            'accent_color' => '#16A085',
            'background_color' => '#FFFFFF',
            'text_color' => '#202124',
            'appearance' => 'light',
            'font_family' => 'inter',
        ] : $row;
    }

    /** @param array<string, mixed> $data */
    public function upsertBranding(int $tenantId, array $data): void
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(static fn(string $c): string => ':' . $c, array_keys($data)));
        $updates = implode(', ', array_map(static fn(string $c): string => $c . ' = VALUES(' . $c . ')', array_keys($data)));
        $this->db->pdo()->prepare(
            "INSERT INTO tenant_branding (tenant_id, {$columns}) VALUES (:tenant_id, {$placeholders})
             ON DUPLICATE KEY UPDATE {$updates}",
        )->execute($data + ['tenant_id' => $tenantId]);
    }

    /* -------------------------------------------------------- notifications */

    /** @return list<array{code: string, name: string}> */
    public function notificationChannels(): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT code, name FROM communication_channels ORDER BY sort_position',
        );
        $stmt->execute();

        /** @var list<array{code: string, name: string}> */
        return array_values($stmt->fetchAll());
    }

    /** @return list<array{code: string, name: string, audience: string}> */
    public function notificationEvents(): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT code, name, audience FROM notification_events WHERE is_active = 1 ORDER BY sort_position',
        );
        $stmt->execute();

        /** @var list<array{code: string, name: string, audience: string}> */
        return array_values($stmt->fetchAll());
    }

    /** Channel×event preference map: "channel|event" => enabled.
     * @return array<string, bool>
     */
    public function notificationPrefs(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT channel, event_key, is_enabled FROM notification_preferences WHERE tenant_id = :t',
        );
        $stmt->execute(['t' => $tenantId]);

        $prefs = [];
        foreach ($stmt->fetchAll() as $row) {
            $prefs[$row['channel'] . '|' . $row['event_key']] = (bool) $row['is_enabled'];
        }

        return $prefs;
    }

    public function upsertNotificationPref(int $tenantId, string $channel, string $eventKey, bool $enabled): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO notification_preferences (tenant_id, channel, event_key, is_enabled)
             VALUES (:t, :c, :e, :en)
             ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled)',
        )->execute(['t' => $tenantId, 'c' => $channel, 'e' => $eventKey, 'en' => $enabled ? 1 : 0]);
    }

    /** @return list<array{id: int, code: string, name: string, channel: string}> */
    public function notificationProviders(): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, code, name, channel FROM notification_providers WHERE is_active = 1 ORDER BY channel, name',
        );
        $stmt->execute();

        /** @var list<array{id: int, code: string, name: string, channel: string}> */
        return array_values($stmt->fetchAll());
    }

    /** @return list<array<string, mixed>> */
    public function notificationAccounts(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.id, a.provider_id, a.sender_id, a.status, a.credentials_encrypted IS NOT NULL AS has_credentials,
                    p.code AS provider_code, p.name AS provider_name, p.channel
               FROM tenant_notification_accounts a
               JOIN notification_providers p ON p.id = a.provider_id
              WHERE a.tenant_id = :t ORDER BY p.channel, p.name',
        );
        $stmt->execute(['t' => $tenantId]);

        /** @var list<array<string, mixed>> */
        return array_values($stmt->fetchAll());
    }

    public function notificationProviderValid(int $providerId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM notification_providers WHERE id = :i AND is_active = 1 LIMIT 1',
        );
        $stmt->execute(['i' => $providerId]);

        return $stmt->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $data */
    public function insertNotificationAccount(int $tenantId, array $data): int
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(static fn(string $c): string => ':' . $c, array_keys($data)));
        $this->db->pdo()->prepare(
            "INSERT INTO tenant_notification_accounts (tenant_id, {$columns}, created_at, updated_at)
             VALUES (:t, {$placeholders}, NOW(), NOW())",
        )->execute($data + ['t' => $tenantId]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function updateNotificationAccount(int $tenantId, int $accountId, string $status, ?string $senderId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE tenant_notification_accounts SET status = :st, sender_id = :sd
              WHERE tenant_id = :t AND id = :i',
        )->execute(['st' => $status, 'sd' => $senderId, 't' => $tenantId, 'i' => $accountId]);
    }

    /** @return array<string, mixed>|null */
    public function findNotificationAccount(int $tenantId, int $accountId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM tenant_notification_accounts WHERE tenant_id = :t AND id = :i LIMIT 1',
        );
        $stmt->execute(['t' => $tenantId, 'i' => $accountId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /* ------------------------------------------------------------- payments */

    /** @return list<array{code: string, name: string, payment_method: string}> */
    public function paymentProviders(): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT code, name, payment_method FROM payment_providers WHERE is_active = 1 ORDER BY name',
        );
        $stmt->execute();

        /** @var list<array{code: string, name: string, payment_method: string}> */
        return array_values($stmt->fetchAll());
    }

    /** @return list<array<string, mixed>> */
    public function paymentAccounts(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.id, a.uuid, a.branch_id, a.provider, a.account_type, a.shortcode, a.display_name, a.status,
                    a.credentials_encrypted IS NOT NULL AS has_credentials, b.name AS branch_name
               FROM tenant_payment_accounts a
               LEFT JOIN branches b ON b.tenant_id = a.tenant_id AND b.id = a.branch_id
              WHERE a.tenant_id = :t ORDER BY a.display_name',
        );
        $stmt->execute(['t' => $tenantId]);

        /** @var list<array<string, mixed>> */
        return array_values($stmt->fetchAll());
    }

    public function paymentProviderValid(string $code): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM payment_providers WHERE code = :c AND is_active = 1 LIMIT 1',
        );
        $stmt->execute(['c' => $code]);

        return $stmt->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $data */
    public function insertPaymentAccount(int $tenantId, array $data): int
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(static fn(string $c): string => ':' . $c, array_keys($data)));
        $this->db->pdo()->prepare(
            "INSERT INTO tenant_payment_accounts (tenant_id, {$columns}, created_at, updated_at)
             VALUES (:t, {$placeholders}, NOW(), NOW())",
        )->execute($data + ['t' => $tenantId]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findPaymentAccount(int $tenantId, string $uuid): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM tenant_payment_accounts WHERE tenant_id = :t AND uuid = :u LIMIT 1',
        );
        $stmt->execute(['t' => $tenantId, 'u' => $uuid]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function updatePaymentAccountStatus(int $tenantId, int $accountId, string $status): void
    {
        $this->db->pdo()->prepare(
            'UPDATE tenant_payment_accounts SET status = :st WHERE tenant_id = :t AND id = :i',
        )->execute(['st' => $status, 't' => $tenantId, 'i' => $accountId]);
    }

    /** Active branches for the payment-account assignment dropdown.
     * @return list<array{id: int, name: string}>
     */
    public function branches(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, name FROM branches WHERE tenant_id = :t AND is_active = 1 ORDER BY name',
        );
        $stmt->execute(['t' => $tenantId]);

        /** @var list<array{id: int, name: string}> */
        return array_values($stmt->fetchAll());
    }

    public function branchValid(int $tenantId, int $branchId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM branches WHERE tenant_id = :t AND id = :i LIMIT 1',
        );
        $stmt->execute(['t' => $tenantId, 'i' => $branchId]);

        return $stmt->fetchColumn() !== false;
    }

    /** Recent customer payments for the ledger preview.
     * @return list<array<string, mixed>>
     */
    public function recentPayments(int $tenantId, int $limit = 10): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT p.uuid, p.kind, p.method, p.amount_minor, p.currency, p.status, p.received_at,
                    CONCAT(c.first_name, ' ', c.last_name) AS customer_name
               FROM payments p
               JOIN customers c ON c.tenant_id = p.tenant_id AND c.id = p.customer_id
              WHERE p.tenant_id = :t
              ORDER BY p.received_at DESC
              LIMIT :lim",
        );
        $stmt->bindValue(':t', $tenantId);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array<string, mixed>> */
        return array_values($stmt->fetchAll());
    }
}
