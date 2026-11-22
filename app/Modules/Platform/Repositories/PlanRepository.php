<?php

declare(strict_types=1);

namespace App\Modules\Platform\Repositories;

use App\Core\Database\Database;

/**
 * Plan catalogue + subscription roster for the platform console. Tenant-side
 * reads stay in FeatureGate; this is the admin CRUD side.
 */
final class PlanRepository
{
    public function __construct(private readonly Database $db) {}

    /**
     * All plans (including inactive) with live-subscriber counts.
     *
     * @return list<array<string, mixed>>
     */
    public function listAllWithStats(): array
    {
        $stmt = $this->db->pdo()->query(
            "SELECT p.*,
                    (SELECT COUNT(*) FROM tenant_subscriptions s
                      WHERE s.plan_id = p.id
                        AND s.status IN ('trialing', 'active', 'past_due')) AS subscribers,
                    (SELECT COUNT(*) FROM plan_features f
                      WHERE f.plan_id = p.id AND f.is_enabled = 1) AS enabled_features
             FROM plans p
             ORDER BY p.sort_position, p.id",
        );

        /** @var list<array<string, mixed>> */
        return $stmt === false ? [] : array_values($stmt->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM plans WHERE uuid = :u LIMIT 1');
        $stmt->execute(['u' => $uuid]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function codeTaken(string $code, ?int $exceptPlanId = null): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM plans WHERE code = :c AND (:e IS NULL OR id <> :e2) LIMIT 1',
        );
        $stmt->execute(['c' => $code, 'e' => $exceptPlanId, 'e2' => $exceptPlanId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Active feature definitions for the plan feature matrix.
     *
     * @return list<array{code: string, name: string, value_type: string, unit: ?string}>
     */
    public function features(): array
    {
        $stmt = $this->db->pdo()->query(
            'SELECT code, name, value_type, unit FROM features WHERE is_active = 1 ORDER BY sort_position',
        );

        /** @var list<array{code: string, name: string, value_type: string, unit: ?string}> */
        return $stmt === false ? [] : array_values($stmt->fetchAll());
    }

    /**
     * A plan's feature map keyed by feature code.
     *
     * @return array<string, array{is_enabled: bool, limit_value: ?int}>
     */
    public function featuresFor(int $planId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT feature_key, is_enabled, limit_value FROM plan_features WHERE plan_id = :p',
        );
        $stmt->execute(['p' => $planId]);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(string) $row['feature_key']] = [
                'is_enabled' => (int) $row['is_enabled'] === 1,
                'limit_value' => $row['limit_value'] === null ? null : (int) $row['limit_value'],
            ];
        }

        return $map;
    }

    /** @param array<string, mixed> $data */
    public function insert(array $data): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO plans (uuid, code, name, description, price_minor, currency, billing_interval, is_active, sort_position)
             VALUES (:uuid, :code, :name, :description, :price_minor, :currency, :billing_interval, :is_active, :sort_position)',
        )->execute($data);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    /** Delete a plan — refused once it has any subscription history (audit trail). */
    public function delete(int $planId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM tenant_subscriptions WHERE plan_id = :p LIMIT 1');
        $stmt->execute(['p' => $planId]);
        if ($stmt->fetchColumn() !== false) {
            return false;
        }

        $this->db->pdo()->prepare('DELETE FROM plans WHERE id = :p')->execute(['p' => $planId]);

        return true;
    }

    /** @param array<string, mixed> $data */
    public function update(int $planId, array $data): void
    {
        $this->db->pdo()->prepare(
            'UPDATE plans SET code = :code, name = :name, description = :description,
                    price_minor = :price_minor, currency = :currency,
                    billing_interval = :billing_interval, is_active = :is_active,
                    sort_position = :sort_position
             WHERE id = :id',
        )->execute($data + ['id' => $planId]);
    }

    /**
     * Replace the plan's feature map inside a transaction.
     *
     * @param array<string, array{is_enabled: bool, limit_value: ?int}> $map
     */
    public function saveFeatures(int $planId, array $map): void
    {
        $this->db->transaction(function () use ($planId, $map): void {
            $pdo = $this->db->pdo();
            $pdo->prepare('DELETE FROM plan_features WHERE plan_id = :p')->execute(['p' => $planId]);
            $stmt = $pdo->prepare(
                'INSERT INTO plan_features (plan_id, feature_key, is_enabled, limit_value)
                 VALUES (:p, :k, :e, :l)',
            );
            foreach ($map as $key => $row) {
                $stmt->execute([
                    'p' => $planId,
                    'k' => $key,
                    'e' => $row['is_enabled'] ? 1 : 0,
                    'l' => $row['limit_value'],
                ]);
            }
        });
    }

    /**
     * Tenants on a live subscription of this plan, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function subscribersFor(int $planId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT t.name, t.slug, t.status AS tenant_status,
                    s.status, s.current_period_end, s.trial_ends_at
             FROM tenant_subscriptions s
             JOIN tenants t ON t.id = s.tenant_id
             WHERE s.plan_id = :p AND s.status IN ('trialing', 'active', 'past_due')
             ORDER BY s.current_period_end ASC",
        );
        $stmt->execute(['p' => $planId]);

        /** @var list<array<string, mixed>> */
        return $stmt === false ? [] : array_values($stmt->fetchAll());
    }

    /** @return list<array{code: string, name: string, symbol: string}> */
    public function currencies(): array
    {
        $stmt = $this->db->pdo()->query(
            'SELECT code, name, symbol FROM currencies WHERE is_active = 1 ORDER BY sort_position',
        );

        /** @var list<array{code: string, name: string, symbol: string}> */
        return $stmt === false ? [] : array_values($stmt->fetchAll());
    }
}
