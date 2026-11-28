<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Core\Database\Database;

/**
 * Plan feature enforcement. Resolution order per feature_key:
 *   1. tenant_feature_overrides (unexpired) — support-set exceptions
 *   2. plan_features of the tenant's trialing/active subscription
 *   3. default: enabled with no limit (tenants without a subscription keep
 *      legacy unrestricted behaviour)
 *
 * enabled() answers "may this tenant use feature X at all"; limit()
 * answers "how many" for limit-type features (NULL = unlimited). A limit
 * is meaningless when the feature is disabled.
 */
final class FeatureGate
{
    public function __construct(private readonly Database $db) {}

    public function enabled(int $tenantId, string $feature): bool
    {
        $row = $this->resolve($tenantId, $feature);

        return $row === null || (bool) $row['is_enabled'];
    }

    /** NULL = unlimited. Zero means "not at all" when enabled is false. */
    public function limit(int $tenantId, string $feature): ?int
    {
        $row = $this->resolve($tenantId, $feature);
        if ($row === null || !(bool) $row['is_enabled']) {
            return null;
        }

        return $row['limit_value'] === null ? null : (int) $row['limit_value'];
    }

    /**
     * True when the tenant may create one more of a metered feature.
     * $currentCount is supplied by the caller (the repo owns the count).
     */
    public function underLimit(int $tenantId, string $feature, int $currentCount): bool
    {
        $row = $this->resolve($tenantId, $feature);
        if ($row === null) {
            return true; // grandfathered — no rule applies
        }
        if (!(bool) $row['is_enabled']) {
            return false; // a disabled feature admits no quota at all
        }

        return $row['limit_value'] === null || $currentCount < (int) $row['limit_value'];
    }

    /**
     * Features that cost the platform money per use (messages sent through
     * the platform-owned provider account). They are never granted by
     * default: a plan or override must include them explicitly.
     */
    private const COST_BEARING = ['sms_messages', 'whatsapp_messages'];

    /**
     * The effective override or plan_feature row.
     *
     * Secure defaults (no override, nothing on the plan):
     *   - boolean (premium) features are not entitled;
     *   - cost-bearing metered features (SMS/WhatsApp) are not entitled;
     *   - other limits (staff, branches, bookings) are uncapped.
     * This holds both for a subscribed plan that omits the feature and for a
     * tenant with no subscription at all. A tenant whose latest subscription
     * is cancelled/expired is denied everything plan-based. past_due keeps
     * plan access (grace). NULL is returned only for an unknown feature key.
     *
     * @return array{is_enabled: int, limit_value: ?int}|null
     */
    private function resolve(int $tenantId, string $feature): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT is_enabled, limit_value FROM tenant_feature_overrides
             WHERE tenant_id = :t AND feature_key = :f
               AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())',
        );
        $stmt->execute(['t' => $tenantId, 'f' => $feature]);
        $row = $stmt->fetch();
        if ($row !== false) {
            /** @var array{is_enabled: int, limit_value: ?int} */
            return $row;
        }

        $type = $this->db->pdo()->prepare('SELECT value_type FROM features WHERE code = :f');
        $type->execute(['f' => $feature]);
        $valueType = $type->fetchColumn();
        if ($valueType === false) {
            return null; // unknown feature key
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT s.status, pf.is_enabled, pf.limit_value
               FROM tenant_subscriptions s
               LEFT JOIN plan_features pf ON pf.plan_id = s.plan_id AND pf.feature_key = :f
              WHERE s.tenant_id = :t
              ORDER BY s.id DESC LIMIT 1',
        );
        $stmt->execute(['t' => $tenantId, 'f' => $feature]);
        $row = $stmt->fetch();
        if ($row !== false && !in_array((string) $row['status'], ['trialing', 'active', 'past_due'], true)) {
            return ['is_enabled' => 0, 'limit_value' => null];
        }
        if ($row === false || $row['is_enabled'] === null) {
            // Never subscribed, or the plan omits the feature.
            return $valueType === 'boolean' || in_array($feature, self::COST_BEARING, true)
                ? ['is_enabled' => 0, 'limit_value' => null]
                : ['is_enabled' => 1, 'limit_value' => null];
        }

        /** @var array{is_enabled: int, limit_value: ?int} */
        return $row;
    }

    /** The tenant's live subscription + plan, for admin/billing UIs.
     * @return array<string, mixed>|null
     */
    public function subscriptionFor(int $tenantId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id, s.status, s.current_period_start, s.current_period_end,
                    s.trial_ends_at, p.code AS plan_code, p.name AS plan_name
             FROM tenant_subscriptions s
             JOIN plans p ON p.id = s.plan_id
             WHERE s.tenant_id = ? AND s.status IN ('trialing','active','past_due')
             ORDER BY s.id DESC LIMIT 1",
        );
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** Assign a plan to a tenant (admin action): ends any live sub, starts a new one. */
    public function assignPlan(int $tenantId, int $planId, string $uuid): void
    {
        $this->db->transaction(function () use ($tenantId, $planId, $uuid): void {
            $pdo = $this->db->pdo();
            $pdo->prepare(
                "UPDATE tenant_subscriptions SET status = 'cancelled', cancelled_at = UTC_TIMESTAMP()
                 WHERE tenant_id = :t AND status IN ('trialing','active','past_due')",
            )->execute(['t' => $tenantId]);
            $pdo->prepare(
                "INSERT INTO tenant_subscriptions
                    (uuid, tenant_id, plan_id, status, starts_at, current_period_start, current_period_end)
                 VALUES (:uuid, :t, :p, 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP(),
                         UTC_TIMESTAMP() + INTERVAL 1 MONTH)",
            )->execute(['uuid' => $uuid, 't' => $tenantId, 'p' => $planId]);
        });
    }

    /**
     * Start a new business on the trial plan so it runs on explicit plan
     * limits from day one (not on the bare secure defaults). No-op when no
     * active plan with $planCode exists. Call inside the registration
     * transaction.
     */
    public function startTrial(int $tenantId, string $uuid, string $planCode = 'trial', int $days = 14): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM plans WHERE code = :c AND is_active = 1 LIMIT 1');
        $stmt->execute(['c' => $planCode]);
        $planId = $stmt->fetchColumn();
        if ($planId === false) {
            return;
        }

        $this->db->pdo()->prepare(
            "INSERT INTO tenant_subscriptions
                (uuid, tenant_id, plan_id, status, starts_at, current_period_start, current_period_end, trial_ends_at)
             VALUES (:uuid, :t, :p, 'trialing', UTC_TIMESTAMP(), UTC_TIMESTAMP(),
                     UTC_TIMESTAMP() + INTERVAL :d DAY, UTC_TIMESTAMP() + INTERVAL :d2 DAY)",
        )->execute(['uuid' => $uuid, 't' => $tenantId, 'p' => (int) $planId, 'd' => $days, 'd2' => $days]);
    }

    /** End the live subscription immediately — the tenant loses plan features. */
    public function cancelSubscription(int $tenantId): void
    {
        $this->db->pdo()->prepare(
            "UPDATE tenant_subscriptions SET status = 'cancelled', cancelled_at = UTC_TIMESTAMP()
             WHERE tenant_id = :t AND status IN ('trialing','active','past_due')",
        )->execute(['t' => $tenantId]);
    }

    /**
     * Active + scheduled overrides for display on the tenant admin page.
     * @return list<array{feature_key: string, is_enabled: int, limit_value: ?int, expires_at: ?string}>
     */
    public function overrides(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT feature_key, is_enabled, limit_value, expires_at
             FROM tenant_feature_overrides WHERE tenant_id = :t ORDER BY feature_key',
        );
        $stmt->execute(['t' => $tenantId]);

        /** @var list<array{feature_key: string, is_enabled: int, limit_value: ?int, expires_at: ?string}> */
        return array_values($stmt->fetchAll());
    }

    /** Upsert a per-tenant override; expires_at NULL means indefinite. */
    public function setOverride(int $tenantId, string $feature, bool $enabled, ?int $limit, ?string $expiresAt): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO tenant_feature_overrides (tenant_id, feature_key, is_enabled, limit_value, expires_at)
             VALUES (:t, :f, :e, :l, :x)
             ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled),
                 limit_value = VALUES(limit_value), expires_at = VALUES(expires_at)',
        )->execute([
            't' => $tenantId,
            'f' => $feature,
            'e' => $enabled ? 1 : 0,
            'l' => $limit,
            'x' => $expiresAt,
        ]);
    }

    public function clearOverride(int $tenantId, string $feature): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM tenant_feature_overrides WHERE tenant_id = :t AND feature_key = :f',
        )->execute(['t' => $tenantId, 'f' => $feature]);
    }

    /** Feature catalog keys for validating override input.
     * @return list<string>
     */
    public function featureKeys(): array
    {
        $stmt = $this->db->pdo()->query('SELECT code FROM features ORDER BY code');
        if ($stmt === false) {
            return [];
        }

        /** @var list<string> */
        return array_map(static fn(mixed $k): string => (string) $k, $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return list<array{id: int, code: string, name: string, price_minor: int}> */
    public function plans(): array
    {
        $stmt = $this->db->pdo()->query(
            'SELECT id, code, name, price_minor FROM plans WHERE is_active = 1 ORDER BY sort_position',
        );

        /** @var list<array{id: int, code: string, name: string, price_minor: int}> */
        return $stmt === false ? [] : array_values($stmt->fetchAll());
    }
}
