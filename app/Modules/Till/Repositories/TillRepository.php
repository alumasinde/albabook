<?php

declare(strict_types=1);

namespace App\Modules\Till\Repositories;

use App\Core\Database\Database;

/**
 * Tenant-scoped till/ticket queries. The appointment is the ticket:
 * appointment_items = booked services, appointment_sale_items = lines rung
 * up at the till, payments = tenders. Totals are always recomputed in SQL.
 */
final class TillRepository
{
    public function __construct(private readonly Database $db) {}

    /** Appointment core for the ticket, optionally locked FOR UPDATE.
     * @return array<string, mixed>|null
     */
    public function appointment(int $tenantId, string $uuid, bool $lock = false): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, uuid, reference, branch_id, customer_id, staff_id, status,
                    payment_status, total_amount_minor, deposit_amount_minor,
                    currency, customer_name_snapshot, customer_phone_snapshot,
                    customer_email_snapshot, start_at, end_at, version
             FROM appointments WHERE tenant_id = ? AND uuid = ?'
            . ($lock ? ' FOR UPDATE' : ''),
        );
        $stmt->execute([$tenantId, $uuid]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** Booked service lines (read-only on the ticket).
     * @return list<array<string, mixed>>
     */
    public function bookedItems(int $tenantId, int $appointmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, service_name_snapshot AS label, quantity,
                    unit_amount_minor, tax_rate_bp, tax_amount_minor
             FROM appointment_items
             WHERE tenant_id = ? AND appointment_id = ?
             ORDER BY item_position",
        );
        $stmt->execute([$tenantId, $appointmentId]);

        /** @var list<array<string, mixed>> */
        return array_values($stmt->fetchAll());
    }

    /** Till-added lines.
     * @return list<array<string, mixed>>
     */
    public function saleItems(int $tenantId, int $appointmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, kind, service_id, product_id, label, quantity,
                    unit_amount_minor, rate_bp, tax_rate_bp, tax_amount_minor, total_minor
             FROM appointment_sale_items
             WHERE tenant_id = ? AND appointment_id = ?
             ORDER BY line_no, id',
        );
        $stmt->execute([$tenantId, $appointmentId]);

        /** @var list<array<string, mixed>> */
        return array_values($stmt->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function findSaleItem(int $tenantId, int $appointmentId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, kind, service_id, product_id, unit_amount_minor, tax_amount_minor
             FROM appointment_sale_items
             WHERE tenant_id = ? AND appointment_id = ? AND id = ?',
        );
        $stmt->execute([$tenantId, $appointmentId, $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function nextLineNo(int $tenantId, int $appointmentId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(MAX(line_no), 0) + 1 FROM appointment_sale_items
             WHERE tenant_id = ? AND appointment_id = ?',
        );
        $stmt->execute([$tenantId, $appointmentId]);

        return (int) $stmt->fetchColumn();
    }

    /** @param array<string, mixed> $data */
    public function insertSaleItem(int $tenantId, int $appointmentId, array $data): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO appointment_sale_items
                (tenant_id, appointment_id, line_no, kind, service_id, product_id,
                 label, quantity, unit_amount_minor, rate_bp, tax_rate_bp,
                 tax_amount_minor, total_minor, added_by_user_id)
             VALUES
                (:tid, :aid, :line_no, :kind, :service_id, :product_id,
                 :label, :quantity, :unit_amount_minor, :rate_bp, :tax_rate_bp,
                 :tax_amount_minor, :total_minor, :added_by_user_id)',
        )->execute($data + ['tid' => $tenantId, 'aid' => $appointmentId]);
    }

    /** @param array{quantity: int, total_minor: int} $data */
    public function updateSaleItem(int $tenantId, int $id, array $data): void
    {
        $this->db->pdo()->prepare(
            'UPDATE appointment_sale_items SET quantity = ?, total_minor = ?
             WHERE tenant_id = ? AND id = ?',
        )->execute([$data['quantity'], $data['total_minor'], $tenantId, $id]);
    }

    public function deleteSaleItem(int $tenantId, int $id): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM appointment_sale_items WHERE tenant_id = ? AND id = ?',
        )->execute([$tenantId, $id]);
    }

    /** Service lookup for till lines (active, fixed-price or priced type).
     * @return array<string, mixed>|null
     */
    public function service(int $tenantId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, name, price_type, price_amount_minor, min_amount_minor,
                    max_amount_minor
             FROM services WHERE tenant_id = ? AND id = ? AND is_active = 1',
        );
        $stmt->execute([$tenantId, $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function product(int $tenantId, int $id, bool $lock = false): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, name, sku, price_minor, tax_rate_bp, stock_quantity
             FROM products WHERE tenant_id = ? AND id = ? AND is_active = 1'
            . ($lock ? ' FOR UPDATE' : ''),
        );
        $stmt->execute([$tenantId, $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** Decrement tracked stock by quantity (clamped at zero handled by caller). */
    public function decrementStock(int $tenantId, int $productId, int $quantity): void
    {
        $this->db->pdo()->prepare(
            'UPDATE products SET stock_quantity = stock_quantity - ?
             WHERE tenant_id = ? AND id = ? AND stock_quantity IS NOT NULL',
        )->execute([$quantity, $tenantId, $productId]);
    }

    /** Tenders on the ticket.
     * @return list<array<string, mixed>>
     */
    public function payments(int $tenantId, int $appointmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT uuid, kind, method, provider, provider_reference, amount_minor,
                    currency, status, received_at
             FROM payments
             WHERE tenant_id = ? AND appointment_id = ?
             ORDER BY created_at',
        );
        $stmt->execute([$tenantId, $appointmentId]);

        /** @var list<array<string, mixed>> */
        return array_values($stmt->fetchAll());
    }

    /** @return list<array{uuid: string, amount_minor: int, status: string, reason: ?string, refunded_at: ?string}> */
    public function refunds(int $tenantId, int $appointmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT r.uuid, r.amount_minor, r.status, r.reason, r.refunded_at
             FROM refunds r
             JOIN payments p ON p.tenant_id = r.tenant_id AND p.id = r.payment_id
             WHERE r.tenant_id = ? AND p.appointment_id = ?
             ORDER BY r.created_at',
        );
        $stmt->execute([$tenantId, $appointmentId]);

        /** @var list<array{uuid: string, amount_minor: int, status: string, reason: ?string, refunded_at: ?string}> */
        return array_values($stmt->fetchAll());
    }

    /** Succeeded tender total minus succeeded refunds (signed). */
    public function paidTotal(int $tenantId, int $appointmentId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(amount_minor), 0) FROM payments
             WHERE tenant_id = ? AND appointment_id = ? AND status = 'succeeded'",
        );
        $stmt->execute([$tenantId, $appointmentId]);
        $paid = (int) $stmt->fetchColumn();

        $ref = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(r.amount_minor), 0)
             FROM refunds r JOIN payments p ON p.tenant_id = r.tenant_id AND p.id = r.payment_id
             WHERE r.tenant_id = ? AND p.appointment_id = ? AND r.status = 'succeeded'",
        );
        $ref->execute([$tenantId, $appointmentId]);

        return $paid - (int) $ref->fetchColumn();
    }

    /** @param array<string, mixed> $data */
    public function insertPayment(int $tenantId, array $data): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payments
                (uuid, tenant_id, branch_id, appointment_id, customer_id, kind,
                 method, provider, provider_reference, amount_minor, currency,
                 status, received_at, recorded_by_user_id, idempotency_key)
             VALUES
                (:uuid, :tid, :branch_id, :appointment_id, :customer_id, :kind,
                 :method, :provider, :provider_reference, :amount_minor, :currency,
                 :status, :received_at, :recorded_by_user_id, :idempotency_key)',
        )->execute($data + ['tid' => $tenantId]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** A payment already recorded under this client idempotency key.
     * @return array<string, mixed>|null
     */
    public function paymentByIdempotencyKey(int $tenantId, int $appointmentId, string $key): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payments WHERE tenant_id = ? AND appointment_id = ? AND idempotency_key = ? LIMIT 1',
        );
        $stmt->execute([$tenantId, $appointmentId, $key]);

        return $stmt->fetch() ?: null;
    }

    /** @param array<string, mixed> $data */
    public function insertRefund(int $tenantId, array $data): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO refunds
                (uuid, tenant_id, payment_id, amount_minor, currency, status,
                 reason, refunded_at, recorded_by_user_id)
             VALUES
                (:uuid, :tid, :payment_id, :amount_minor, :currency, :status,
                 :reason, :refunded_at, :recorded_by_user_id)',
        )->execute($data + ['tid' => $tenantId]);
    }

    /** @return array<string, mixed>|null */
    public function findPaymentByUuid(int $tenantId, string $uuid): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, appointment_id, amount_minor, currency, status
             FROM payments WHERE tenant_id = ? AND uuid = ?',
        );
        $stmt->execute([$tenantId, $uuid]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function refundedAgainst(int $tenantId, int $paymentId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(amount_minor), 0) FROM refunds
             WHERE tenant_id = ? AND payment_id = ? AND status = 'succeeded'",
        );
        $stmt->execute([$tenantId, $paymentId]);

        return (int) $stmt->fetchColumn();
    }

    /** Derived ticket totals + payment status write-back. */
    public function writeTotals(int $tenantId, int $appointmentId, int $totalMinor, string $paymentStatus): void
    {
        $this->db->pdo()->prepare(
            'UPDATE appointments SET total_amount_minor = ?, payment_status = ?,
                    version = version + 1
             WHERE tenant_id = ? AND id = ?',
        )->execute([$totalMinor, $paymentStatus, $tenantId, $appointmentId]);
    }

    /** Active tender methods for customer payments.
     * @return list<array{code: string, name: string, requires_reference: int, is_cash: int}>
     */
    public function paymentMethods(): array
    {
        $stmt = $this->db->pdo()->query(
            'SELECT code, name, requires_reference, is_cash
             FROM payment_methods
             WHERE is_active = 1 AND for_customer_payments = 1
             ORDER BY sort_position',
        );

        /** @var list<array{code: string, name: string, requires_reference: int, is_cash: int}> */
        return $stmt === false ? [] : array_values($stmt->fetchAll());
    }

    /** Workspace VAT rate — services don't carry their own rate, so till
     * service lines inherit the tenant's configured rate.
     */
    public function tenantTaxRateBp(int $tenantId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT tax_rate_bp FROM tenants WHERE id = ?',
        );
        $stmt->execute([$tenantId]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    /** Optional service charge applied to the net (post-discount, pre-VAT)
     * subtotal at the till. Stored in tenant_settings.preferences JSON.
     */
    public function serviceChargeBp(int $tenantId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT JSON_UNQUOTE(JSON_EXTRACT(COALESCE(preferences, JSON_OBJECT()), '$.service_charge_bp'))
             FROM tenant_settings WHERE tenant_id = ?",
        );
        $stmt->execute([$tenantId]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? 0 : max(0, (int) $value);
    }

    /** Receipt footer note (tenant_settings.preferences.receipt_footer_note). */
    public function receiptFooterNote(int $tenantId): string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT JSON_UNQUOTE(JSON_EXTRACT(COALESCE(preferences, JSON_OBJECT()), '$.receipt_footer_note'))
             FROM tenant_settings WHERE tenant_id = ?",
        );
        $stmt->execute([$tenantId]);
        $value = $stmt->fetchColumn();

        return is_string($value) ? $value : '';
    }

    /** Checkout policy flag. */
    public function fullPaymentRequired(int $tenantId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT checkout_full_payment_required FROM tenant_settings WHERE tenant_id = ?',
        );
        $stmt->execute([$tenantId]);
        $value = $stmt->fetchColumn();

        return $value === false ? true : (bool) (int) $value;
    }

    /** Bookable services + active products for the add-item pickers.
     * @return array{services: list<array<string, mixed>>, products: list<array<string, mixed>>}
     */
    public function pickerOptions(int $tenantId): array
    {
        $svc = $this->db->pdo()->prepare(
            'SELECT id, name, price_type, price_amount_minor, min_amount_minor
             FROM services WHERE tenant_id = ? AND is_active = 1 ORDER BY name',
        );
        $svc->execute([$tenantId]);

        $prd = $this->db->pdo()->prepare(
            'SELECT id, name, sku, price_minor, tax_rate_bp, stock_quantity
             FROM products WHERE tenant_id = ? AND is_active = 1 ORDER BY name',
        );
        $prd->execute([$tenantId]);

        return [
            'services' => array_values($svc->fetchAll()),
            'products' => array_values($prd->fetchAll()),
        ];
    }

    /**
     * Extract the VAT and service-charge portions embedded in a gross
     * (tax-inclusive) amount. Prices are quoted inclusive — the levies sit
     * inside the price, computed against the net base like an eTIMS
     * extraction: gross = net * (1 + vat + sc).
     * @return array{net_minor: int, vat_minor: int, sc_minor: int}
     */
    public function extractLevies(int $grossMinor, int $vatBp, int $scBp): array
    {
        if ($grossMinor <= 0) {
            return ['net_minor' => 0, 'vat_minor' => 0, 'sc_minor' => 0];
        }
        $net = intdiv($grossMinor * 10000, 10000 + $vatBp + $scBp);
        $vat = intdiv($net * $vatBp, 10000);
        $sc = intdiv($net * $scBp, 10000);

        return ['net_minor' => $grossMinor - $vat - $sc, 'vat_minor' => $vat, 'sc_minor' => $sc];
    }

    /** Full ticket breakdown: inclusive subtotal, discount, payable,
     * extracted VAT/service charge, tips, grand total and tender state.
     * The single source of truth for ticket math — shared by the till API
     * and the receipt page.
     * @return array{subtotal_minor: int, discount_minor: int, service_charge_minor: int,
     *               tax_minor: int, net_minor: int, tip_minor: int, total_minor: int,
     *               paid_minor: int, refunded_minor: int, balance_minor: int,
     *               tax_breakdown: list<array{rate_bp: int, taxable_minor: int, vat_minor: int}>}
     */
    public function computeTotals(int $tenantId, int $appointmentId): array
    {
        $subtotal = 0;
        $discount = 0;
        $tips = 0;
        /** @var array<int, int> gross inclusive amount per VAT rate group */
        $grossByRate = [];
        $addGross = function (int $rateBp, int $amount) use (&$grossByRate, &$subtotal): void {
            $subtotal += $amount;
            $grossByRate[$rateBp] = ($grossByRate[$rateBp] ?? 0) + $amount;
        };

        foreach ($this->bookedItems($tenantId, $appointmentId) as $item) {
            $addGross((int) $item['tax_rate_bp'], (int) $item['unit_amount_minor'] * (int) $item['quantity']);
        }
        foreach ($this->saleItems($tenantId, $appointmentId) as $item) {
            $qty = (int) $item['quantity'];
            switch ($item['kind']) {
                case 'service':
                case 'product':
                    $addGross((int) $item['tax_rate_bp'], (int) $item['unit_amount_minor'] * $qty);
                    break;
                case 'discount':
                    $discount += abs((int) $item['total_minor']);
                    break;
                case 'tip':
                    $tips += (int) $item['total_minor'];
                    break;
            }
        }

        // Allocate the ticket discount pro-rata across VAT rate groups with
        // cumulative rounding so the group shares sum exactly, then extract
        // each group's net/VAT/service-charge portions from its post-
        // discount gross.
        $scBp = $this->serviceChargeBp($tenantId);
        $tax = 0;
        $charge = 0;
        $netTotal = 0;
        $breakdown = [];
        $allocated = 0;
        $cumGross = 0;
        foreach ($grossByRate as $rateBp => $gross) {
            $cumGross += $gross;
            $groupDiscount = $subtotal > 0 ? min($gross, intdiv($discount * $cumGross, $subtotal) - $allocated) : 0;
            $allocated += $groupDiscount;
            $levies = $this->extractLevies($gross - $groupDiscount, (int) $rateBp, $scBp);
            $tax += $levies['vat_minor'];
            $charge += $levies['sc_minor'];
            $netTotal += $levies['net_minor'];
            $breakdown[] = [
                'rate_bp' => (int) $rateBp,
                'taxable_minor' => $levies['net_minor'],
                'vat_minor' => $levies['vat_minor'],
            ];
        }

        $total = max(0, $subtotal - $discount + $tips);
        $paid = $this->paidTotal($tenantId, $appointmentId);
        $refunded = array_sum(array_map(
            static fn(array $r): int => $r['status'] === 'succeeded' ? (int) $r['amount_minor'] : 0,
            $this->refunds($tenantId, $appointmentId),
        ));

        return [
            'subtotal_minor' => $subtotal,
            'discount_minor' => $discount,
            'service_charge_minor' => $charge,
            'tax_minor' => $tax,
            'net_minor' => $netTotal,
            'tip_minor' => $tips,
            'total_minor' => $total,
            'paid_minor' => $paid,
            'refunded_minor' => $refunded,
            'balance_minor' => $total - $paid,
            'tax_breakdown' => $breakdown,
        ];
    }

    /** Header data for the printed receipt.
     * @return array<string, mixed>|null
     */
    public function receiptContext(int $tenantId, int $appointmentId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.reference, a.start_at, a.end_at, a.currency, a.status,
                    a.customer_name_snapshot, a.customer_phone_snapshot,
                    a.customer_email_snapshot,
                    b.name AS branch_name, b.address_line1, b.address_line2,
                    b.town, b.county, b.phone AS branch_phone, b.email AS branch_email,
                    s.display_name AS staff_name,
                    t.name AS tenant_name, t.kra_pin, t.tax_rate_bp, t.slug AS tenant_slug
             FROM appointments a
             JOIN branches b ON b.tenant_id = a.tenant_id AND b.id = a.branch_id
             LEFT JOIN staff s ON s.tenant_id = a.tenant_id AND s.id = a.staff_id
             JOIN tenants t ON t.id = a.tenant_id
             WHERE a.tenant_id = ? AND a.id = ?',
        );
        $stmt->execute([$tenantId, $appointmentId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** Enqueue a receipt notification for the customer. */
    public function enqueueReceipt(int $tenantId, string $uuid, int $appointmentId, string $channel, string $recipient, string $payloadJson): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO notification_queue
                (uuid, tenant_id, appointment_id, channel, recipient, payload, status)
             VALUES (?, ?, ?, ?, ?, ?, 'pending')",
        )->execute([$uuid, $tenantId, $appointmentId, $channel, $recipient, $payloadJson]);
    }

    /** Outbox event for webhook fan-out (payment.received etc.). */
    public function outbox(int $tenantId, string $uuid, string $eventType, int $appointmentId, string $payloadJson): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO outbox_events
                (uuid, tenant_id, event_type, aggregate_type, aggregate_id, payload)
             VALUES (?, ?, ?, 'appointment', ?, ?)",
        )->execute([$uuid, $tenantId, $eventType, $appointmentId, $payloadJson]);
    }
}
