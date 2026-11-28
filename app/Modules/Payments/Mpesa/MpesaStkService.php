<?php

declare(strict_types=1);

namespace App\Modules\Payments\Mpesa;

use App\Core\Config\Config;
use App\Core\Database\Database;
use App\Core\Http\HostGroup;
use App\Modules\Appointments\Services\TransitionException;
use App\Modules\Till\Repositories\TillRepository;
use App\Shared\Http\AppUrl;
use App\Shared\Security\Encrypter;
use App\Shared\Support\UuidFactory;
use App\Shared\Time\Clock;
use App\Shared\Validation\PhoneNumber;
use Psr\Log\LoggerInterface;

/**
 * Lipa na M-Pesa Online (STK push) for till tickets.
 *
 * Flow
 *   1. initiate(): under the ticket lock, reserve the amount as a 'pending'
 *      payment (pending prompts count against the balance, so two prompts or
 *      cash + a prompt can never overpay), then ask Daraja to prompt the
 *      customer's phone.
 *   2. handleCallback(): Safaricom posts the result to a per-account URL
 *      carrying an HMAC secret (Daraja callbacks are unsigned). Only a
 *      checkout we created is accepted, its amount must match, callbacks are
 *      de-duplicated, and an optional source-IP allow-list applies.
 *   3. refresh() / sweepStale(): callbacks do get lost — after a grace period
 *      the till's status poll (and the scheduler) ask Daraja directly
 *      (STK Query), so a paid customer is never left showing "waiting".
 */
final class MpesaStkService
{
    private const OPEN_STATUSES = ['confirmed', 'checked_in', 'in_service'];
    private const QUERY_AFTER_SECONDS = 25;
    private const QUERY_EVERY_SECONDS = 10;

    public function __construct(
        private readonly Database $db,
        private readonly MpesaRepository $mpesa,
        private readonly TillRepository $till,
        private readonly DarajaClient $daraja,
        private readonly Encrypter $encrypter,
        private readonly UuidFactory $uuids,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly Clock $clock,
    ) {}

    /**
     * @param list<int>|null $branchIds
     * @return array<string, mixed> the request's status payload
     * @throws TransitionException
     */
    public function initiate(
        int $tenantId,
        string $appointmentUuid,
        ?array $branchIds,
        string $phoneInput,
        int $amountMinor,
        string $idempotencyKey,
        ?int $actorUserId,
    ): array {
        $phone = PhoneNumber::normalise($phoneInput);
        if ($phone === null || !str_starts_with($phone, '+254')) {
            throw new TransitionException('VALIDATION', 'Enter the customer’s Safaricom number.', 422, ['phone' => 'Enter a Kenyan mobile number, e.g. 0712 345 678.']);
        }
        if ($amountMinor <= 0 || $amountMinor % 100 !== 0) {
            throw new TransitionException('VALIDATION', 'M-Pesa only accepts whole shillings.', 422, ['amount_minor' => 'Enter a whole-shilling amount.']);
        }

        // Retry of the same click: return the prompt already sent.
        $existing = $this->mpesa->requestByPaymentIdempotency($tenantId, $idempotencyKey);
        if ($existing !== null) {
            return $this->payload($existing);
        }

        [$request, $account, $appointment] = $this->db->transaction(function () use (
            $tenantId,
            $appointmentUuid,
            $branchIds,
            $phone,
            $amountMinor,
            $idempotencyKey,
            $actorUserId,
        ): array {
            $appointment = $this->till->appointment($tenantId, $appointmentUuid, true);
            if ($appointment === null) {
                throw new TransitionException('NOT_FOUND', 'Appointment not found.', 404);
            }
            if ($branchIds !== null && !in_array((int) $appointment['branch_id'], $branchIds, true)) {
                throw new TransitionException('FORBIDDEN', 'You do not have access to this branch.', 403);
            }
            if (!in_array((string) $appointment['status'], self::OPEN_STATUSES, true)) {
                throw new TransitionException('TICKET_CLOSED', 'This ticket is closed.', 409);
            }
            $account = $this->mpesa->activeAccountFor($tenantId, (int) $appointment['branch_id']);
            if ($account === null) {
                throw new TransitionException('MPESA_NOT_CONFIGURED', 'No live M-Pesa till or paybill is set up for this branch (Settings → Payments).', 409);
            }

            $totals = $this->till->computeTotals($tenantId, (int) $appointment['id']);
            $outstanding = $totals['total_minor'] - $totals['paid_minor'] + $totals['refunded_minor']
                - $this->till->pendingPromptTotal($tenantId, (int) $appointment['id']);
            if ($amountMinor > $outstanding) {
                throw new TransitionException('PAYMENT_EXCEEDS_BALANCE', 'The amount exceeds the outstanding balance (including prompts still waiting).', 422, ['amount_minor' => 'The amount exceeds the outstanding balance.']);
            }

            $paymentId = $this->till->insertPayment($tenantId, [
                'uuid' => $this->uuids->generate(),
                'branch_id' => $appointment['branch_id'],
                'appointment_id' => $appointment['id'],
                'customer_id' => $appointment['customer_id'],
                'kind' => 'full',
                'method' => 'mpesa',
                'provider' => 'mpesa_daraja',
                'provider_reference' => null,
                'amount_minor' => $amountMinor,
                'currency' => $appointment['currency'],
                'status' => 'pending',
                'received_at' => null,
                'recorded_by_user_id' => $actorUserId,
                'idempotency_key' => $idempotencyKey,
            ]);
            $uuid = $this->uuids->generate();
            $id = $this->mpesa->insertRequest([
                'uuid' => $uuid,
                'tenant_id' => $tenantId,
                'branch_id' => $appointment['branch_id'],
                'appointment_id' => $appointment['id'],
                'payment_id' => $paymentId,
                'payment_account_id' => $account['id'],
                'phone_e164' => $phone,
                'amount_minor' => $amountMinor,
                'requested_by_user_id' => $actorUserId,
            ]);

            return [$this->mpesa->requestByUuid($tenantId, $uuid) ?? ['id' => $id], $account, $appointment];
        });

        // Outside the transaction: never hold the ticket lock across an HTTP call.
        try {
            $credentials = $this->credentials($account);
            [$shortcode, $partyB, $buyGoods] = $this->parties($account, $credentials);
            $sent = $this->daraja->stkPush(
                $credentials,
                $shortcode,
                $partyB,
                $buyGoods,
                intdiv($amountMinor, 100),
                ltrim($phone, '+'),
                $this->callbackUrl((string) $account['uuid']),
                (string) $appointment['reference'],
                'AlbaBook',
            );
            $this->mpesa->markSent((int) $request['id'], $sent['merchant_request_id'], $sent['checkout_request_id']);
        } catch (DarajaException $e) {
            $this->db->transaction(fn() => $this->mpesa->settle($request, 'failed', 'SEND_FAILED', $e->getMessage(), null, 'callback'));
            throw new TransitionException('MPESA_FAILED', 'M-Pesa could not send the prompt: ' . $e->getMessage(), 502);
        }

        return $this->payload($this->mpesa->requestByUuid($tenantId, (string) $request['uuid']) ?? $request);
    }

    /**
     * Current status for the till; queries Daraja when the callback is late.
     * @param list<int>|null $branchIds
     * @return array<string, mixed>
     */
    public function refresh(int $tenantId, string $requestUuid, ?array $branchIds = null): array
    {
        $request = $this->mpesa->requestByUuid($tenantId, $requestUuid);
        if ($request === null || ($branchIds !== null && !in_array((int) $request['branch_id'], $branchIds, true))) {
            throw new TransitionException('NOT_FOUND', 'Payment prompt not found.', 404);
        }
        if ($request['status'] === 'pending' && $this->dueForQuery($request)) {
            $this->query($request);
            $request = $this->mpesa->requestByUuid($tenantId, $requestUuid) ?? $request;
        }

        return $this->payload($request);
    }

    /**
     * Safaricom → us. Always answers "accepted" to Safaricom for anything it
     * may legitimately retry; returns false when the call must be rejected
     * outright (bad signature / unknown account).
     * @param array<string, mixed> $body
     */
    public function handleCallback(string $accountUuid, string $signature, array $body, string $clientIp): bool
    {
        $account = $this->mpesa->accountByUuid($accountUuid);
        if ($account === null || !hash_equals($this->callbackSignature($accountUuid), $signature)) {
            $this->logger->warning('mpesa.callback.rejected', ['reason' => 'signature', 'ip' => $clientIp]);

            return false;
        }
        if (!$this->ipAllowed($clientIp)) {
            $this->logger->warning('mpesa.callback.rejected', ['reason' => 'ip', 'ip' => $clientIp]);

            return false;
        }

        $cb = $body['Body']['stkCallback'] ?? null;
        $checkoutId = is_array($cb) ? (string) ($cb['CheckoutRequestID'] ?? '') : '';
        if ($checkoutId === '') {
            return true; // malformed — acknowledge so Safaricom stops retrying
        }
        $resultCode = (string) ($cb['ResultCode'] ?? '');
        $resultDesc = (string) ($cb['ResultDesc'] ?? '');
        $meta = [];
        foreach ((array) ($cb['CallbackMetadata']['Item'] ?? []) as $item) {
            if (is_array($item) && isset($item['Name'])) {
                $meta[(string) $item['Name']] = $item['Value'] ?? null;
            }
        }

        // De-duplicate: the same callback can be delivered more than once.
        $eventId = 'stk:' . $checkoutId . ':' . $resultCode;
        $first = $this->mpesa->recordProviderEvent((int) $account['tenant_id'], $eventId, true, $body);

        $this->db->transaction(function () use ($account, $checkoutId, $resultCode, $resultDesc, $meta, $first): void {
            $request = $this->mpesa->requestByCheckout($checkoutId, true);
            // Only checkouts this account created; never another tenant's.
            if ($request === null || (int) $request['payment_account_id'] !== (int) $account['id']
                || (int) $request['tenant_id'] !== (int) $account['tenant_id']) {
                return;
            }
            $receipt = isset($meta['MpesaReceiptNumber']) ? strtoupper((string) $meta['MpesaReceiptNumber']) : null;

            if ($request['status'] !== 'pending' && $request['status'] !== 'initiated') {
                // Already settled (e.g. by STK Query): only fill a missing receipt.
                if ($request['status'] === 'succeeded' && $receipt !== null && $resultCode === '0') {
                    $this->mpesa->attachReceipt($request, $receipt);
                }

                return;
            }
            if (!$first) {
                return;
            }

            if ($resultCode === '0') {
                $paid = (int) round(((float) ($meta['Amount'] ?? 0)) * 100);
                if ($paid !== (int) $request['amount_minor']) {
                    // Never credit a different amount than was requested.
                    $this->mpesa->settle($request, 'failed', 'AMOUNT_MISMATCH', "Paid {$paid}, expected {$request['amount_minor']}", $receipt, 'callback');
                    $this->logger->error('mpesa.callback.amount_mismatch', ['request' => $request['uuid'], 'paid' => $paid, 'expected' => $request['amount_minor']]);
                    $this->till->refreshTotals((int) $request['tenant_id'], (int) $request['appointment_id']);

                    return;
                }
                $this->succeed($request, $receipt, $resultDesc, 'callback');

                return;
            }

            $this->mpesa->settle($request, self::failureStatus($resultCode), $resultCode, $resultDesc, null, 'callback');
            $this->till->refreshTotals((int) $request['tenant_id'], (int) $request['appointment_id']);
        });
        $this->mpesa->markEventProcessed($eventId);

        return true;
    }

    /** Scheduler safety net: settle prompts whose callback never came. */
    public function sweepStale(): int
    {
        $count = 0;
        foreach ($this->mpesa->stalePending(90) as $request) {
            try {
                $this->query($request);
                $count++;
            } catch (\Throwable $e) {
                $this->logger->warning('mpesa.sweep.failed', ['request' => $request['uuid'], 'error' => $e->getMessage()]);
            }
        }

        return $count;
    }

    public function callbackUrl(string $accountUuid): string
    {
        $base = (string) $this->config->string('payments.mpesa.callback_base_url', '');
        $path = '/webhooks/mpesa/stk/' . $accountUuid . '/' . $this->callbackSignature($accountUuid);

        return $base !== ''
            ? rtrim($base, '/') . $path
            : (new AppUrl($this->config))->to(HostGroup::Api, $path);
    }

    /** @param array<string, mixed> $request */
    private function query(array $request): void
    {
        $this->mpesa->touchQueried((int) $request['id']);
        $account = $this->mpesa->accountById((int) $request['tenant_id'], (int) $request['payment_account_id']);
        if ($account === null || $request['checkout_request_id'] === null) {
            return;
        }
        $credentials = $this->credentials($account);
        [$shortcode] = $this->parties($account, $credentials);
        $result = $this->daraja->stkQuery($credentials, $shortcode, (string) $request['checkout_request_id']);
        if ($result === null) {
            return; // customer still on the prompt
        }

        $this->db->transaction(function () use ($request, $result): void {
            $locked = $this->mpesa->requestByCheckout((string) $request['checkout_request_id'], true);
            if ($locked === null || $locked['status'] !== 'pending') {
                return; // the callback won the race
            }
            if ($result['result_code'] === '0') {
                // STK Query does not return the receipt number; a late
                // callback fills it in via attachReceipt().
                $this->succeed($locked, null, $result['result_desc'], 'query');

                return;
            }
            $this->mpesa->settle($locked, self::failureStatus($result['result_code']), $result['result_code'], $result['result_desc'], null, 'query');
            $this->till->refreshTotals((int) $locked['tenant_id'], (int) $locked['appointment_id']);
        });
    }

    /** @param array<string, mixed> $request */
    private function succeed(array $request, ?string $receipt, string $desc, string $via): void
    {
        $tenantId = (int) $request['tenant_id'];
        $appointmentId = (int) $request['appointment_id'];
        $this->mpesa->settle($request, 'succeeded', '0', $desc, $receipt, $via);
        $this->till->refreshTotals($tenantId, $appointmentId);
        $this->till->outbox($tenantId, $this->uuids->generate(), 'payment.received', $appointmentId, json_encode([
            'event' => 'payment.received',
            'method' => 'mpesa',
            'amount_minor' => (int) $request['amount_minor'],
            'mpesa_receipt' => $receipt,
            'stk_request' => $request['uuid'],
        ], JSON_THROW_ON_ERROR));
    }

    private static function failureStatus(string $code): string
    {
        return match ($code) {
            '1032' => 'cancelled',   // customer cancelled the prompt
            '1037' => 'timeout',     // phone unreachable / no response
            default => 'failed',     // e.g. 1 insufficient funds, 2001 wrong PIN
        };
    }

    /** @param array<string, mixed> $request */
    private function dueForQuery(array $request): bool
    {
        $utc = new \DateTimeZone('UTC');
        $now = $this->clock->now()->getTimestamp();
        $created = new \DateTimeImmutable((string) $request['created_at'], $utc);
        $last = $request['last_queried_at'] !== null
            ? new \DateTimeImmutable((string) $request['last_queried_at'], $utc)
            : null;

        return $now - $created->getTimestamp() >= self::QUERY_AFTER_SECONDS
            && ($last === null || $now - $last->getTimestamp() >= self::QUERY_EVERY_SECONDS);
    }

    /** @param array<string, mixed> $account */
    private function credentials(array $account): MpesaCredentials
    {
        $raw = $account['credentials_encrypted'] !== null ? $this->encrypter->decrypt((string) $account['credentials_encrypted']) : '';
        $credentials = MpesaCredentials::fromJson($raw);
        if ($credentials === null) {
            throw new DarajaException('The M-Pesa account is missing its consumer key, secret or passkey (Settings → Payments).');
        }

        return $credentials;
    }

    /**
     * Daraja parties: a paybill signs and receives with its shortcode; a
     * Buy Goods till signs with its store (head-office) number and the till
     * number receives.
     * @param array<string, mixed> $account
     * @return array{0: string, 1: string, 2: bool}
     */
    private function parties(array $account, MpesaCredentials $credentials): array
    {
        $shortcode = (string) $account['shortcode'];
        if ($account['account_type'] === 'till') {
            if ($credentials->storeNumber === null) {
                throw new DarajaException('Buy Goods tills need the store number (head office) for STK push.');
            }

            return [$credentials->storeNumber, $shortcode, true];
        }

        return [$shortcode, $shortcode, false];
    }

    private function callbackSignature(string $accountUuid): string
    {
        return substr(hash_hmac('sha256', 'albabook:mpesa-callback:v1|' . $accountUuid, $this->config->appKey()), 0, 40);
    }

    private function ipAllowed(string $ip): bool
    {
        $allow = $this->config->get('payments.mpesa.callback_ips', []);
        if (!is_array($allow) || $allow === []) {
            return true;
        }

        return (new \App\Shared\Support\TrustedProxies(array_values(array_filter($allow, 'is_string'))))->isTrusted($ip);
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function payload(array $request): array
    {
        return [
            'uuid' => (string) $request['uuid'],
            'status' => (string) $request['status'],
            'amount_minor' => (int) $request['amount_minor'],
            'phone' => (string) $request['phone_e164'],
            'receipt' => $request['mpesa_receipt'] ?? null,
            'message' => match ((string) $request['status']) {
                'initiated', 'pending' => 'Prompt sent — ask the customer to enter their M-Pesa PIN.',
                'succeeded' => 'Payment received.',
                'cancelled' => 'The customer cancelled the prompt.',
                'timeout' => 'The customer did not respond in time.',
                default => (string) ($request['result_desc'] ?? 'The payment failed.'),
            },
            'done' => !in_array((string) $request['status'], ['initiated', 'pending'], true),
        ];
    }
}
