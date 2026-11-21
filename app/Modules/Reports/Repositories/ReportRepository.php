<?php

declare(strict_types=1);

namespace App\Modules\Reports\Repositories;

use App\Core\Database\Database;

/**
 * Tenant-scoped reporting queries. All aggregates are computed in SQL over
 * the member's tenant only — no cross-tenant leakage.
 */
final class ReportRepository
{
    public function __construct(private readonly Database $db) {}

    /**
     * Branch-scope clause for branch-limited memberships. Ints are inlined
     * (cast), so no parameter juggling is needed.
     * @param list<int>|null $branchIds
     */
    private function inBranch(?array $branchIds, string $column): string
    {
        if ($branchIds === null) {
            return '';
        }
        if ($branchIds === []) {
            return ' AND 1 = 0';
        }

        return ' AND ' . $column . ' IN (' . implode(',', array_map('intval', $branchIds)) . ')';
    }

    /** Headline metrics for a [from, to) window (dates are UTC datetimes).
     * @param list<int>|null $branchIds
     * @return array<string, mixed>
     */
    public function summary(int $tenantId, string $from, string $to, ?array $branchIds = null): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(status = 'completed') AS completed,
                SUM(status = 'cancelled') AS cancelled,
                SUM(status = 'no_show') AS no_shows,
                SUM(status IN ('pending','confirmed','checked_in','in_service')) AS active
             FROM appointments
             WHERE tenant_id = ? AND start_at >= ? AND start_at < ?" . $this->inBranch($branchIds, 'branch_id'),
        );
        $stmt->execute([$tenantId, $from, $to]);
        $appointments = $stmt->fetch() ?: [];

        $cust = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM customers WHERE tenant_id = ? AND created_at >= ? AND created_at < ?",
        );
        $cust->execute([$tenantId, $from, $to]);

        return [
            'appointments_total' => (int) ($appointments['total'] ?? 0),
            'appointments_completed' => (int) ($appointments['completed'] ?? 0),
            'appointments_cancelled' => (int) ($appointments['cancelled'] ?? 0),
            'appointments_no_show' => (int) ($appointments['no_shows'] ?? 0),
            'appointments_active' => (int) ($appointments['active'] ?? 0),
            'new_customers' => (int) $cust->fetchColumn(),
        ];
    }

    /** Appointments per calendar day within the window.
     * @param list<int>|null $branchIds
     * @return list<array{day: string, count: int}>
     */
    public function daily(int $tenantId, string $from, string $to, ?array $branchIds = null): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT DATE(start_at) AS day, COUNT(*) AS count
             FROM appointments
             WHERE tenant_id = ? AND start_at >= ? AND start_at < ?' . $this->inBranch($branchIds, 'branch_id') . '
             GROUP BY DATE(start_at) ORDER BY day',
        );
        $stmt->execute([$tenantId, $from, $to]);

        /** @var list<array{day: string, count: int}> */
        return array_values($stmt->fetchAll());
    }

    /** Most-booked services within the window.
     * @param list<int>|null $branchIds
     * @return list<array<string, mixed>>
     */
    public function topServices(int $tenantId, string $from, string $to, ?array $branchIds = null): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT i.service_name_snapshot AS name, COUNT(*) AS bookings
             FROM appointment_items i
             JOIN appointments a
               ON a.tenant_id = i.tenant_id AND a.id = i.appointment_id
             WHERE i.tenant_id = ? AND a.start_at >= ? AND a.start_at < ?
               AND a.status NOT IN ('cancelled','rejected','expired')" . $this->inBranch($branchIds, 'a.branch_id') . "
             GROUP BY i.service_name_snapshot
             ORDER BY bookings DESC, name LIMIT 5",
        );
        $stmt->execute([$tenantId, $from, $to]);

        /** @var list<array<string, mixed>> */
        return array_values($stmt->fetchAll());
    }

    /** Per-day appointment breakdown for the Appointments tab.
     * @param list<int>|null $branchIds
     * @return list<array<string, mixed>>
     */
    public function appointmentsByDay(int $tenantId, string $from, string $to, ?array $branchIds = null): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT DATE(start_at) AS day, COUNT(*) AS total,
                    SUM(status = 'completed') AS completed,
                    SUM(status = 'cancelled') AS cancelled,
                    SUM(status = 'no_show') AS no_shows,
                    SUM(status IN ('pending','confirmed','checked_in','in_service')) AS active
             FROM appointments
             WHERE tenant_id = ? AND start_at >= ? AND start_at < ?" . $this->inBranch($branchIds, 'branch_id') . "
             GROUP BY DATE(start_at) ORDER BY day DESC",
        );
        $stmt->execute([$tenantId, $from, $to]);

        /** @var list<array<string, mixed>> */
        return array_values($stmt->fetchAll());
    }

    /** Per-service volume + revenue for the Appointments tab.
     * @param list<int>|null $branchIds
     * @return list<array<string, mixed>>
     */
    public function byService(int $tenantId, string $from, string $to, ?array $branchIds = null): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT i.service_name_snapshot AS service,
                    COUNT(*) AS bookings,
                    SUM(a.status = 'completed') AS completed,
                    SUM(a.status = 'no_show') AS no_shows,
                    SUM(a.status = 'cancelled') AS cancelled,
                    COALESCE(SUM(i.unit_amount_minor * i.quantity), 0) AS revenue_minor,
                    MAX(i.currency) AS currency
             FROM appointment_items i
             JOIN appointments a
               ON a.tenant_id = i.tenant_id AND a.id = i.appointment_id
             WHERE i.tenant_id = ? AND a.start_at >= ? AND a.start_at < ?
               AND a.status NOT IN ('cancelled','rejected','expired')" . $this->inBranch($branchIds, 'a.branch_id') . "
             GROUP BY i.service_name_snapshot
             ORDER BY bookings DESC, service",
        );
        $stmt->execute([$tenantId, $from, $to]);

        /** @var list<array<string, mixed>> */
        return array_values($stmt->fetchAll());
    }

    /** Per-day tender + refund totals for the Sales tab.
     * @param list<int>|null $branchIds
     * @return list<array<string, mixed>>
     */
    public function salesByDay(int $tenantId, string $from, string $to, ?array $branchIds = null): array
    {
        $days = [];
        $payments = $this->db->pdo()->prepare(
            "SELECT DATE(received_at) AS day, COUNT(*) AS payments_count,
                    COALESCE(SUM(amount_minor), 0) AS gross_minor
             FROM payments
             WHERE tenant_id = ? AND status = 'succeeded'
               AND received_at >= ? AND received_at < ?" . $this->inBranch($branchIds, 'branch_id') . "
             GROUP BY DATE(received_at)",
        );
        $payments->execute([$tenantId, $from, $to]);
        foreach ($payments->fetchAll() as $row) {
            $days[(string) $row['day']] = [
                'day' => (string) $row['day'],
                'payments_count' => (int) $row['payments_count'],
                'gross_minor' => (int) $row['gross_minor'],
                'refunded_minor' => 0,
            ];
        }
        $refunds = $this->db->pdo()->prepare(
            "SELECT DATE(refunded_at) AS day, COALESCE(SUM(amount_minor), 0) AS refunded_minor
             FROM refunds
             WHERE tenant_id = ? AND status = 'succeeded'
               AND refunded_at >= ? AND refunded_at < ?
               AND EXISTS (SELECT 1 FROM payments p WHERE p.id = refunds.payment_id"
               . $this->inBranch($branchIds, 'p.branch_id') . ")
             GROUP BY DATE(refunded_at)",
        );
        $refunds->execute([$tenantId, $from, $to]);
        foreach ($refunds->fetchAll() as $row) {
            $day = (string) $row['day'];
            if (!isset($days[$day])) {
                $days[$day] = ['day' => $day, 'payments_count' => 0, 'gross_minor' => 0, 'refunded_minor' => 0];
            }
            $days[$day]['refunded_minor'] = (int) $row['refunded_minor'];
        }
        $days = array_values($days);
        usort($days, static fn(array $a, array $b): int => strcmp((string) $b['day'], (string) $a['day']));

        return $days;
    }

    /** No-show rate per service for the No-Shows tab.
     * @param list<int>|null $branchIds
     * @return list<array<string, mixed>>
     */
    public function noShowsByService(int $tenantId, string $from, string $to, ?array $branchIds = null): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT i.service_name_snapshot AS service,
                    COUNT(DISTINCT a.id) AS booked,
                    SUM(a.status = 'no_show') AS no_shows
             FROM appointment_items i
             JOIN appointments a
               ON a.tenant_id = i.tenant_id AND a.id = i.appointment_id
             WHERE i.tenant_id = ? AND a.start_at >= ? AND a.start_at < ?
               AND a.status IN ('completed','no_show','cancelled','checked_in','in_service')" . $this->inBranch($branchIds, 'a.branch_id') . "
             GROUP BY i.service_name_snapshot
             ORDER BY no_shows DESC, booked DESC",
        );
        $stmt->execute([$tenantId, $from, $to]);

        /** @var list<array<string, mixed>> */
        return array_values($stmt->fetchAll());
    }

    /** Occupied minutes per resource for the Resources tab.
     * @param list<int>|null $branchIds
     * @return list<array<string, mixed>>
     */
    public function resourceUtilization(int $tenantId, string $from, string $to, ?array $branchIds = null): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT r.name AS resource, rt.name AS type, b.name AS branch,
                    COUNT(DISTINCT ai.appointment_id) AS bookings,
                    COALESCE(SUM(TIMESTAMPDIFF(MINUTE, ar.occupied_start_at, ar.occupied_end_at)), 0) AS occupied_minutes
             FROM appointment_resources ar
             JOIN resources r ON r.tenant_id = ar.tenant_id AND r.id = ar.resource_id
             JOIN resource_types rt ON rt.tenant_id = ar.tenant_id AND rt.id = r.resource_type_id
             JOIN branches b ON b.tenant_id = ar.tenant_id AND b.id = r.branch_id
             JOIN appointment_items ai ON ai.tenant_id = ar.tenant_id AND ai.id = ar.appointment_item_id
             JOIN appointments a ON a.tenant_id = ar.tenant_id AND a.id = ai.appointment_id
             WHERE ar.tenant_id = ?
               AND ar.occupied_start_at < ? AND ar.occupied_end_at > ?
               AND a.status NOT IN ('cancelled','rejected','expired','no_show')" . $this->inBranch($branchIds, 'b.id') . "
             GROUP BY r.id, r.name, rt.name, b.name
             ORDER BY occupied_minutes DESC, resource",
        );
        $stmt->execute([$tenantId, $to, $from]);

        /** @var list<array<string, mixed>> */
        return array_values($stmt->fetchAll());
    }

    /** Collected revenue + outstanding amounts (reports.view_financial).
     * @param list<int>|null $branchIds
     * @return array<string, mixed>
     */
    public function financial(int $tenantId, string $from, string $to, ?array $branchIds = null): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT
                COALESCE(SUM(amount_minor), 0) AS collected_minor,
                COUNT(*) AS payments_count
             FROM payments
             WHERE tenant_id = ? AND status = 'succeeded'
               AND received_at >= ? AND received_at < ?" . $this->inBranch($branchIds, 'branch_id'),
        );
        $stmt->execute([$tenantId, $from, $to]);
        $collected = $stmt->fetch() ?: [];

        $byMethod = $this->db->pdo()->prepare(
            "SELECT method, COALESCE(SUM(amount_minor), 0) AS amount_minor, COUNT(*) AS count
             FROM payments
             WHERE tenant_id = ? AND status = 'succeeded'
               AND received_at >= ? AND received_at < ?" . $this->inBranch($branchIds, 'branch_id') . "
             GROUP BY method ORDER BY amount_minor DESC",
        );
        $byMethod->execute([$tenantId, $from, $to]);

        $booked = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(total_amount_minor), 0)
             FROM appointments
             WHERE tenant_id = ? AND start_at >= ? AND start_at < ?
               AND status NOT IN ('cancelled','rejected','expired')" . $this->inBranch($branchIds, 'branch_id'),
        );
        $booked->execute([$tenantId, $from, $to]);

        return [
            'collected_minor' => (int) ($collected['collected_minor'] ?? 0),
            'payments_count' => (int) ($collected['payments_count'] ?? 0),
            'booked_value_minor' => (int) $booked->fetchColumn(),
            'by_method' => array_values($byMethod->fetchAll()),
        ];
    }

    /** Flat appointment rows for CSV export (reports.export).
     * @param list<int>|null $branchIds
     * @return list<array<string, mixed>>
     */
    public function exportRows(int $tenantId, string $from, string $to, ?array $branchIds = null): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.reference, a.start_at, a.end_at, a.status, a.payment_status,
                    a.customer_name_snapshot AS customer, b.name AS branch,
                    a.total_amount_minor, a.currency
             FROM appointments a
             JOIN branches b ON b.tenant_id = a.tenant_id AND b.id = a.branch_id
             WHERE a.tenant_id = ? AND a.start_at >= ? AND a.start_at < ?' . $this->inBranch($branchIds, 'a.branch_id') . '
             ORDER BY a.start_at',
        );
        $stmt->execute([$tenantId, $from, $to]);

        /** @var list<array<string, mixed>> */
        return array_values($stmt->fetchAll());
    }

    public function currency(int $tenantId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT currency FROM tenants WHERE id = ?');
        $stmt->execute([$tenantId]);

        return (string) ($stmt->fetchColumn() ?: 'KES');
    }
}
