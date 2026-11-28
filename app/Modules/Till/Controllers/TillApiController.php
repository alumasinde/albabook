<?php

declare(strict_types=1);

namespace App\Modules\Till\Controllers;

use App\Core\Database\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Appointments\Repositories\AppointmentRepository;
use App\Modules\Appointments\Services\AppointmentTransitionService;
use App\Modules\Appointments\Services\TransitionException;
use App\Modules\Auth\Context\MembershipContext;
use App\Modules\Notifications\Services\NotificationPolicy;
use App\Modules\Tax\Etims\EtimsException;
use App\Modules\Tax\Etims\EtimsService;
use App\Modules\Till\Repositories\TillRepository;
use App\Shared\Http\ApiResponse;
use Psr\Log\LoggerInterface;
use App\Shared\Support\UuidFactory;
use App\Tenancy\TenantContext;

/**
 * Private JSON API for the till (session-authenticated, D-008). The
 * appointment IS the ticket — booked services are read-only lines,
 * appointment_sale_items are till lines, payments are tenders.
 *
 * Items/payments mutations require payments.record; refunds require
 * payments.refund; checkout requires appointments.update. Reads require
 * payments.view. All money math is recomputed server-side.
 */
final class TillApiController
{
    /** Ticket statuses that still allow editing. */
    private const OPEN_STATUSES = ['confirmed', 'checked_in', 'in_service'];

    public function __construct(
        private readonly TillRepository $till,
        private readonly Database $db,
        private readonly UuidFactory $uuids,
        private readonly NotificationPolicy $notificationPolicy,
        private readonly AppointmentRepository $appointments,
        private readonly AppointmentTransitionService $transitions,
        private readonly EtimsService $etims,
        private readonly LoggerInterface $logger,
    ) {}

    /** Full ticket state for the checkout drawer.
     * @param array<string, string> $params
     */
    public function ticket(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('payments.view')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to view payments.', 403);
        }

        $appointment = $this->till->appointment($tenant->tenantId, (string) $params['uuid']);
        if ($appointment === null) {
            return ApiResponse::error('NOT_FOUND', 'Appointment not found.', 404);
        }
        if (!$membership->canAccessBranch((int) $appointment['branch_id'])) {
            return ApiResponse::error('FORBIDDEN', 'You do not have access to this branch.', 403);
        }

        return ApiResponse::ok($this->payload($tenant, $membership, $appointment));
    }

    /** Add a till line: service, product, discount or tip.
     * @param array<string, string> $params
     */
    public function addItem(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('payments.record')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to edit the ticket.', 403);
        }

        $body = $request->body();
        $kind = (string) ($body['kind'] ?? '');
        if (!in_array($kind, ['service', 'product', 'discount', 'tip'], true)) {
            return ApiResponse::error('VALIDATION', 'Invalid item kind.', 422, ['kind' => 'Choose a valid item type.']);
        }

        try {
            $payload = $this->db->transaction(function () use ($tenant, $membership, $params, $body, $kind): array {
                $appointment = $this->till->appointment($tenant->tenantId, (string) $params['uuid'], true);
                if ($appointment === null) {
                    throw new TransitionException('NOT_FOUND', 'Appointment not found.', 404);
                }
                $this->assertBranch($membership, $appointment);
                $this->assertOpen($appointment);

                $line = $this->buildLine($tenant->tenantId, $kind, $body);
                if (isset($line['error'])) {
                    throw new TransitionException('VALIDATION', 'Fix the highlighted fields.', 422, $line['error']);
                }
                if ($kind === 'discount') {
                    $this->assertDiscountAllowed($membership, $tenant->tenantId, (int) $appointment['id'], (int) $line['unit_amount_minor']);
                }

                $line['line_no'] = $this->till->nextLineNo($tenant->tenantId, (int) $appointment['id']);
                $line['added_by_user_id'] = $membership->actorUserId();
                $this->till->insertSaleItem($tenant->tenantId, (int) $appointment['id'], $line);

                $derived = $this->refreshTotals($tenant->tenantId, (int) $appointment['id']);
                $appointment['payment_status'] = $derived['payment_status'];
                $appointment['total_amount_minor'] = $derived['total_minor'];

                return $this->payload($tenant, $membership, $appointment);
            });
        } catch (TransitionException $e) {
            return ApiResponse::error($e->apiCode, $e->getMessage(), $e->status, $e->fields ?: null);
        }

        return ApiResponse::ok($payload);
    }

    /** Adjust a till line's quantity.
     * @param array<string, string> $params
     */
    public function updateItem(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('payments.record')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to edit the ticket.', 403);
        }

        $quantity = (int) ($request->body()['quantity'] ?? 0);
        if ($quantity < 1 || $quantity > 999) {
            return ApiResponse::error('VALIDATION', 'Quantity must be 1-999.', 422, ['quantity' => 'Quantity must be 1-999.']);
        }

        try {
            $payload = $this->db->transaction(function () use ($tenant, $membership, $params, $quantity): array {
                $appointment = $this->till->appointment($tenant->tenantId, (string) $params['uuid'], true);
                if ($appointment === null) {
                    throw new TransitionException('NOT_FOUND', 'Appointment not found.', 404);
                }
                $this->assertBranch($membership, $appointment);
                $this->assertOpen($appointment);

                $item = $this->till->findSaleItem($tenant->tenantId, (int) $appointment['id'], (int) $params['id']);
                if ($item === null) {
                    throw new TransitionException('NOT_FOUND', 'Line not found.', 404);
                }

                $unit = (int) $item['unit_amount_minor'];
                $signed = $item['kind'] === 'discount' ? -1 : 1;
                if ($item['kind'] === 'discount' && !$membership->can('payments.discount')) {
                    throw new TransitionException('FORBIDDEN', 'Changing a discount needs manager approval.', 403);
                }
                $this->till->updateSaleItem($tenant->tenantId, (int) $item['id'], [
                    'quantity' => $quantity,
                    'total_minor' => $signed * $unit * $quantity,
                ]);
                // Raising a discount's quantity or lowering a service's can
                // push discounts past the goods on the ticket.
                $this->assertDiscountsWithinSubtotal($tenant->tenantId, (int) $appointment['id']);

                $derived = $this->refreshTotals($tenant->tenantId, (int) $appointment['id']);
                $appointment['payment_status'] = $derived['payment_status'];
                $appointment['total_amount_minor'] = $derived['total_minor'];

                return $this->payload($tenant, $membership, $appointment);
            });
        } catch (TransitionException $e) {
            return ApiResponse::error($e->apiCode, $e->getMessage(), $e->status, $e->fields ?: null);
        }

        return ApiResponse::ok($payload);
    }

    /** Remove a till line.
     * @param array<string, string> $params
     */
    public function removeItem(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('payments.record')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to edit the ticket.', 403);
        }

        try {
            $payload = $this->db->transaction(function () use ($tenant, $membership, $params): array {
                $appointment = $this->till->appointment($tenant->tenantId, (string) $params['uuid'], true);
                if ($appointment === null) {
                    throw new TransitionException('NOT_FOUND', 'Appointment not found.', 404);
                }
                $this->assertBranch($membership, $appointment);
                $this->assertOpen($appointment);

                $item = $this->till->findSaleItem($tenant->tenantId, (int) $appointment['id'], (int) $params['id']);
                if ($item === null) {
                    throw new TransitionException('NOT_FOUND', 'Line not found.', 404);
                }

                $this->till->deleteSaleItem($tenant->tenantId, (int) $item['id']);
                // Removing a service/product must not leave discounts larger
                // than what remains (removing a discount always passes).
                $this->assertDiscountsWithinSubtotal($tenant->tenantId, (int) $appointment['id']);
                $derived = $this->refreshTotals($tenant->tenantId, (int) $appointment['id']);
                $appointment['payment_status'] = $derived['payment_status'];
                $appointment['total_amount_minor'] = $derived['total_minor'];

                return $this->payload($tenant, $membership, $appointment);
            });
        } catch (TransitionException $e) {
            return ApiResponse::error($e->apiCode, $e->getMessage(), $e->status, $e->fields ?: null);
        }

        return ApiResponse::ok($payload);
    }

    /** Record a tender against the ticket.
     * @param array<string, string> $params
     */
    public function pay(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('payments.record')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to record payments.', 403);
        }

        $body = $request->body();
        $fields = [];

        $methods = array_column($this->till->paymentMethods(), null, 'code');
        $method = (string) ($body['method'] ?? '');
        /** @var array{code: string, requires_reference: int}|null $methodRow */
        $methodRow = $methods[$method] ?? null;
        if ($methodRow === null) {
            $fields['method'] = 'Choose a payment method.';
        }

        $amount = self::parseAmount($body['amount_minor'] ?? null);
        if ($amount === null) {
            $fields['amount_minor'] = 'Enter an amount greater than zero.';
        }

        $reference = trim((string) ($body['reference'] ?? ''));
        if ($methodRow !== null && (int) $methodRow['requires_reference'] === 1 && $reference === '') {
            $fields['reference'] = 'Enter the transaction reference (e.g. the M-Pesa code).';
        }
        if (mb_strlen($reference) > 100) {
            $fields['reference'] = 'Reference is too long.';
        }

        // Client-supplied idempotency key: retries (double-click, timeout,
        // retry) replay with the same key and hit uq_payments_idempotency
        // instead of recording a second tender.
        $idempotencyKey = trim((string) ($body['idempotency_key'] ?? ''));
        if (mb_strlen($idempotencyKey) < 8 || mb_strlen($idempotencyKey) > 100 || preg_match('/[^\x20-\x7E]/', $idempotencyKey) === 1) {
            $fields['idempotency_key'] = 'A payment idempotency key is required.';
        }

        if ($fields !== []) {
            return ApiResponse::error('VALIDATION', 'Fix the highlighted fields.', 422, $fields);
        }

        try {
            $payload = $this->db->transaction(function () use ($tenant, $membership, $params, $method, $amount, $reference, $idempotencyKey): array {
                $appointment = $this->till->appointment($tenant->tenantId, (string) $params['uuid'], true);
                if ($appointment === null) {
                    throw new TransitionException('NOT_FOUND', 'Appointment not found.', 404);
                }
                $this->assertBranch($membership, $appointment);
                $this->assertOpen($appointment);

                // Idempotent replay before any validation: a retry of a
                // payment that already landed returns the recorded result.
                if ($this->till->paymentByIdempotencyKey($tenant->tenantId, (int) $appointment['id'], $idempotencyKey) !== null) {
                    $derived = $this->refreshTotals($tenant->tenantId, (int) $appointment['id']);
                    $appointment['payment_status'] = $derived['payment_status'];
                    $appointment['total_amount_minor'] = $derived['total_minor'];

                    return $this->payload($tenant, $membership, $appointment);
                }

                // Overpayment cap: nothing may be recorded beyond the balance
                // actually due (net of succeeded refunds).
                $totals = $this->computeTotals($tenant->tenantId, (int) $appointment['id']);
                // M-Pesa prompts still awaiting the customer are reserved, so
                // cash + a pending prompt can never overpay the ticket.
                $outstanding = max(0, $totals['total_minor'] - $totals['paid_minor'] + $totals['refunded_minor']
                    - $this->till->pendingPromptTotal($tenant->tenantId, (int) $appointment['id']));
                if ((int) $amount > $outstanding) {
                    throw new TransitionException(
                        'PAYMENT_EXCEEDS_BALANCE',
                        'The amount exceeds the outstanding balance.',
                        422,
                        ['amount_minor' => 'The amount exceeds the outstanding balance.'],
                    );
                }

                $this->till->insertPayment($tenant->tenantId, [
                    'uuid' => $this->uuids->generate(),
                    'branch_id' => $appointment['branch_id'],
                    'appointment_id' => $appointment['id'],
                    'customer_id' => $appointment['customer_id'],
                    'kind' => 'full',
                    'method' => $method,
                    'provider' => null,
                    'provider_reference' => $reference !== '' ? strtoupper($reference) : null,
                    'amount_minor' => (int) $amount,
                    'currency' => $appointment['currency'],
                    'status' => 'succeeded',
                    'received_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                    'recorded_by_user_id' => $membership->actorUserId(),
                    'idempotency_key' => $idempotencyKey,
                ]);

                $derived = $this->refreshTotals($tenant->tenantId, (int) $appointment['id']);
                $appointment['payment_status'] = $derived['payment_status'];
                $appointment['total_amount_minor'] = $derived['total_minor'];

                return $this->payload($tenant, $membership, $appointment);
            });
        } catch (TransitionException $e) {
            return ApiResponse::error($e->apiCode, $e->getMessage(), $e->status, $e->fields ?: null);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'uq_payments_idempotency')) {
                // Idempotent replay: the earlier request already recorded this
                // payment — return the current ticket as if it succeeded again.
                $appointment = $this->till->appointment($tenant->tenantId, (string) $params['uuid']);
                if ($appointment !== null && $membership->canAccessBranch((int) $appointment['branch_id'])) {
                    $derived = $this->refreshTotals($tenant->tenantId, (int) $appointment['id']);
                    $appointment['payment_status'] = $derived['payment_status'];
                    $appointment['total_amount_minor'] = $derived['total_minor'];

                    return ApiResponse::ok($this->payload($tenant, $membership, $appointment));
                }
                throw $e;
            }
            if (str_contains($e->getMessage(), 'uq_payments_method_reference')) {
                return ApiResponse::error('DUPLICATE_REFERENCE', 'That transaction reference is already recorded.', 409);
            }
            throw $e;
        }

        return ApiResponse::ok($payload);
    }

    /** Refund part or all of a tender.
     * @param array<string, string> $params
     */
    public function refund(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('payments.refund')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to refund payments.', 403);
        }

        $body = $request->body();
        $amount = self::parseAmount($body['amount_minor'] ?? null);
        $reason = trim((string) ($body['reason'] ?? ''));
        if ($amount === null) {
            return ApiResponse::error('VALIDATION', 'Enter a refund amount greater than zero.', 422, ['amount_minor' => 'Invalid amount.']);
        }
        if (mb_strlen($reason) > 255) {
            return ApiResponse::error('VALIDATION', 'Reason is too long.', 422, ['reason' => 'Too long.']);
        }

        try {
            $payload = $this->db->transaction(function () use ($tenant, $membership, $params, $amount, $reason): array {
                $appointment = $this->till->appointment($tenant->tenantId, (string) $params['uuid'], true);
                if ($appointment === null) {
                    throw new TransitionException('NOT_FOUND', 'Appointment not found.', 404);
                }
                $this->assertBranch($membership, $appointment);

                $payment = $this->till->findPaymentByUuid($tenant->tenantId, (string) $params['payment_uuid']);
                if ($payment === null || (int) $payment['appointment_id'] !== (int) $appointment['id']) {
                    throw new TransitionException('NOT_FOUND', 'Payment not found.', 404);
                }
                if ($payment['status'] !== 'succeeded') {
                    throw new TransitionException('NOT_REFUNDABLE', 'Only succeeded payments can be refunded.', 409);
                }

                $already = $this->till->refundedAgainst($tenant->tenantId, (int) $payment['id']);
                $refundable = (int) $payment['amount_minor'] - $already;
                if ((int) $amount > $refundable) {
                    throw new TransitionException('REFUND_EXCEEDS', 'Refund exceeds the refundable amount.', 409);
                }

                $this->till->insertRefund($tenant->tenantId, [
                    'uuid' => $this->uuids->generate(),
                    'payment_id' => $payment['id'],
                    'amount_minor' => (int) $amount,
                    'currency' => $payment['currency'],
                    'status' => 'succeeded',
                    'reason' => $reason !== '' ? $reason : null,
                    'refunded_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                    'recorded_by_user_id' => $membership->actorUserId(),
                ]);

                $derived = $this->refreshTotals($tenant->tenantId, (int) $appointment['id']);
                $appointment['payment_status'] = $derived['payment_status'];
                $appointment['total_amount_minor'] = $derived['total_minor'];

                return $this->payload($tenant, $membership, $appointment);
            });
        } catch (TransitionException $e) {
            return ApiResponse::error($e->apiCode, $e->getMessage(), $e->status, $e->fields ?: null);
        }

        return ApiResponse::ok($payload);
    }

    /** Complete the visit: policy check, stock decrement, complete, receipt.
     * @param array<string, string> $params
     */
    public function checkout(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('appointments.update')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to complete appointments.', 403);
        }

        try {
            $payload = $this->db->transaction(function () use ($tenant, $membership, $params): array {
                $appointment = $this->till->appointment($tenant->tenantId, (string) $params['uuid'], true);
                if ($appointment === null) {
                    throw new TransitionException('NOT_FOUND', 'Appointment not found.', 404);
                }
                $this->assertBranch($membership, $appointment);
                $this->assertOpen($appointment);

                // Same visit-window gate as the status-transition path:
                // a ticket can't be checked out before its appointment
                // window opens.
                $issue = $this->transitions->timingError(
                    'completed',
                    (string) $appointment['start_at'],
                    (string) $appointment['end_at'],
                    $this->appointments->visitWindowPrefs($tenant->tenantId),
                );
                if ($issue !== null) {
                    throw new TransitionException($issue[0], $issue[1], 409);
                }

                $totals = $this->computeTotals($tenant->tenantId, (int) $appointment['id']);
                $balance = $totals['total_minor'] - $totals['paid_minor'];

                if ($balance > 0 && $this->till->fullPaymentRequired($tenant->tenantId)) {
                    throw new TransitionException('BALANCE_DUE', 'The ticket still has a balance due.', 409);
                }

                // Decrement tracked stock once, at completion.
                foreach ($this->till->saleItems($tenant->tenantId, (int) $appointment['id']) as $item) {
                    if ($item['kind'] === 'product' && $item['product_id'] !== null) {
                        $this->till->decrementStock($tenant->tenantId, (int) $item['product_id'], (int) $item['quantity']);
                    }
                }

                $derived = $this->refreshTotals($tenant->tenantId, (int) $appointment['id']);
                $appointment['payment_status'] = $derived['payment_status'];
                $appointment['total_amount_minor'] = $derived['total_minor'];

                // Complete inside this transaction — the transition service
                // owns its own txn and Database forbids nesting. Same rules:
                // optimistic version check + appointment_events row.
                $newVersion = $this->appointments->updateStatus(
                    $tenant->tenantId,
                    (int) $appointment['id'],
                    'completed',
                    (int) $appointment['version'] + 1,
                );
                if ($newVersion === null) {
                    throw new TransitionException('STALE_APPOINTMENT', 'This appointment was changed by someone else. Reload it and retry.', 409);
                }
                $this->appointments->insertEvent(
                    $tenant->tenantId,
                    (int) $appointment['id'],
                    'status.completed',
                    'tenant_user',
                    $membership->actorUserId(),
                    (string) $appointment['status'],
                    'completed',
                );

                // eTIMS: number and freeze the tax invoice in this same
                // transaction (submitted to KRA later by the scheduler, so
                // checkout never waits on KRA). A setup problem must not
                // block completing a paid visit — it is logged instead.
                try {
                    $this->etims->queueInvoice($tenant->tenantId, (int) $appointment['id']);
                } catch (EtimsException $e) {
                    $this->logger->error('etims.queue.failed', ['appointment' => $appointment['uuid'], 'error' => $e->getMessage()]);
                }

                $this->emitNotifications($tenant->tenantId, $appointment, $totals);

                return $this->payload($tenant, $membership, $this->till->appointment($tenant->tenantId, (string) $params['uuid']) ?? $appointment);
            });
        } catch (TransitionException $e) {
            return ApiResponse::error($e->apiCode, $e->getMessage(), $e->status, $e->fields ?: null);
        }

        return ApiResponse::ok($payload);
    }

    /* ------------------------------------------------------------------ */

    /** Recompute total + derived payment_status from SQL state.
     * @return array{total_minor: int, payment_status: string}
     */
    private function refreshTotals(int $tenantId, int $appointmentId): array
    {
        return $this->till->refreshTotals($tenantId, $appointmentId);
    }

    /** Ticket breakdown — the repository owns the math (receipt shares it).
     * @return array{subtotal_minor: int, discount_minor: int, service_charge_minor: int,
     *               tax_minor: int, net_minor: int, tip_minor: int, total_minor: int,
     *               paid_minor: int, refunded_minor: int, balance_minor: int,
     *               tax_breakdown: list<array{rate_bp: int, taxable_minor: int, vat_minor: int}>}
     */
    private function computeTotals(int $tenantId, int $appointmentId): array
    {
        return $this->till->computeTotals($tenantId, $appointmentId);
    }

    /** Ticket payload: appointment, lines, tenders, totals, pickers.
     * @param array<string, mixed> $appointment
     * @return array<string, mixed>
     */
    private function payload(TenantContext $tenant, MembershipContext $membership, array $appointment): array
    {
        $totals = $this->computeTotals($tenant->tenantId, (int) $appointment['id']);

        return [
            'appointment' => [
                'uuid' => $appointment['uuid'],
                'reference' => $appointment['reference'],
                'status' => $appointment['status'],
                'payment_status' => $appointment['payment_status'],
                'currency' => $appointment['currency'],
                'customer_name' => $appointment['customer_name_snapshot'],
                // Pre-fills the M-Pesa prompt number (staff can change it).
                'customer_phone' => $appointment['customer_phone_snapshot'],
            ],
            'booked_items' => $this->till->bookedItems($tenant->tenantId, (int) $appointment['id']),
            'sale_items' => $this->till->saleItems($tenant->tenantId, (int) $appointment['id']),
            'payments' => $this->till->payments($tenant->tenantId, (int) $appointment['id']),
            'refunds' => $this->till->refunds($tenant->tenantId, (int) $appointment['id']),
            'totals' => $totals + [
                'balance_minor' => $totals['total_minor'] - $totals['paid_minor'],
            ],
            'pickers' => $this->till->pickerOptions($tenant->tenantId),
            'methods' => $this->till->paymentMethods(),
            'full_payment_required' => $this->till->fullPaymentRequired($tenant->tenantId),
            'service_charge_bp' => $this->till->serviceChargeBp($tenant->tenantId),
            'receipt_url' => '/appointments/' . $appointment['uuid'] . '/receipt',
            'open' => in_array($appointment['status'], self::OPEN_STATUSES, true),
            'can' => [
                'record' => $membership->can('payments.record'),
                'refund' => $membership->can('payments.refund'),
                'discount' => $membership->can('payments.discount'),
                'checkout' => $membership->can('appointments.update'),
            ],
        ];
    }

    /** Build a validated sale-item line. Returns the line or
     * ['error' => fields].
     * @param array<string, mixed> $body
     * @return array<string, mixed>|array{error: array<string, string>}
     */
    private function buildLine(int $tenantId, string $kind, array $body): array
    {
        $quantity = (int) ($body['quantity'] ?? 1);
        if ($quantity < 1 || $quantity > 999) {
            return ['error' => ['quantity' => 'Quantity must be 1-999.']];
        }

        if ($kind === 'service' || $kind === 'product') {
            $id = (int) ($body['ref_id'] ?? 0);
            $source = $kind === 'service'
                ? $this->till->service($tenantId, $id)
                : $this->till->product($tenantId, $id);
            if ($source === null) {
                return ['error' => ['ref_id' => 'Item not found or inactive.']];
            }

            $unit = $kind === 'service'
                ? (int) ($source['price_amount_minor'] ?? $source['min_amount_minor'] ?? 0)
                : (int) $source['price_minor'];
            $taxBp = $kind === 'service'
                ? $this->till->tenantTaxRateBp($tenantId)
                : (int) $source['tax_rate_bp'];
            // Prices are tax-inclusive: the quoted unit already contains
            // VAT and service charge. Store only the VAT portion per unit
            // for line-level records; totals re-extract authoritatively.
            $taxPerUnit = $this->till->extractLevies($unit, $taxBp, $this->till->serviceChargeBp($tenantId))['vat_minor'];

            return [
                'kind' => $kind,
                'service_id' => $kind === 'service' ? $id : null,
                'product_id' => $kind === 'product' ? $id : null,
                'label' => $kind === 'service' ? $source['name'] : $source['name'],
                'quantity' => $quantity,
                'unit_amount_minor' => $unit,
                'rate_bp' => null,
                'tax_rate_bp' => $taxBp,
                'tax_amount_minor' => $taxPerUnit,
                'total_minor' => $unit * $quantity,
            ];
        }

        // discount | tip — free-form amounts.
        $amount = self::parseAmount($body['amount_minor'] ?? null);
        $label = trim((string) ($body['label'] ?? ''));
        if ($amount === null) {
            return ['error' => ['amount_minor' => 'Enter an amount greater than zero.']];
        }
        if ($label === '' || mb_strlen($label) > 150) {
            return ['error' => ['label' => $kind === 'discount' ? 'A discount reason is required.' : 'A label is required.']];
        }

        return [
            'kind' => $kind,
            'service_id' => null,
            'product_id' => null,
            'label' => $label,
            'quantity' => 1,
            'unit_amount_minor' => (int) $amount,
            'rate_bp' => null,
            'tax_rate_bp' => 0,
            'tax_amount_minor' => 0,
            'total_minor' => $kind === 'discount' ? -(int) $amount : (int) $amount,
        ];
    }

    /** Receipt notification + outbox event on completion.
     * @param array<string, mixed> $appointment
     * @param array{subtotal_minor: int, discount_minor: int, service_charge_minor: int,
     *               tax_minor: int, net_minor: int, tip_minor: int, total_minor: int,
     *               paid_minor: int, refunded_minor: int, balance_minor: int,
     *               tax_breakdown: list<array{rate_bp: int, taxable_minor: int, vat_minor: int}>} $totals
     */
    private function emitNotifications(int $tenantId, array $appointment, array $totals): void
    {
        $eventPayload = json_encode([
            'event' => 'payment_update',
            'appointment_uuid' => $appointment['uuid'],
            'reference' => $appointment['reference'],
            'total_minor' => $totals['total_minor'],
            'paid_minor' => $totals['paid_minor'],
        ], JSON_THROW_ON_ERROR);

        $phone = trim((string) ($appointment['customer_phone_snapshot'] ?? ''));
        $email = trim((string) ($appointment['customer_email_snapshot'] ?? ''));

        // Phone recipients go to SMS and/or WhatsApp per the business's setting.
        foreach ($this->notificationPolicy->phoneChannels($tenantId) as $phoneChannel) {
            if ($phone !== '' && $this->notificationPolicy->allows($tenantId, $phoneChannel, 'payment_update')) {
                $this->till->enqueueReceipt($tenantId, $this->uuids->generate(), (int) $appointment['id'], $phoneChannel, $phone, $eventPayload);
            }
        }
        if ($email !== '' && $this->notificationPolicy->allows($tenantId, 'email', 'payment_update')) {
            $this->till->enqueueReceipt($tenantId, $this->uuids->generate(), (int) $appointment['id'], 'email', $email, $eventPayload);
        }

        $this->till->outbox($tenantId, $this->uuids->generate(), 'payment.received', (int) $appointment['id'], $eventPayload);
    }

    /**
     * A new discount needs payments.discount (recording payments alone is
     * not enough — a till operator could otherwise zero a ticket after
     * taking cash) and may not exceed what is left of the subtotal.
     */
    private function assertDiscountAllowed(MembershipContext $membership, int $tenantId, int $appointmentId, int $amountMinor): void
    {
        if (!$membership->can('payments.discount')) {
            throw new TransitionException('FORBIDDEN', 'Discounts need manager approval.', 403);
        }
        $totals = $this->computeTotals($tenantId, $appointmentId);
        $room = $totals['subtotal_minor'] - $totals['discount_minor'];
        if ($amountMinor > $room) {
            throw new TransitionException(
                'DISCOUNT_EXCEEDS_TICKET',
                'The discount is larger than the remaining ticket.',
                422,
                ['amount_minor' => 'The discount cannot exceed the remaining subtotal.'],
            );
        }
    }

    /**
     * Strict money input: a positive whole number of minor units, at most
     * 12 digits (≈ KES 10 billion). is_numeric() + (int) used to accept
     * "1e20" (saturates to PHP_INT_MAX, then overflows totals → 500),
     * "12.5" (silently truncated) and other non-integers.
     */
    private static function parseAmount(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 && $value <= 999_999_999_999 ? $value : null;
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,11}$/', trim($value)) !== 1) {
            return null;
        }

        return (int) trim($value);
    }

    /** Invariant after any line change: discounts never exceed the subtotal. */
    private function assertDiscountsWithinSubtotal(int $tenantId, int $appointmentId): void
    {
        $totals = $this->computeTotals($tenantId, $appointmentId);
        if ($totals['discount_minor'] > $totals['subtotal_minor']) {
            throw new TransitionException(
                'DISCOUNT_EXCEEDS_TICKET',
                'That change would leave discounts larger than the ticket.',
                422,
                ['quantity' => 'Reduce or remove the discount first.'],
            );
        }
    }

    /** Branch-limited members can only touch tickets at their own branches.
     * @param array<string, mixed> $appointment
     */
    private function assertBranch(MembershipContext $membership, array $appointment): void
    {
        if (!$membership->canAccessBranch((int) $appointment['branch_id'])) {
            throw new TransitionException('FORBIDDEN', 'You do not have access to this branch.', 403);
        }
    }

    /** @param array<string, mixed> $appointment */
    private function assertOpen(array $appointment): void
    {
        if (!in_array($appointment['status'], self::OPEN_STATUSES, true)) {
            throw new TransitionException('TICKET_CLOSED', 'This ticket is already closed.', 409);
        }
    }

    /** @return array{TenantContext, MembershipContext} */
    private function context(Request $request): array
    {
        return [$request->attribute('tenant'), $request->attribute('membership')];
    }
}
