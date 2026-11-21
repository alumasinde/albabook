<?php

declare(strict_types=1);

namespace App\Modules\Booking\Repositories;

use App\Core\Database\Database;

/**
 * Read queries for the public booking page and its availability endpoint.
 * Every query is tenant-scoped by the id resolved from the slug — the slug
 * itself is never trusted for anything else. Returns raw rows; presentation
 * belongs to the controller.
 */
final class BookingPageRepository
{
    public function __construct(private readonly Database $db) {}

    /** @return array<string, mixed>|null */
    public function pageSettings(int $tenantId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT is_enabled, seo_title, seo_description, booking_flow, staff_selection,
                    branch_selection, service_presentation, show_prices, show_duration, default_locale
             FROM booking_page_settings WHERE tenant_id = :t',
        );
        $stmt->execute(['t' => $tenantId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public function branding(int $tenantId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT primary_color, secondary_color, accent_color, background_color, text_color, appearance
             FROM tenant_branding WHERE tenant_id = :t',
        );
        $stmt->execute(['t' => $tenantId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function branches(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, uuid, name, address_line1, address_line2, county, town, phone, email, timezone
             FROM branches WHERE tenant_id = :t AND is_active = 1 ORDER BY name',
        );
        $stmt->execute(['t' => $tenantId]);

        return array_values($stmt->fetchAll());
    }

    /** @return list<array<string, mixed>> */
    public function services(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.id, s.uuid, s.name, s.description, s.duration_minutes,
                    s.price_type, s.price_amount_minor, s.min_amount_minor, s.max_amount_minor, s.currency,
                    sc.name AS category_name
             FROM services s
             LEFT JOIN service_categories sc ON sc.tenant_id = s.tenant_id AND sc.id = s.service_category_id
             WHERE s.tenant_id = :t AND s.is_active = 1 AND s.is_bookable_online = 1
             ORDER BY s.sort_position, s.name',
        );
        $stmt->execute(['t' => $tenantId]);

        return array_values($stmt->fetchAll());
    }

    /**
     * Staff who can perform a service at a branch and are bookable online.
     * Ordered by booking_priority so "first available" follows tenant policy.
     *
     * @return list<array<string, mixed>>
     */
    public function staffForService(int $tenantId, int $serviceId, int $branchId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.id, s.display_name, s.booking_priority, s.bio
             FROM staff s
             JOIN staff_services ss ON ss.tenant_id = s.tenant_id AND ss.staff_id = s.id
             JOIN staff_branches sb ON sb.tenant_id = s.tenant_id AND sb.staff_id = s.id
             WHERE s.tenant_id = :t AND s.status = \'active\' AND s.is_bookable_online = 1
               AND ss.service_id = :svc AND ss.is_bookable_online = 1
               AND sb.branch_id = :b
             ORDER BY s.booking_priority, s.display_name',
        );
        $stmt->execute(['t' => $tenantId, 'svc' => $serviceId, 'b' => $branchId]);

        return array_values($stmt->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function branch(int $tenantId, int $branchId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, name, timezone FROM branches WHERE tenant_id = :t AND id = :b AND is_active = 1',
        );
        $stmt->execute(['t' => $tenantId, 'b' => $branchId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Effective business-hours windows for a branch on a weekday.
     *
     * @return list<array{opens_at: string, closes_at: string}>
     */
    public function businessHours(int $tenantId, int $branchId, int $weekday, string $date): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT opens_at, closes_at FROM business_hours
             WHERE tenant_id = :t AND branch_id = :b AND weekday = :w
               AND (effective_from IS NULL OR effective_from <= :d)
               AND (effective_to IS NULL OR effective_to >= :d)',
        );
        $stmt->execute(['t' => $tenantId, 'b' => $branchId, 'w' => $weekday, 'd' => $date]);

        return array_values($stmt->fetchAll());
    }

    /**
     * All effective business-hours rows for a branch, for the public
     * "Opening hours" card.
     *
     * @return list<array{weekday: int, opens_at: string, closes_at: string}>
     */
    public function openingHours(int $tenantId, int $branchId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT weekday, opens_at, closes_at FROM business_hours
             WHERE tenant_id = :t AND branch_id = :b ORDER BY weekday, opens_at',
        );
        $stmt->execute(['t' => $tenantId, 'b' => $branchId]);

        return array_values($stmt->fetchAll());
    }

    /**
     * Staff shift windows for a weekday at a branch.
     *
     * @param list<int> $staffIds
     * @return list<array{staff_id: int, starts_at: string, ends_at: string}>
     */
    public function staffSchedules(int $tenantId, array $staffIds, int $branchId, int $weekday, string $date): array
    {
        if ($staffIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $staffIds));
        $stmt = $this->db->pdo()->prepare(
            "SELECT staff_id, starts_at, ends_at FROM staff_schedules
             WHERE tenant_id = :t AND staff_id IN ({$in}) AND branch_id = :b AND weekday = :w
               AND (effective_from IS NULL OR effective_from <= :d)
               AND (effective_to IS NULL OR effective_to >= :d)",
        );
        $stmt->execute(['t' => $tenantId, 'b' => $branchId, 'w' => $weekday, 'd' => $date]);

        return array_values($stmt->fetchAll());
    }

    /**
     * @param list<int> $staffIds
     * @return list<array{staff_id: int, starts_at: string, ends_at: string}>
     */
    public function staffBreaks(int $tenantId, array $staffIds, int $weekday, string $date): array
    {
        if ($staffIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $staffIds));
        $stmt = $this->db->pdo()->prepare(
            "SELECT staff_id, starts_at, ends_at FROM staff_breaks
             WHERE tenant_id = :t AND staff_id IN ({$in}) AND weekday = :w
               AND (effective_from IS NULL OR effective_from <= :d)
               AND (effective_to IS NULL OR effective_to >= :d)",
        );
        $stmt->execute(['t' => $tenantId, 'w' => $weekday, 'd' => $date]);

        return array_values($stmt->fetchAll());
    }

    /**
     * Occupied ranges (buffer-inclusive) for staff within a UTC window.
     * Mirrors the write-path check in AppointmentRepository::staffOccupied.
     *
     * @param list<int> $staffIds
     * @return list<array{staff_id: int, occupied_start_at: string, occupied_end_at: string}>
     */
    public function occupiedRanges(int $tenantId, array $staffIds, string $fromUtc, string $toUtc): array
    {
        if ($staffIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $staffIds));
        $stmt = $this->db->pdo()->prepare(
            "SELECT ai.staff_id, ai.occupied_start_at, ai.occupied_end_at
             FROM appointment_items ai
             JOIN appointments a ON a.tenant_id = ai.tenant_id AND a.id = ai.appointment_id
             WHERE ai.tenant_id = :t AND ai.staff_id IN ({$in})
               AND ai.occupied_start_at < :to AND ai.occupied_end_at > :from
               AND a.status IN ('pending', 'confirmed', 'checked_in')",
        );
        $stmt->execute(['t' => $tenantId, 'from' => $fromUtc, 'to' => $toUtc]);

        return array_values($stmt->fetchAll());
    }

    /**
     * Branch policy row, falling back to the tenant-wide row (branch_scope = 0).
     *
     * @return array<string, mixed>|null
     */
    public function policy(int $tenantId, ?int $branchId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT slot_interval_minutes, min_notice_minutes, max_advance_days, confirmation_mode
             FROM booking_policies WHERE tenant_id = :t AND branch_scope = :s',
        );
        $stmt->execute(['t' => $tenantId, 's' => $branchId ?? 0]);
        $row = $stmt->fetch();
        if ($row === false && $branchId !== null) {
            $stmt->execute(['t' => $tenantId, 's' => 0]);
            $row = $stmt->fetch();
        }

        return $row ?: null;
    }
}
