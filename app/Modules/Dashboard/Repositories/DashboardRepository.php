<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Repositories;

use App\Core\Database\Database;

/**
 * Dashboard aggregate reads. Metrics are aggregated in SQL, never by loading
 * rows into PHP (dashboard.md §7 rules). All ranges are UTC instants computed
 * by the caller in the tenant timezone.
 */
final class DashboardRepository
{
    public function __construct(private readonly Database $db) {}

    /**
     * @param array<int>|null $branchIds
     * @param array<string, mixed> $params
     */
    private function scopeSql(?array $branchIds, array &$params, string $column = 'branch_id'): string
    {
        if ($branchIds === null) {
            return '';
        }
        if ($branchIds === []) {
            return ' AND 1 = 0';
        }
        $marks = [];
        foreach (array_values($branchIds) as $i => $id) {
            $key = 'scope_b' . $i;
            $marks[] = ':' . $key;
            $params[$key] = $id;
        }

        return " AND {$column} IN (" . implode(',', $marks) . ')';
    }

    /**
     * Counts per status for appointments overlapping [fromUtc, toUtc).
     * @param array<int>|null $branchIds
     * @return array<string, int>
     */
    public function appointmentCounts(int $tenantId, ?array $branchIds, string $fromUtc, string $toUtc): array
    {
        $params = ['t' => $tenantId, 'f' => $fromUtc, 'to' => $toUtc];
        $scope = $this->scopeSql($branchIds, $params);
        $stmt = $this->db->pdo()->prepare(
            "SELECT status, COUNT(*) AS n FROM appointments
              WHERE tenant_id = :t AND start_at < :to AND end_at >= :f{$scope}
              GROUP BY status",
        );
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['status']] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * Pending bookings in the future, regardless of period (dashboard.md §2).
     * @param array<int>|null $branchIds
     */
    public function pendingBookings(int $tenantId, ?array $branchIds, string $nowUtc): int
    {
        $params = ['t' => $tenantId, 'n' => $nowUtc];
        $scope = $this->scopeSql($branchIds, $params);
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM appointments WHERE tenant_id = :t AND status = 'pending' AND start_at > :n{$scope}",
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function activeCustomers(int $tenantId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM customers WHERE tenant_id = :t AND status = 'active'",
        );
        $stmt->execute(['t' => $tenantId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Collected revenue: succeeded payments − succeeded refunds (dashboard.md §2).
     * @param array<int>|null $branchIds
     */
    public function collectedMinor(int $tenantId, ?array $branchIds, string $fromUtc, string $toUtc): int
    {
        $params = ['t' => $tenantId, 'f' => $fromUtc, 'to' => $toUtc];
        $scope = $this->scopeSql($branchIds, $params);
        $pdo = $this->db->pdo();
        $in = $pdo->prepare(
            "SELECT COALESCE(SUM(amount_minor), 0) FROM payments
              WHERE tenant_id = :t AND status = 'succeeded' AND received_at >= :f AND received_at < :to{$scope}",
        );
        $in->execute($params);
        $out = $pdo->prepare(
            "SELECT COALESCE(SUM(amount_minor), 0) FROM refunds
              WHERE tenant_id = :t AND status = 'succeeded' AND refunded_at >= :f AND refunded_at < :to{$scope}",
        );
        $out->execute($params);

        return (int) $in->fetchColumn() - (int) $out->fetchColumn();
    }

    /**
     * Booked revenue: confirmed/checked_in/completed totals in the period.
     * @param array<int>|null $branchIds
     */
    public function bookedMinor(int $tenantId, ?array $branchIds, string $fromUtc, string $toUtc): int
    {
        $params = ['t' => $tenantId, 'f' => $fromUtc, 'to' => $toUtc];
        $scope = $this->scopeSql($branchIds, $params);
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(total_amount_minor), 0) FROM appointments
              WHERE tenant_id = :t AND status IN ('confirmed', 'checked_in', 'completed')
                AND start_at >= :f AND start_at < :to{$scope}",
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Appointment counts bucketed by local date for charts.
     * @param array<int>|null $branchIds
     * @return array<string, int> date => count
     */
    public function bookingsByDay(int $tenantId, ?array $branchIds, string $fromUtc, string $toUtc): array
    {
        $params = ['t' => $tenantId, 'f' => $fromUtc, 'to' => $toUtc];
        $scope = $this->scopeSql($branchIds, $params);
        $stmt = $this->db->pdo()->prepare(
            "SELECT start_at FROM appointments
              WHERE tenant_id = :t AND start_at >= :f AND start_at < :to{$scope}",
        );
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $key = (string) $row['start_at'];
            $out[$key] = ($out[$key] ?? 0) + 1;
        }

        return $out;
    }

    /**
     * @param array<int>|null $branchIds
     * @return list<array<string, mixed>>
     */
    public function todaySchedule(int $tenantId, ?array $branchIds, string $fromUtc, string $toUtc, int $limit): array
    {
        $params = ['t' => $tenantId, 'f' => $fromUtc, 'to' => $toUtc];
        $scope = $this->scopeSql($branchIds, $params);
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.uuid, a.status, a.start_at, a.customer_name_snapshot,
                    s.display_name AS staff_name,
                    (SELECT ai.service_name_snapshot FROM appointment_items ai
                      WHERE ai.tenant_id = a.tenant_id AND ai.appointment_id = a.id
                      ORDER BY ai.item_position LIMIT 1) AS service_name,
                    b.timezone AS branch_timezone
               FROM appointments a
          LEFT JOIN staff s ON s.tenant_id = a.tenant_id AND s.id = a.staff_id
               JOIN branches b ON b.tenant_id = a.tenant_id AND b.id = a.branch_id
              WHERE a.tenant_id = :t AND a.start_at >= :f AND a.start_at < :to
                AND a.status IN ('pending', 'confirmed', 'checked_in'){$scope}
           ORDER BY a.start_at
              LIMIT " . max(1, $limit),
        );
        $stmt->execute($params);

        return array_values($stmt->fetchAll());
    }

    /**
     * New customers whose first appointment falls in the period (dashboard.md §2).
     * @param array<int>|null $branchIds
     */
    public function newCustomers(int $tenantId, ?array $branchIds, string $fromUtc, string $toUtc): int
    {
        $params = ['t' => $tenantId, 'f' => $fromUtc, 'to' => $toUtc];
        $scope = $this->scopeSql($branchIds, $params, 'a.branch_id');
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(DISTINCT a.customer_id) FROM appointments a
              WHERE a.tenant_id = :t AND a.customer_id IS NOT NULL
                AND a.start_at >= :f AND a.start_at < :to
                AND NOT EXISTS (
                    SELECT 1 FROM appointments prev
                     WHERE prev.tenant_id = a.tenant_id AND prev.customer_id = a.customer_id
                       AND prev.start_at < :f){$scope}",
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }
}
