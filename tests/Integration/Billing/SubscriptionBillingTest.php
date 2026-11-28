<?php

declare(strict_types=1);

namespace Tests\Integration\Billing;

use App\Core\Database\Seeders\PlanSeeder;
use App\Modules\Billing\Services\FeatureGate;
use App\Modules\Billing\Services\SubscriptionBillingService;
use App\Modules\Payments\Mpesa\DarajaClient;
use App\Shared\Http\AppUrl;
use App\Shared\Http\HttpClient;
use App\Shared\Mail\ArrayMailer;
use App\Shared\Time\SystemClock;
use Psr\Log\NullLogger;
use Tests\Integration\Support\Phase2TestCase;

/**
 * AlbaBook's own subscription billing (update.md 6.5): trial expiry to
 * read-only, renewal invoicing, plan changes and M-Pesa settlement.
 *
 * The service opens real transactions, so this case commits rows (same as
 * the concurrency tests); assertions always scope to the tenant it made.
 */
final class SubscriptionBillingTest extends Phase2TestCase
{
    protected bool $wrapInTransaction = false;

    private SubscriptionBillingService $billing;
    private FeatureGate $features;
    private ArrayMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        (new PlanSeeder($this->db, $this->uuids))->run();
        $this->mailer = new ArrayMailer();
        $this->billing = new SubscriptionBillingService(
            $this->db,
            $this->config,
            new SystemClock(),
            $this->uuids,
            $this->mailer,
            new DarajaClient(new HttpClient()),
            new AppUrl($this->config),
            new NullLogger(),
        );
        $this->features = new FeatureGate($this->db);
    }

    public function testExpiredTrialGoesReadOnlyWithoutAPlan(): void
    {
        $tenantId = $this->tenant();
        $subId = $this->subscription($tenantId, 'trial', 'trialing', 'UTC_TIMESTAMP() - INTERVAL 1 DAY');

        $this->billing->sweep();

        $sub = $this->row('SELECT * FROM tenant_subscriptions WHERE id = ?', [$subId]);
        self::assertSame('expired', $sub['status']);
        self::assertNotNull($sub['trial_reminder_sent_at'], 'the reminder fires on the same pass (trial already ending)');
        self::assertFalse($this->features->enabled($tenantId, 'custom_booking_domain'));
        self::assertFalse($this->features->underLimit($tenantId, 'monthly_bookings', 0));
        self::assertNotEmpty($this->mailer->messages());
    }

    public function testExpiredTrialWithChosenPlanIssuesActivationInvoice(): void
    {
        $tenantId = $this->tenant();
        $planId = $this->planId('starter');
        $subId = $this->subscription($tenantId, 'trial', 'trialing', 'UTC_TIMESTAMP() - INTERVAL 1 DAY');
        $this->pdo->prepare('UPDATE tenant_subscriptions SET scheduled_plan_id = ? WHERE id = ?')
            ->execute([$planId, $subId]);

        $this->billing->sweep();

        $sub = $this->row('SELECT * FROM tenant_subscriptions WHERE id = ?', [$subId]);
        self::assertSame('past_due', $sub['status'], 'a chosen plan keeps grace access while the invoice is open');

        $invoice = $this->row('SELECT * FROM subscription_invoices WHERE tenant_id = ?', [$tenantId]);
        self::assertSame('issued', $invoice['status']);
        self::assertSame('activation', $invoice['purpose']);
        self::assertSame(150000, (int) $invoice['total_minor']);
        self::assertMatchesRegularExpression('/^INV-\d{6}$/', $invoice['invoice_number']);
    }

    public function testPaidRenewalAdvancesPeriodAndAppliesScheduledPlan(): void
    {
        $tenantId = $this->tenant();
        $subId = $this->subscription($tenantId, 'starter', 'active', 'UTC_TIMESTAMP() - INTERVAL 30 DAY');
        $this->pdo->prepare('UPDATE tenant_subscriptions SET scheduled_plan_id = ? WHERE id = ?')
            ->execute([$this->planId('growth'), $subId]);

        $invoiceId = $this->invoice($tenantId, $subId, 'renewal', 350000);
        $this->payment($tenantId, $invoiceId, 'ws_CO_checkout_1');

        self::assertTrue($this->billing->settle('ws_CO_checkout_1', '0', 'Success', 'QWE123', 'callback'));

        $sub = $this->row('SELECT s.*, p.code AS plan_code FROM tenant_subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.id = ?', [$subId]);
        self::assertSame('active', $sub['status']);
        self::assertSame('growth', $sub['plan_code'], 'the scheduled upgrade applies on payment');
        self::assertNull($sub['scheduled_plan_id']);
        self::assertGreaterThan(
            (string) $sub['current_period_start'],
            (string) $sub['current_period_end'],
            'the period advanced one interval',
        );
        self::assertSame('paid', $this->row('SELECT status FROM subscription_invoices WHERE id = ?', [$invoiceId])['status']);
        self::assertSame('succeeded', $this->row('SELECT status FROM subscription_payments WHERE tenant_id = ?', [$tenantId])['status']);
    }

    public function testUpgradeMidPeriodIssuesProratedInvoice(): void
    {
        $tenantId = $this->tenant();
        $subId = $this->subscription($tenantId, 'starter', 'active', 'UTC_TIMESTAMP() - INTERVAL 15 DAY', 'UTC_TIMESTAMP() + INTERVAL 30 DAY');

        $result = $this->billing->choosePlan($tenantId, 'pro', null);

        self::assertSame('upgrade_invoiced', $result['action']);
        $invoice = $this->row('SELECT * FROM subscription_invoices WHERE tenant_id = ?', [$tenantId]);
        self::assertSame('upgrade', $invoice['purpose']);
        // Half a 60-day period remains: (750000-150000) * 30/60 = 300000.
        $expected = (int) round((750000 - 150000) * 0.5);
        self::assertEqualsWithDelta($expected, (int) $invoice['total_minor'], 30000, 'proration should be ~half the difference');

        $sub = $this->row('SELECT scheduled_plan_id FROM tenant_subscriptions WHERE id = ?', [$subId]);
        self::assertSame($this->planId('pro'), (int) $sub['scheduled_plan_id']);
    }

    public function testDowngradeSchedulesWithoutAnInvoice(): void
    {
        $tenantId = $this->tenant();
        $this->subscription($tenantId, 'growth', 'active', 'UTC_TIMESTAMP() - INTERVAL 10 DAY');

        $result = $this->billing->choosePlan($tenantId, 'starter', null);

        self::assertSame('scheduled', $result['action']);
        self::assertNull($result['invoice']);
        self::assertSame(0, (int) $this->row(
            'SELECT COUNT(*) c FROM subscription_invoices WHERE tenant_id = ?',
            [$tenantId],
        )['c']);
    }

    public function testOverdueInvoiceExpiresTheSubscription(): void
    {
        $tenantId = $this->tenant();
        $subId = $this->subscription($tenantId, 'starter', 'past_due', 'UTC_TIMESTAMP() - INTERVAL 40 DAY');
        $invoiceId = $this->invoice($tenantId, $subId, 'renewal', 150000, 'UTC_TIMESTAMP() - INTERVAL 1 DAY');

        $this->billing->sweep();

        self::assertSame('expired', $this->row('SELECT status FROM tenant_subscriptions WHERE id = ?', [$subId])['status']);
        self::assertSame('overdue', $this->row('SELECT status FROM subscription_invoices WHERE id = ?', [$invoiceId])['status']);
        self::assertFalse($this->features->underLimit($tenantId, 'monthly_bookings', 0));
    }

    public function testSettleRejectsUnknownCheckoutAndReplays(): void
    {
        self::assertFalse($this->billing->settle('ws_CO_unknown', '0', 'x', null, 'callback'));

        $tenantId = $this->tenant();
        $subId = $this->subscription($tenantId, 'starter', 'past_due', 'UTC_TIMESTAMP() - INTERVAL 40 DAY');
        $invoiceId = $this->invoice($tenantId, $subId, 'renewal', 150000, 'UTC_TIMESTAMP() - INTERVAL 1 DAY');
        $this->payment($tenantId, $invoiceId, 'ws_CO_checkout_2');

        self::assertTrue($this->billing->settle('ws_CO_checkout_2', '0', 'Success', 'ABC', 'callback'));
        self::assertFalse(
            $this->billing->settle('ws_CO_checkout_2', '0', 'Success', 'ABC', 'callback'),
            'a replayed callback must not settle twice',
        );
    }

    public function testCallbackSignatureGuardsTheEndpoint(): void
    {
        $tenantId = $this->tenant();
        $subId = $this->subscription($tenantId, 'starter', 'past_due', 'UTC_TIMESTAMP() - INTERVAL 40 DAY');
        $invoiceId = $this->invoice($tenantId, $subId, 'renewal', 150000, 'UTC_TIMESTAMP() + INTERVAL 5 DAY');
        $invoiceUuid = (string) $this->row('SELECT uuid FROM subscription_invoices WHERE id = ?', [$invoiceId])['uuid'];
        $this->payment($tenantId, $invoiceId, 'ws_CO_callback_1');

        $body = ['Body' => ['stkCallback' => [
            'CheckoutRequestID' => 'ws_CO_callback_1',
            'ResultCode' => 0,
            'ResultDesc' => 'Success',
            'CallbackMetadata' => ['Item' => [['Name' => 'MpesaReceiptNumber', 'Value' => 'QZX987']]],
        ]]];

        self::assertFalse($this->billing->handleCallback($invoiceUuid, 'bad-signature', $body, '127.0.0.1'));
        self::assertSame('pending', $this->row(
            'SELECT status FROM subscription_payments WHERE tenant_id = ?',
            [$tenantId],
        )['status']);

        $signature = substr(hash_hmac('sha256', 'albabook:billing-callback:v1|' . $invoiceUuid, $this->config->appKey()), 0, 40);
        self::assertTrue($this->billing->handleCallback($invoiceUuid, $signature, $body, '127.0.0.1'));
        self::assertSame('succeeded', $this->row(
            'SELECT status FROM subscription_payments WHERE tenant_id = ?',
            [$tenantId],
        )['status']);
        self::assertSame('QZX987', $this->row(
            'SELECT provider_reference FROM subscription_payments WHERE tenant_id = ?',
            [$tenantId],
        )['provider_reference']);
        self::assertSame('active', $this->row(
            'SELECT status FROM tenant_subscriptions WHERE id = ?',
            [$subId],
        )['status']);
    }

    /* ------------------------------------------------------------------ */

    private function tenant(): int
    {
        $userId = $this->createUser($this->uniqueEmail());

        return (int) $this->provision($userId)['tenant_id'];
    }

    /** Insert a subscription; trialSql is the trial_ends_at expression (trialing only). */
    private function subscription(
        int $tenantId,
        string $planCode,
        string $status,
        string $trialSql,
        string $periodEndSql = 'UTC_TIMESTAMP() - INTERVAL 1 MINUTE',
    ): int {
        $trial = $status === 'trialing';
        $this->pdo->prepare(
            "INSERT INTO tenant_subscriptions
                (uuid, tenant_id, plan_id, status, starts_at, current_period_start, current_period_end, trial_ends_at)
             VALUES (:uuid, :t, :p, :s, UTC_TIMESTAMP() - INTERVAL 31 DAY,
                     UTC_TIMESTAMP() - INTERVAL 30 DAY,
                     {$periodEndSql},
                     " . ($trial ? $trialSql : 'NULL') . ')',
        )->execute([
            'uuid' => $this->uuids->generate(),
            't' => $tenantId,
            'p' => $this->planId($planCode),
            's' => $status,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function invoice(int $tenantId, int $subId, string $purpose, int $totalMinor, string $dueSql = 'UTC_TIMESTAMP() + INTERVAL 5 DAY'): int
    {
        $this->pdo->prepare(
            "INSERT INTO subscription_invoices
                (uuid, tenant_id, subscription_id, invoice_number, status, purpose, issued_at, due_at, subtotal_minor, tax_minor, total_minor, currency)
             VALUES (:u, :t, :s, :n, 'issued', :p, UTC_TIMESTAMP(), {$dueSql}, :a, 0, :a2, 'KES')",
        )->execute([
            'u' => $this->uuids->generate(), 't' => $tenantId, 's' => $subId,
            'n' => 'INV-' . bin2hex(random_bytes(4)), 'p' => $purpose, 'a' => $totalMinor, 'a2' => $totalMinor,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function payment(int $tenantId, int $invoiceId, string $checkoutId): int
    {
        $this->pdo->prepare(
            "INSERT INTO subscription_payments
                (uuid, tenant_id, invoice_id, amount_minor, currency, method, status, checkout_request_id)
             VALUES (:u, :t, :i, 150000, 'KES', 'mpesa', 'pending', :c)",
        )->execute([
            'u' => $this->uuids->generate(), 't' => $tenantId, 'i' => $invoiceId, 'c' => $checkoutId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function planId(string $code): int
    {
        return (int) $this->pdo->query(
            'SELECT id FROM plans WHERE code = ' . $this->pdo->quote($code),
        )->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function row(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        self::assertIsArray($row, 'expected a row for: ' . $sql);

        return $row;
    }
}
