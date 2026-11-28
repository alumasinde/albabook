<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Core\Config\Config;
use App\Core\Database\Database;
use App\Modules\Appointments\Services\TransitionException;
use App\Modules\Payments\Mpesa\DarajaClient;
use App\Modules\Payments\Mpesa\DarajaException;
use App\Modules\Payments\Mpesa\MpesaCredentials;
use App\Shared\Http\AppUrl;
use App\Shared\Mail\Mailer;
use App\Shared\Mail\MailMessage;
use App\Shared\Support\UuidFactory;
use App\Shared\Time\Clock;
use App\Shared\Validation\PhoneNumber;
use Psr\Log\LoggerInterface;

/**
 * AlbaBook's own revenue: subscription invoices, M-Pesa collection and the
 * trial/renewal lifecycle.
 *
 * Lifecycle (all timestamps UTC):
 *   trialing ── trial_ends_at passes ──► expired (read-only: FeatureGate
 *        denies every plan feature) unless a plan was chosen, in which case
 *        the first 'activation' invoice goes out and the subscription moves
 *        to past_due for the grace window.
 *   active ── current_period_end − lead_days ──► 'renewal' invoice issued,
 *        due_at = period end + grace_days. At period end unpaid the
 *        subscription becomes past_due (plan access continues per
 *        FeatureGate); at due_at unpaid the invoice is overdue and the
 *        subscription expires.
 *   invoice paid ──► subscription 'active'; renewal invoices advance the
 *        period by one billing interval, activation invoices start a fresh
 *        period at payment time, upgrade invoices swap the plan and keep
 *        the current period.
 *
 * Plan changes (choosePlan): upgrades issue a prorated 'upgrade' invoice
 * for the rest of the period and apply on payment; downgrades and plan
 * choices during trial only set scheduled_plan_id and apply at the next
 * paid invoice.
 */
final class SubscriptionBillingService
{
    /** trial_ends_at reminders go out this many days ahead. */
    private const REMIND_DAYS = 3;

    /** Invoice number shown to the business and used as the M-Pesa account reference (≤ 12 chars). */
    private const INVOICE_PREFIX = 'INV-';

    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly UuidFactory $uuids,
        private readonly Mailer $mailer,
        private readonly DarajaClient $daraja,
        private readonly AppUrl $urls,
        private readonly LoggerInterface $logger,
    ) {}

    /* ------------------------------------------------------------------ */
    /* Scheduler sweep                                                     */
    /* ------------------------------------------------------------------ */

    /** One bounded pass over the subscription lifecycle. @return int rows touched */
    public function sweep(int $limit = 100): int
    {
        $done = $this->remindExpiringTrials($limit)
            + $this->expireTrials($limit)
            + $this->issueRenewals($limit)
            + $this->demotePastDue($limit)
            + $this->expireOverdue($limit);

        return $done;
    }

    /** Email owners a few days before the trial lapses (once per subscription). */
    private function remindExpiringTrials(int $limit): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id, s.tenant_id, s.trial_ends_at, t.name AS business_name
               FROM tenant_subscriptions s JOIN tenants t ON t.id = s.tenant_id
              WHERE s.status = 'trialing' AND s.trial_ends_at IS NOT NULL
                AND s.trial_reminder_sent_at IS NULL
                AND s.trial_ends_at <= UTC_TIMESTAMP() + INTERVAL :d DAY
              LIMIT " . max(1, $limit),
        );
        $stmt->execute(['d' => self::REMIND_DAYS]);
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            $this->db->transaction(function () use ($row): void {
                $marked = $this->db->pdo()->prepare(
                    'UPDATE tenant_subscriptions SET trial_reminder_sent_at = UTC_TIMESTAMP()
                      WHERE id = :id AND trial_reminder_sent_at IS NULL',
                );
                $marked->execute(['id' => (int) $row['id']]);
                if ($marked->rowCount() !== 1) {
                    return;
                }
                $this->mailOwners(
                    (int) $row['tenant_id'],
                    'Your AlbaBook trial ends ' . $this->day($row['trial_ends_at']),
                    [
                        (string) $row['business_name'] . '’s free trial ends on '
                            . $this->day($row['trial_ends_at']) . '.',
                        '',
                        'Choose a plan on the Billing page to keep taking bookings:',
                        $this->urls->app('/billing'),
                    ],
                );
            });
        }

        return count($rows);
    }

    /**
     * Trial lapses: with a plan already chosen, issue the activation invoice
     * and move to past_due (grace); otherwise expire — FeatureGate then
     * denies every plan feature, which is the read-only state.
     */
    private function expireTrials(int $limit): int
    {
        $stmt = $this->db->pdo()->query(
            "SELECT s.id, s.tenant_id, s.uuid, s.scheduled_plan_id, t.name AS business_name
               FROM tenant_subscriptions s JOIN tenants t ON t.id = s.tenant_id
              WHERE s.status = 'trialing' AND s.trial_ends_at IS NOT NULL
                AND s.trial_ends_at <= UTC_TIMESTAMP()
              ORDER BY s.trial_ends_at LIMIT " . max(1, $limit),
        );
        $rows = $stmt === false ? [] : $stmt->fetchAll();

        $done = 0;
        foreach ($rows as $row) {
            $this->db->transaction(function () use ($row, &$done): void {
                $tenantId = (int) $row['tenant_id'];
                $subId = (int) $row['id'];
                $planId = $row['scheduled_plan_id'] === null ? null : (int) $row['scheduled_plan_id'];

                if ($planId !== null && ($plan = $this->plan($planId)) !== null) {
                    $this->setStatus($subId, 'trialing', 'past_due');
                    $this->issueInvoice($row, $plan, 'activation', (int) $plan['price_minor']);
                    $this->mailOwners($tenantId, 'Your AlbaBook trial has ended', [
                        (string) $row['business_name'] . '’s trial has ended.',
                        'An invoice for the ' . (string) $plan['name'] . ' plan is ready on the Billing page —',
                        'pay it within the grace period to keep taking bookings:',
                        $this->urls->app('/billing'),
                    ]);
                } else {
                    $this->setStatus($subId, 'trialing', 'expired');
                    $this->mailOwners($tenantId, 'Your AlbaBook trial has ended', [
                        (string) $row['business_name'] . '’s trial has ended and the workspace is now read-only.',
                        'Pick a plan on the Billing page to restore full access:',
                        $this->urls->app('/billing'),
                    ]);
                }
                $this->event($tenantId, $subId, $planId !== null ? 'trial_ended_to_billing' : 'trial_expired', null, null);
                $done++;
            });
        }

        return $done;
    }

    /** Issue the next period's invoice shortly before the current period ends. */
    private function issueRenewals(int $limit): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id, s.tenant_id, s.uuid, s.plan_id, s.scheduled_plan_id,
                    s.current_period_end, t.name AS business_name
               FROM tenant_subscriptions s JOIN tenants t ON t.id = s.tenant_id
              WHERE s.status = 'active'
                AND s.current_period_end <= UTC_TIMESTAMP() + INTERVAL :d DAY
                AND NOT EXISTS (SELECT 1 FROM subscription_invoices i
                                 WHERE i.tenant_id = s.tenant_id AND i.subscription_id = s.id
                                   AND i.status IN ('draft', 'issued', 'overdue'))
              ORDER BY s.current_period_end LIMIT " . max(1, $limit),
        );
        $stmt->execute(['d' => $this->leadDays()]);
        $rows = $stmt->fetchAll();

        $done = 0;
        foreach ($rows as $row) {
            $planId = $row['scheduled_plan_id'] === null ? (int) $row['plan_id'] : (int) $row['scheduled_plan_id'];
            $plan = $this->plan($planId);
            if ($plan === null) {
                continue;
            }
            $this->db->transaction(function () use ($row, $plan, &$done): void {
                $this->issueInvoice($row, $plan, 'renewal', (int) $plan['price_minor']);
                $this->mailOwners(
                    (int) $row['tenant_id'],
                    'Your AlbaBook invoice is ready',
                    [
                        'The ' . (string) $plan['name'] . ' plan invoice for ' . (string) $row['business_name']
                            . ' is ready — pay by ' . $this->day($this->dueAt()->format('Y-m-d')) . ' to keep the workspace active:',
                        $this->urls->app('/billing'),
                    ],
                );
                $done++;
            });
        }

        return $done;
    }

    /** Period ended with the renewal still unpaid → past_due (grace). */
    private function demotePastDue(int $limit): int
    {
        $stmt = $this->db->pdo()->query(
            "SELECT id, tenant_id FROM tenant_subscriptions
              WHERE status = 'active' AND current_period_end <= UTC_TIMESTAMP()
              LIMIT " . max(1, $limit),
        );
        $rows = $stmt === false ? [] : $stmt->fetchAll();
        foreach ($rows as $row) {
            $this->setStatus((int) $row['id'], 'active', 'past_due');
            $this->event((int) $row['tenant_id'], (int) $row['id'], 'payment_grace_started', null, null);
        }

        return count($rows);
    }

    /** Grace exhausted → invoice overdue, subscription expired (read-only). */
    private function expireOverdue(int $limit): int
    {
        $stmt = $this->db->pdo()->query(
            "SELECT s.id, s.tenant_id, i.id AS invoice_id, t.name AS business_name
               FROM tenant_subscriptions s
               JOIN subscription_invoices i ON i.tenant_id = s.tenant_id AND i.subscription_id = s.id
                AND i.status IN ('issued', 'overdue') AND i.due_at <= UTC_TIMESTAMP()
               JOIN tenants t ON t.id = s.tenant_id
              WHERE s.status = 'past_due'
              ORDER BY i.due_at LIMIT " . max(1, $limit),
        );
        $rows = $stmt === false ? [] : $stmt->fetchAll();

        $done = 0;
        foreach ($rows as $row) {
            $this->db->transaction(function () use ($row, &$done): void {
                $this->db->pdo()->prepare(
                    "UPDATE subscription_invoices SET status = 'overdue'
                      WHERE id = :id AND status = 'issued'",
                )->execute(['id' => (int) $row['invoice_id']]);
                $this->setStatus((int) $row['id'], 'past_due', 'expired');
                $this->event((int) $row['tenant_id'], (int) $row['id'], 'subscription_expired', null, null);
                $this->mailOwners((int) $row['tenant_id'], 'Your AlbaBook workspace is now read-only', [
                    (string) $row['business_name'] . '’s subscription invoice was not paid in time,',
                    'so the workspace is now read-only. Pay the outstanding invoice on the',
                    'Billing page to restore full access:',
                    $this->urls->app('/billing'),
                ]);
                $done++;
            });
        }

        return $done;
    }

    /* ------------------------------------------------------------------ */
    /* Plan choice                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Choose a plan. Upgrade (active sub, higher price): prorated invoice
     * now, applies on payment. Downgrade or a choice during trial/expired:
     * stored as scheduled_plan_id and applied by the next paid invoice.
     * An expired workspace that picks a plan gets its activation invoice
     * immediately and returns to past_due grace.
     *
     * @return array<string, mixed>
     */
    public function choosePlan(int $tenantId, string $planCode, ?int $actorUserId): array
    {
        $plan = $this->planByCode($planCode);
        if ($plan === null || !(bool) $plan['is_active']) {
            throw new TransitionException('PLAN_NOT_FOUND', 'That plan is not available.', 404);
        }

        return $this->db->transaction(function () use ($tenantId, $plan, $actorUserId): array {
            $sub = $this->subscriptionRow($tenantId);
            if ($sub !== null && (string) $sub['plan_code'] === (string) $plan['code']) {
                // Picking the current plan clears any scheduled change.
                $this->db->pdo()->prepare(
                    'UPDATE tenant_subscriptions SET scheduled_plan_id = NULL WHERE id = :id',
                )->execute(['id' => (int) $sub['id']]);

                return ['action' => 'unchanged', 'plan' => (string) $plan['name'], 'invoice' => null];
            }

            if ($sub === null) {
                // Never subscribed — start on grace with an activation invoice.
                $subId = $this->createShell($tenantId, (int) $plan['id']);
                $invoice = $this->issueInvoiceId($tenantId, $subId, $plan, 'activation', (int) $plan['price_minor']);
                $this->event($tenantId, $subId, 'plan_chosen', $actorUserId, 'tenant_user');

                return ['action' => 'invoice_issued', 'plan' => (string) $plan['name'], 'invoice' => $invoice];
            }

            $subId = (int) $sub['id'];
            $status = (string) $sub['status'];
            $this->db->pdo()->prepare(
                'UPDATE tenant_subscriptions SET scheduled_plan_id = :p WHERE id = :id',
            )->execute(['p' => (int) $plan['id'], 'id' => $subId]);
            $this->event($tenantId, $subId, 'plan_chosen:' . $plan['code'], $actorUserId, 'tenant_user');

            if ($status === 'active' && (int) $plan['price_minor'] > (int) $sub['price_minor']) {
                // Upgrade: charge the prorated difference for the rest of the period.
                $prorated = $this->prorate($sub, (int) $plan['price_minor']);
                if ($prorated > 0) {
                    $invoice = $this->issueInvoiceId($tenantId, $subId, $plan, 'upgrade', $prorated);

                    return ['action' => 'upgrade_invoiced', 'plan' => (string) $plan['name'], 'invoice' => $invoice];
                }
            }

            if (in_array($status, ['expired', 'cancelled'], true)) {
                // Reactivating: first invoice now, back on grace until paid.
                $this->setStatus($subId, $status, 'past_due');
                $invoice = $this->issueInvoiceId($tenantId, $subId, $plan, 'activation', (int) $plan['price_minor']);

                return ['action' => 'invoice_issued', 'plan' => (string) $plan['name'], 'invoice' => $invoice];
            }

            // Trialing or downgrade on an active sub: applies at the next paid invoice.
            return ['action' => 'scheduled', 'plan' => (string) $plan['name'], 'invoice' => null];
        });
    }

    /** @return list<array<string, mixed>> paid plans a member may pick */
    public function planChoices(): array
    {
        $stmt = $this->db->pdo()->query(
            'SELECT p.code, p.name, p.description, p.price_minor, p.currency, p.billing_interval
               FROM plans p WHERE p.is_active = 1 AND p.price_minor > 0 ORDER BY p.sort_position',
        );

        return $stmt === false ? [] : array_values($stmt->fetchAll());
    }

    /* ------------------------------------------------------------------ */
    /* M-Pesa collection                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * STK prompt for an open invoice on the platform's own Daraja account.
     * Supersedes any still-pending attempt for the same invoice.
     *
     * @return array{payment_uuid: string, checkout_request_id: string, customer_message: string}
     */
    public function payWithMpesa(int $tenantId, string $invoiceUuid, string $phoneInput, int $actorUserId): array
    {
        $invoice = $this->invoiceByUuid($tenantId, $invoiceUuid);
        if ($invoice === null || !in_array((string) $invoice['status'], ['issued', 'overdue'], true)) {
            throw new TransitionException('INVOICE_NOT_PAYABLE', 'That invoice is not awaiting payment.', 404);
        }

        $phone = PhoneNumber::normalise($phoneInput);
        if ($phone === null || !str_starts_with($phone, '+254')) {
            throw new TransitionException('VALIDATION', 'Enter a Kenyan mobile number, e.g. 0712 345 678.', 422, ['phone' => 'Enter a Kenyan mobile number, e.g. 0712 345 678.']);
        }

        $credentials = $this->credentials();
        if ($credentials === null) {
            throw new TransitionException('MPESA_NOT_CONFIGURED', 'M-Pesa billing is not configured yet — pay by bank transfer and we will reconcile it.', 503);
        }

        $amount = (int) $invoice['total_minor'];
        if ($amount <= 0 || $amount % 100 !== 0) {
            throw new TransitionException('VALIDATION', 'This invoice cannot be paid by M-Pesa.', 422);
        }

        $result = $this->daraja->stkPush(
            $credentials,
            $this->config->string('payments.subscriptions.shortcode'),
            $this->config->string('payments.subscriptions.party_b') ?: $this->config->string('payments.subscriptions.shortcode'),
            $this->config->bool('payments.subscriptions.buy_goods', true),
            intdiv($amount, 100),
            $phone,
            $this->callbackUrl((string) $invoice['uuid']),
            (string) $invoice['invoice_number'],
            'AlbaBook ' . $invoice['invoice_number'],
        );

        $paymentUuid = $this->uuids->generate();
        $this->db->transaction(function () use ($invoice, $paymentUuid, $result, $actorUserId): void {
            $this->db->pdo()->prepare(
                "UPDATE subscription_payments SET status = 'cancelled'
                  WHERE tenant_id = :t AND invoice_id = :i AND status = 'pending'",
            )->execute(['t' => (int) $invoice['tenant_id'], 'i' => (int) $invoice['id']]);
            $this->db->pdo()->prepare(
                "INSERT INTO subscription_payments
                    (uuid, tenant_id, invoice_id, amount_minor, currency, method, status, checkout_request_id)
                 VALUES (:uuid, :t, :i, :amount, :cur, 'mpesa', 'pending', :checkout)",
            )->execute([
                'uuid' => $paymentUuid,
                't' => (int) $invoice['tenant_id'],
                'i' => (int) $invoice['id'],
                'amount' => (int) $invoice['total_minor'],
                'cur' => (string) $invoice['currency'],
                'checkout' => $result['checkout_request_id'],
            ]);
            $this->event(
                (int) $invoice['tenant_id'],
                (int) $invoice['subscription_id'],
                'payment_prompted',
                $actorUserId,
                'tenant_user',
            );
        });

        return [
            'payment_uuid' => $paymentUuid,
            'checkout_request_id' => $result['checkout_request_id'],
            'customer_message' => $result['customer_message'],
        ];
    }

    /**
     * Poll a pending payment; falls back to STK Query when the callback is
     * late (same pattern as the till).
     *
     * @return array{status: string, receipt: ?string}
     */
    public function paymentStatus(int $tenantId, string $paymentUuid): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT p.id, p.status, p.provider_reference, p.checkout_request_id
               FROM subscription_payments p
              WHERE p.tenant_id = :t AND p.uuid = :u',
        );
        $stmt->execute(['t' => $tenantId, 'u' => $paymentUuid]);
        $payment = $stmt->fetch();
        if (!is_array($payment)) {
            throw new TransitionException('NOT_FOUND', 'Payment not found.', 404);
        }

        if ((string) $payment['status'] === 'pending' && $payment['checkout_request_id'] !== null) {
            $credentials = $this->credentials();
            if ($credentials !== null) {
                try {
                    $query = $this->daraja->stkQuery(
                        $credentials,
                        $this->config->string('payments.subscriptions.shortcode'),
                        (string) $payment['checkout_request_id'],
                    );
                } catch (DarajaException) {
                    $query = null;
                }
                if ($query !== null) {
                    $this->settle((string) $payment['checkout_request_id'], $query['result_code'], $query['result_desc'], null, 'query');
                    $payment = $this->paymentByUuid($tenantId, $paymentUuid) ?? $payment;
                }
            }
        }

        return [
            'status' => (string) ($payment['status'] ?? 'pending'),
            'receipt' => $payment['provider_reference'] !== null ? (string) $payment['provider_reference'] : null,
        ];
    }

    /**
     * Safaricom callback for a subscription invoice. The URL is signed with
     * the app key like the till's; an unknown signature returns false so the
     * controller answers a plain 404. A malformed body returns true so
     * Safaricom stops retrying (same as the till).
     *
     * @param array<string, mixed> $body
     */
    public function handleCallback(string $invoiceUuid, string $signature, array $body, string $clientIp): bool
    {
        if (!hash_equals($this->callbackSignature($invoiceUuid), $signature)) {
            $this->logger->warning('billing.callback.rejected', ['reason' => 'signature', 'ip' => $clientIp]);

            return false;
        }
        $callback = $body['Body']['stkCallback'] ?? null;
        if (!is_array($callback) || !isset($callback['CheckoutRequestID'])) {
            return true;
        }

        $receipt = null;
        foreach ((array) ($callback['CallbackMetadata']['Item'] ?? []) as $item) {
            if (is_array($item) && ($item['Name'] ?? '') === 'MpesaReceiptNumber') {
                $receipt = (string) ($item['Value'] ?? '');
            }
        }

        return $this->settle(
            (string) $callback['CheckoutRequestID'],
            (string) ($callback['ResultCode'] ?? '1'),
            (string) ($callback['ResultDesc'] ?? ''),
            $receipt !== '' ? $receipt : null,
            'callback',
        );
    }

    /* ------------------------------------------------------------------ */
    /* Settlement                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Terminal outcome for a checkout id: settle the payment, mark the
     * invoice paid and advance/activate the subscription. Version-checked
     * so a late query and the callback cannot double-apply. Returns false
     * when no pending payment matches (unknown or already settled).
     */
    public function settle(string $checkoutRequestId, string $resultCode, string $resultDesc, ?string $receipt, string $via): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT p.id, p.tenant_id, p.invoice_id, i.subscription_id, i.purpose
               FROM subscription_payments p
               JOIN subscription_invoices i ON i.tenant_id = p.tenant_id AND i.id = p.invoice_id
              WHERE p.checkout_request_id = :c AND p.status = 'pending' LIMIT 1",
        );
        $stmt->execute(['c' => $checkoutRequestId]);
        $payment = $stmt->fetch();
        if (!is_array($payment)) {
            return false;
        }

        $succeeded = $resultCode === '0';
        $this->db->transaction(function () use ($payment, $succeeded, $resultCode, $receipt, $via): void {
            $pdo = $this->db->pdo();
            $pdo->prepare(
                'UPDATE subscription_payments
                    SET status = :s, provider_reference = COALESCE(:r, provider_reference), paid_at = IF(:s2 = \'succeeded\', UTC_TIMESTAMP(), paid_at)
                  WHERE id = :id AND status = \'pending\'',
            )->execute([
                's' => $succeeded ? 'succeeded' : ($resultCode === '1032' ? 'cancelled' : 'failed'),
                'r' => $receipt, 's2' => $succeeded ? 'succeeded' : 'failed', 'id' => (int) $payment['id'],
            ]);
            $this->event(
                (int) $payment['tenant_id'],
                (int) $payment['subscription_id'],
                $succeeded ? 'payment_succeeded:' . $via : 'payment_failed:' . $resultCode,
                null,
                null,
            );

            if (!$succeeded) {
                return;
            }

            $pdo->prepare(
                "UPDATE subscription_invoices SET status = 'paid'
                  WHERE id = :id AND status IN ('issued', 'overdue')",
            )->execute(['id' => (int) $payment['invoice_id']]);

            $this->activateFromInvoice((int) $payment['subscription_id'], (string) $payment['purpose']);
        });

        return true;
    }

    /**
     * A paid invoice drives the subscription forward:
     *   activation → fresh period starting now
     *   renewal    → period advances one billing interval
     *   upgrade    → scheduled plan applies, period unchanged
     * Always clears a scheduled plan and returns the row to 'active'.
     */
    private function activateFromInvoice(int $subId, string $purpose): void
    {
        $pdo = $this->db->pdo();
        $sub = $this->subscriptionRowById($subId);
        if ($sub === null) {
            return;
        }

        $applyPlan = 'plan_id = COALESCE(scheduled_plan_id, plan_id)';
        if ($purpose === 'activation') {
            $interval = $this->intervalSql((string) $sub['billing_interval']);
            $pdo->prepare(
                "UPDATE tenant_subscriptions SET status = 'active', {$applyPlan}, scheduled_plan_id = NULL,
                        current_period_start = UTC_TIMESTAMP(), current_period_end = UTC_TIMESTAMP() + INTERVAL {$interval}
                  WHERE id = :id",
            )->execute(['id' => $subId]);
        } elseif ($purpose === 'renewal') {
            $interval = $this->intervalSql((string) $sub['billing_interval']);
            $pdo->prepare(
                "UPDATE tenant_subscriptions SET status = 'active', {$applyPlan}, scheduled_plan_id = NULL,
                        current_period_start = current_period_end,
                        current_period_end = current_period_end + INTERVAL {$interval}
                  WHERE id = :id",
            )->execute(['id' => $subId]);
        } else { // upgrade — same period, new plan
            $pdo->prepare(
                "UPDATE tenant_subscriptions SET status = 'active', {$applyPlan}, scheduled_plan_id = NULL
                  WHERE id = :id",
            )->execute(['id' => $subId]);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Internals                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $plan
     * @return array{uuid: string, invoice_number: string, total_minor: int}
     */
    private function issueInvoiceId(int $tenantId, int $subId, array $plan, string $purpose, int $amountMinor): array
    {
        $uuid = $this->uuids->generate();
        $pdo = $this->db->pdo();
        $due = $this->dueAt();
        $pdo->prepare(
            "INSERT INTO subscription_invoices
                (uuid, tenant_id, subscription_id, invoice_number, status, purpose, issued_at, due_at,
                 subtotal_minor, tax_minor, total_minor, currency)
             VALUES (:uuid, :t, :s, '', 'issued', :purpose, UTC_TIMESTAMP(), :due,
                     :sub, 0, :total, 'KES')",
        )->execute([
            'uuid' => $uuid, 't' => $tenantId, 's' => $subId, 'purpose' => $purpose,
            'due' => $due->format('Y-m-d H:i:s'),
            'sub' => $amountMinor, 'total' => $amountMinor,
        ]);
        $number = self::INVOICE_PREFIX . str_pad((string) $pdo->lastInsertId(), 6, '0', STR_PAD_LEFT);
        $pdo->prepare('UPDATE subscription_invoices SET invoice_number = :n WHERE uuid = :u')
            ->execute(['n' => $number, 'u' => $uuid]);
        $this->event($tenantId, $subId, 'invoice_issued:' . $purpose, null, null);

        return ['uuid' => $uuid, 'invoice_number' => $number, 'total_minor' => $amountMinor];
    }

    /**
     * @param array<string, mixed> $subRow
     * @param array<string, mixed> $plan
     */
    private function issueInvoice(array $subRow, array $plan, string $purpose, int $amountMinor): void
    {
        $this->issueInvoiceId((int) $subRow['tenant_id'], (int) $subRow['id'], $plan, $purpose, $amountMinor);
    }

    /**
     * Prorated upgrade price: (new − old) × days remaining in the period.
     * @param array<string, mixed> $sub
     */
    private function prorate(array $sub, int $newPriceMinor): int
    {
        $start = new \DateTimeImmutable((string) $sub['current_period_start'], new \DateTimeZone('UTC'));
        $end = new \DateTimeImmutable((string) $sub['current_period_end'], new \DateTimeZone('UTC'));
        $total = max(1, $end->getTimestamp() - $start->getTimestamp());
        $left = max(0, $end->getTimestamp() - $this->clock->now()->getTimestamp());

        return (int) round(($newPriceMinor - (int) $sub['price_minor']) * ($left / $total));
    }

    /** New subscription shell for a tenant that never had one (past_due = grace). */
    private function createShell(int $tenantId, int $planId): int
    {
        $this->db->pdo()->prepare(
            "INSERT INTO tenant_subscriptions
                (uuid, tenant_id, plan_id, status, starts_at, current_period_start, current_period_end, scheduled_plan_id)
             VALUES (:uuid, :t, :p, 'past_due', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL 1 DAY, :p2)",
        )->execute(['uuid' => $this->uuids->generate(), 't' => $tenantId, 'p' => $planId, 'p2' => $planId]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    private function subscriptionRow(int $tenantId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.*, p.code AS plan_code, p.price_minor, p.billing_interval
               FROM tenant_subscriptions s JOIN plans p ON p.id = s.plan_id
              WHERE s.tenant_id = ? ORDER BY s.id DESC LIMIT 1',
        );
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function subscriptionRowById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.*, p.billing_interval FROM tenant_subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.id = ?',
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function invoiceByUuid(int $tenantId, string $uuid): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM subscription_invoices WHERE tenant_id = ? AND uuid = ?',
        );
        $stmt->execute([$tenantId, $uuid]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function paymentByUuid(int $tenantId, string $uuid): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM subscription_payments WHERE tenant_id = ? AND uuid = ?',
        );
        $stmt->execute([$tenantId, $uuid]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function plan(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM plans WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function planByCode(string $code): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM plans WHERE code = ?');
        $stmt->execute([$code]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function setStatus(int $subId, string $from, string $to): void
    {
        $this->db->pdo()->prepare(
            'UPDATE tenant_subscriptions SET status = :to WHERE id = :id AND status = :from',
        )->execute(['to' => $to, 'id' => $subId, 'from' => $from]);
    }

    private function event(int $tenantId, int $subId, string $type, ?int $actorId, ?string $actorType): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO subscription_events (tenant_id, subscription_id, event_type, actor_type, actor_id)
             VALUES (?, ?, ?, ?, ?)',
        )->execute([$tenantId, $subId, mb_substr($type, 0, 80), $actorType, $actorId]);
    }

    /**
     * E-mail every member who can manage billing (typically the owner).
     * @param list<string> $lines
     */
    private function mailOwners(int $tenantId, string $subject, array $lines): void
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT DISTINCT u.email
               FROM tenant_users tu
               JOIN users u ON u.id = tu.user_id AND u.status = 'active' AND u.email_verified_at IS NOT NULL
               JOIN membership_roles mr ON mr.tenant_id = tu.tenant_id AND mr.tenant_user_id = tu.id
               JOIN role_permissions rp ON rp.role_id = mr.role_id
               JOIN permissions p ON p.id = rp.permission_id AND p.scope = 'tenant' AND p.identifier = 'billing.view'
              WHERE tu.tenant_id = :t AND tu.status = 'active'
              ORDER BY u.email LIMIT 10",
        );
        $stmt->execute(['t' => $tenantId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $email) {
            try {
                $this->mailer->send(new MailMessage((string) $email, $subject, implode("\n", $lines)));
            } catch (\Throwable) {
                // A mail outage must never roll back the billing sweep.
            }
        }
    }

    private function credentials(): ?MpesaCredentials
    {
        $key = $this->config->string('payments.subscriptions.consumer_key');
        $secret = $this->config->string('payments.subscriptions.consumer_secret');
        $passkey = $this->config->string('payments.subscriptions.passkey');
        $shortcode = $this->config->string('payments.subscriptions.shortcode');
        if ($key === '' || $secret === '' || $passkey === '' || $shortcode === '') {
            return null;
        }

        return new MpesaCredentials(
            $key,
            $secret,
            $passkey,
            $this->config->string('payments.subscriptions.environment') === 'production' ? 'production' : 'sandbox',
            $shortcode,
        );
    }

    private function callbackUrl(string $invoiceUuid): string
    {
        $path = '/webhooks/billing/mpesa/' . $invoiceUuid . '/' . $this->callbackSignature($invoiceUuid);
        $base = $this->config->string('payments.mpesa.callback_base_url');
        // Webhooks are routed on the API host only (bootstrap/routes.php).
        if ($base === '') {
            return $this->urls->to(\App\Core\Http\HostGroup::Api, $path);
        }

        return rtrim($base, '/') . $path;
    }

    private function callbackSignature(string $invoiceUuid): string
    {
        return substr(hash_hmac('sha256', 'albabook:billing-callback:v1|' . $invoiceUuid, $this->config->appKey()), 0, 40);
    }

    private function dueAt(): \DateTimeImmutable
    {
        return $this->clock->now()->modify('+' . $this->graceDays() . ' days');
    }

    private function leadDays(): int
    {
        return max(1, $this->config->int('payments.subscriptions.lead_days', 5));
    }

    private function graceDays(): int
    {
        return max(1, $this->config->int('payments.subscriptions.grace_days', 7));
    }

    private function intervalSql(string $interval): string
    {
        return $interval === 'yearly' ? '1 YEAR' : '1 MONTH';
    }

    private function day(mixed $at): string
    {
        return (new \DateTimeImmutable((string) $at, new \DateTimeZone('UTC')))->format('j M Y');
    }
}
