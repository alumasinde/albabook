<?php

declare(strict_types=1);

namespace App\Modules\Payments\Mpesa;

use App\Core\Database\Database;

/** Persistence for STK prompts and their payment rows (tenant-scoped). */
final class MpesaRepository
{
    public function __construct(private readonly Database $db) {}

    /**
     * The active Daraja account for a branch: a branch-specific account
     * wins over the tenant-wide one (branch_id NULL).
     * @return array<string, mixed>|null
     */
    public function activeAccountFor(int $tenantId, int $branchId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, uuid, tenant_id, branch_id, account_type, shortcode, credentials_encrypted
               FROM tenant_payment_accounts
              WHERE tenant_id = :t AND provider = 'mpesa_daraja' AND status = 'active'
                AND (branch_id = :b OR branch_id IS NULL)
              ORDER BY branch_id IS NULL, id
              LIMIT 1",
        );
        $stmt->execute(['t' => $tenantId, 'b' => $branchId]);

        return $stmt->fetch() ?: null;
    }

    /** @return array<string, mixed>|null */
    public function accountByUuid(string $uuid): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, uuid, tenant_id, branch_id, account_type, shortcode, credentials_encrypted, status
               FROM tenant_payment_accounts WHERE uuid = :u AND provider = 'mpesa_daraja'",
        );
        $stmt->execute(['u' => $uuid]);

        return $stmt->fetch() ?: null;
    }

    /** @return array<string, mixed>|null */
    public function accountById(int $tenantId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, uuid, tenant_id, branch_id, account_type, shortcode, credentials_encrypted, status
               FROM tenant_payment_accounts WHERE tenant_id = :t AND id = :id',
        );
        $stmt->execute(['t' => $tenantId, 'id' => $id]);

        return $stmt->fetch() ?: null;
    }

    /** @param array<string, mixed> $row */
    public function insertRequest(array $row): int
    {
        $columns = array_keys($row);
        $this->db->pdo()->prepare(
            'INSERT INTO mpesa_stk_requests (' . implode(', ', $columns) . ')
             VALUES (' . implode(', ', array_map(static fn(string $c): string => ':' . $c, $columns)) . ')',
        )->execute($row);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function markSent(int $id, string $merchantRequestId, string $checkoutRequestId): void
    {
        $this->db->pdo()->prepare(
            "UPDATE mpesa_stk_requests
                SET status = 'pending', merchant_request_id = :m, checkout_request_id = :c
              WHERE id = :id AND status = 'initiated'",
        )->execute(['m' => $merchantRequestId, 'c' => $checkoutRequestId, 'id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public function requestByUuid(int $tenantId, string $uuid, bool $lock = false): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM mpesa_stk_requests WHERE tenant_id = :t AND uuid = :u' . ($lock ? ' FOR UPDATE' : ''),
        );
        $stmt->execute(['t' => $tenantId, 'u' => $uuid]);

        return $stmt->fetch() ?: null;
    }

    /** @return array<string, mixed>|null */
    public function requestByCheckout(string $checkoutRequestId, bool $lock = false): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM mpesa_stk_requests WHERE checkout_request_id = :c' . ($lock ? ' FOR UPDATE' : ''),
        );
        $stmt->execute(['c' => $checkoutRequestId]);

        return $stmt->fetch() ?: null;
    }

    /** @return array<string, mixed>|null */
    public function requestByPaymentIdempotency(int $tenantId, string $key): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT r.* FROM mpesa_stk_requests r
               JOIN payments p ON p.tenant_id = r.tenant_id AND p.id = r.payment_id
              WHERE r.tenant_id = :t AND p.idempotency_key = :k',
        );
        $stmt->execute(['t' => $tenantId, 'k' => $key]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Terminal outcome for a prompt and its payment row.
     * @param array<string, mixed> $request
     */
    public function settle(
        array $request,
        string $status,
        ?string $resultCode,
        ?string $resultDesc,
        ?string $receipt,
        string $via,
    ): void {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'UPDATE mpesa_stk_requests
                SET status = :s, result_code = :rc, result_desc = :rd,
                    mpesa_receipt = COALESCE(:receipt, mpesa_receipt), confirmed_via = :via,
                    completed_at = UTC_TIMESTAMP()
              WHERE id = :id',
        )->execute([
            's' => $status, 'rc' => $resultCode, 'rd' => $resultDesc !== null ? mb_substr($resultDesc, 0, 255) : null,
            'receipt' => $receipt, 'via' => $via, 'id' => (int) $request['id'],
        ]);

        $paymentStatus = $status === 'succeeded' ? 'succeeded' : ($status === 'cancelled' ? 'cancelled' : 'failed');
        $pdo->prepare(
            'UPDATE payments
                SET status = :s,
                    provider_reference = COALESCE(:ref, provider_reference),
                    received_at = IF(:s2 = \'succeeded\', UTC_TIMESTAMP(), received_at)
              WHERE tenant_id = :t AND id = :id AND status = \'pending\'',
        )->execute([
            's' => $paymentStatus, 'ref' => $receipt, 's2' => $paymentStatus,
            't' => (int) $request['tenant_id'], 'id' => (int) $request['payment_id'],
        ]);
    }

    /**
     * A late callback can fill in the receipt for a prompt already confirmed by query.
     * @param array<string, mixed> $request
     */
    public function attachReceipt(array $request, string $receipt): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE mpesa_stk_requests SET mpesa_receipt = :r WHERE id = :id AND mpesa_receipt IS NULL')
            ->execute(['r' => $receipt, 'id' => (int) $request['id']]);
        $pdo->prepare('UPDATE payments SET provider_reference = :r WHERE tenant_id = :t AND id = :id AND provider_reference IS NULL')
            ->execute(['r' => $receipt, 't' => (int) $request['tenant_id'], 'id' => (int) $request['payment_id']]);
    }

    public function touchQueried(int $id): void
    {
        $this->db->pdo()->prepare('UPDATE mpesa_stk_requests SET last_queried_at = UTC_TIMESTAMP() WHERE id = :id')
            ->execute(['id' => $id]);
    }

    /**
     * Records a provider callback once (provider, event id unique).
     * Returns false when this exact callback was already stored (replay).
     * @param array<string, mixed> $payload
     */
    public function recordProviderEvent(?int $tenantId, string $eventId, bool $signatureValid, array $payload): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT IGNORE INTO payment_provider_events (tenant_id, provider, provider_event_id, signature_valid, payload)
             VALUES (:t, 'mpesa_daraja', :e, :v, :p)",
        );
        $stmt->execute([
            't' => $tenantId, 'e' => mb_substr($eventId, 0, 100), 'v' => $signatureValid ? 1 : 0,
            'p' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);

        return $stmt->rowCount() === 1;
    }

    public function markEventProcessed(string $eventId): void
    {
        $this->db->pdo()->prepare(
            "UPDATE payment_provider_events SET processed_at = UTC_TIMESTAMP()
              WHERE provider = 'mpesa_daraja' AND provider_event_id = :e",
        )->execute(['e' => mb_substr($eventId, 0, 100)]);
    }

    /**
     * Prompts still pending after $seconds (for the scheduler's query sweep).
     * @return list<array<string, mixed>>
     */
    public function stalePending(int $seconds, int $limit = 25): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM mpesa_stk_requests
              WHERE status = 'pending' AND created_at < UTC_TIMESTAMP() - INTERVAL :s SECOND
                AND (last_queried_at IS NULL OR last_queried_at < UTC_TIMESTAMP() - INTERVAL 60 SECOND)
              ORDER BY created_at LIMIT :n",
        );
        $stmt->bindValue('s', $seconds, \PDO::PARAM_INT);
        $stmt->bindValue('n', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return array_values($stmt->fetchAll());
    }
}
