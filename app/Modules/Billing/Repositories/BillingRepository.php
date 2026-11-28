<?php

declare(strict_types=1);

namespace App\Modules\Billing\Repositories;

use App\Core\Database\Database;

/** Tenant-scoped subscription + invoice reads for the billing page. */
final class BillingRepository
{
    public function __construct(private readonly Database $db) {}

    /** Active/latest subscription with plan details.
     * @return array<string, mixed>|null
     */
    public function subscription(int $tenantId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.uuid, s.status, s.starts_at, s.current_period_start,
                    s.current_period_end, s.trial_ends_at, s.cancelled_at,
                    p.code AS plan_code, p.name AS plan_name, p.description AS plan_description,
                    p.price_minor, p.currency, p.billing_interval,
                    sp.name AS scheduled_plan_name
             FROM tenant_subscriptions s
             JOIN plans p ON p.id = s.plan_id
             LEFT JOIN plans sp ON sp.id = s.scheduled_plan_id
             WHERE s.tenant_id = ?
             ORDER BY s.created_at DESC LIMIT 1',
        );
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    public function invoices(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT uuid, invoice_number, status, issued_at, due_at,
                    subtotal_minor, tax_minor, total_minor, currency
             FROM subscription_invoices
             WHERE tenant_id = ? ORDER BY created_at DESC LIMIT 24',
        );
        $stmt->execute([$tenantId]);

        /** @var list<array<string, mixed>> */
        return array_values($stmt->fetchAll());
    }

    /** @return list<array<string, mixed>> */
    public function payments(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT sp.uuid, sp.amount_minor, sp.currency, sp.status,
                    sp.paid_at, sp.provider_reference, i.invoice_number
             FROM subscription_payments sp
             JOIN subscription_invoices i
               ON i.tenant_id = sp.tenant_id AND i.id = sp.invoice_id
             WHERE sp.tenant_id = ? ORDER BY sp.created_at DESC LIMIT 12',
        );
        $stmt->execute([$tenantId]);

        /** @var list<array<string, mixed>> */
        return array_values($stmt->fetchAll());
    }

    /** Plan features for the current plan.
     * @return list<array<string, mixed>>
     */
    public function planFeatures(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pf.feature_key, pf.is_enabled, pf.limit_value
             FROM plan_features pf
             JOIN tenant_subscriptions s ON s.tenant_id = ? AND s.plan_id = pf.plan_id
             WHERE pf.is_enabled = 1
             ORDER BY pf.feature_key',
        );
        $stmt->execute([$tenantId]);

        /** @var list<array<string, mixed>> */
        return array_values($stmt->fetchAll());
    }
}
