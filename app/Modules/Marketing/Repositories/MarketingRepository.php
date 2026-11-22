<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Repositories;

use App\Core\Database\Database;

/**
 * Read model for the public marketing site (live plan pricing) and the
 * demo-request inbox. Pricing comes straight from the plans/plan_features
 * tables that platform admins manage, so the site never drifts from billing.
 */
final class MarketingRepository
{
    public function __construct(private readonly Database $db) {}

    /**
     * Active plans in display order, each with its feature list.
     *
     * @return list<array{
     *     code: string, name: string, description: ?string, price_minor: int,
     *     currency: string, billing_interval: string,
     *     features: list<array{name: string, value_type: string, unit: ?string, limit_value: ?int}>
     * }>
     */
    public function publicPlans(): array
    {
        $plans = $this->db->pdo()->query(
            'SELECT id, code, name, description, price_minor, currency, billing_interval
               FROM plans WHERE is_active = 1 ORDER BY sort_position, price_minor',
        );
        $rows = $plans === false ? [] : $plans->fetchAll();
        if ($rows === []) {
            return [];
        }

        $features = $this->db->pdo()->query(
            'SELECT pf.plan_id, f.name, f.value_type, f.unit, pf.limit_value
               FROM plan_features pf
               JOIN features f ON f.code = pf.feature_key
              WHERE pf.is_enabled = 1 AND f.is_active = 1
              ORDER BY f.sort_position, f.name',
        );
        $byPlan = [];
        foreach ($features === false ? [] : $features->fetchAll() as $f) {
            $byPlan[(int) $f['plan_id']][] = [
                'name' => (string) $f['name'],
                'value_type' => (string) $f['value_type'],
                'unit' => $f['unit'] !== null ? (string) $f['unit'] : null,
                'limit_value' => $f['limit_value'] !== null ? (int) $f['limit_value'] : null,
            ];
        }

        return array_map(static fn(array $p): array => [
            'code' => (string) $p['code'],
            'name' => (string) $p['name'],
            'description' => $p['description'] !== null ? (string) $p['description'] : null,
            'price_minor' => (int) $p['price_minor'],
            'currency' => (string) $p['currency'],
            'billing_interval' => (string) $p['billing_interval'],
            'features' => $byPlan[(int) $p['id']] ?? [],
        ], array_values($rows));
    }

    /** @param array<string, string|null> $data */
    public function insertDemoRequest(array $data): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO demo_requests
                (uuid, full_name, business_name, email, phone, business_type, branch_count, message, ip_hash, user_agent)
             VALUES (:uuid, :full_name, :business_name, :email, :phone, :business_type, :branch_count, :message, :ip_hash, :user_agent)',
        )->execute($data);
    }

    /**
     * Newest first, optionally filtered by status.
     * @return list<array<string, mixed>>
     */
    public function demoRequests(?string $status, int $limit = 200): array
    {
        $sql = 'SELECT uuid, full_name, business_name, email, phone, business_type, branch_count,
                       message, status, created_at
                  FROM demo_requests';
        $params = [];
        if ($status !== null) {
            $sql .= ' WHERE status = :s';
            $params['s'] = $status;
        }
        $stmt = $this->db->pdo()->prepare($sql . ' ORDER BY created_at DESC, id DESC LIMIT :lim');
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return array_values($stmt->fetchAll());
    }

    /** @return array<string, int> status => count */
    public function demoRequestCounts(): array
    {
        $stmt = $this->db->pdo()->query('SELECT status, COUNT(*) AS n FROM demo_requests GROUP BY status');
        $counts = ['new' => 0, 'contacted' => 0, 'closed' => 0];
        foreach ($stmt === false ? [] : $stmt->fetchAll() as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    public function setDemoRequestStatus(string $uuid, string $status): bool
    {
        $stmt = $this->db->pdo()->prepare('UPDATE demo_requests SET status = :s WHERE uuid = :u');
        $stmt->execute(['s' => $status, 'u' => $uuid]);

        return $stmt->rowCount() === 1;
    }
}
