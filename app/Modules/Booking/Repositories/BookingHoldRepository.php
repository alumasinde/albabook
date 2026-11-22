<?php

declare(strict_types=1);

namespace App\Modules\Booking\Repositories;

use App\Core\Database\Database;

/**
 * booking_holds / booking_hold_items / booking_hold_resources — short-lived
 * slot reservations (booking-engine.md §14). A hold blocks staff and
 * resource occupancy exactly like a live booking (staffOccupied and
 * freeResourceForType count them); the booking transaction that converts a
 * hold excludes its rows so it never conflicts with itself.
 */
final class BookingHoldRepository
{
    public function __construct(private readonly Database $db) {}

    /** @param array<string, mixed> $row */
    public function insertHold(array $row): int
    {
        $columns = array_keys($row);
        $sql = 'INSERT INTO booking_holds (' . implode(',', $columns) . ') VALUES (' . implode(',', array_map(static fn($c): string => ':' . $c, $columns)) . ')';
        $this->db->pdo()->prepare($sql)->execute($row);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<string, mixed> $row */
    public function insertHoldItem(array $row): void
    {
        $columns = array_keys($row);
        $sql = 'INSERT INTO booking_hold_items (' . implode(',', $columns) . ') VALUES (' . implode(',', array_map(static fn($c): string => ':' . $c, $columns)) . ')';
        $this->db->pdo()->prepare($sql)->execute($row);
    }

    /** @param array<string, mixed> $row */
    public function insertHoldResource(array $row): void
    {
        $columns = array_keys($row);
        $sql = 'INSERT INTO booking_hold_resources (' . implode(',', $columns) . ') VALUES (' . implode(',', array_map(static fn($c): string => ':' . $c, $columns)) . ')';
        $this->db->pdo()->prepare($sql)->execute($row);
    }

    /**
     * Hold row looked up by its token hash and locked FOR UPDATE inside the
     * converting transaction — the lock serialises conversion against a
     * parallel release and a second conversion attempt on the same token.
     * @return array<string, mixed>|null
     */
    public function lockByTokenHash(string $tokenHash): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, tenant_id, branch_id, status, expires_at, request_hash, converted_appointment_id
             FROM booking_holds WHERE token_hash = :th FOR UPDATE',
        );
        $stmt->execute(['th' => $tokenHash]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Active, unexpired hold by token hash — read path (no lock).
     * @return array<string, mixed>|null
     */
    public function findActiveByTokenHash(string $tokenHash): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, tenant_id, branch_id, status, expires_at, request_hash
             FROM booking_holds
             WHERE token_hash = :th AND status = 'active' AND expires_at > UTC_TIMESTAMP()",
        );
        $stmt->execute(['th' => $tokenHash]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function itemsFor(int $tenantId, int $holdId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT item_position, staff_id, occupied_start_at, occupied_end_at
             FROM booking_hold_items WHERE tenant_id = :t AND hold_id = :h ORDER BY item_position',
        );
        $stmt->execute(['t' => $tenantId, 'h' => $holdId]);

        return array_values($stmt->fetchAll());
    }

    /** Release an active hold. Returns false when it was not active (idempotent-safe). */
    public function release(int $tenantId, int $holdId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE booking_holds SET status = 'released', updated_at = UTC_TIMESTAMP()
             WHERE tenant_id = :t AND id = :h AND status = 'active'",
        );
        $stmt->execute(['t' => $tenantId, 'h' => $holdId]);

        return $stmt->rowCount() > 0;
    }

    public function markConverted(int $tenantId, int $holdId, int $appointmentId): void
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE booking_holds SET status = 'converted', converted_appointment_id = :a, updated_at = UTC_TIMESTAMP()
             WHERE tenant_id = :t AND id = :h AND status = 'active'",
        );
        $stmt->execute(['a' => $appointmentId, 't' => $tenantId, 'h' => $holdId]);
    }

    /** Live holds held by one client fingerprint (max_active_holds_per_client). */
    public function countActiveForClient(int $tenantId, string $fingerprint): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM booking_holds
             WHERE tenant_id = :t AND client_fingerprint = :f
               AND status = 'active' AND expires_at > UTC_TIMESTAMP()",
        );
        $stmt->execute(['t' => $tenantId, 'f' => $fingerprint]);

        return (int) $stmt->fetchColumn();
    }
}
