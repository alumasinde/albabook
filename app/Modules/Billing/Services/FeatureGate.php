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
        $limit = $this->limit($tenantId, $feature);

        return $limit === null || $currentCount < $limit;
    }

    /**
     * The effective override or plan_feature row, or NULL when the tenant
     * has no subscription and no override (default-permit).
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

        $stmt = $this->db->pdo()->prepare(
            "SELECT pf.is_enabled, pf.limit_value
               FROM tenant_subscriptions s
               JOIN plan_features pf ON pf.plan_id = s.plan_id AND pf.feature_key = :f
              WHERE s.tenant_id = :t AND s.status IN ('trialing', 'active')
              LIMIT 1",
        );
        $stmt->execute(['t' => $tenantId, 'f' => $feature]);
        $row = $stmt->fetch();

        /** @var array{is_enabled: int, limit_value: ?int}|false $row */
        return $row === false ? null : $row;
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
