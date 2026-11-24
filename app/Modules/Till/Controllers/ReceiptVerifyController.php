<?php

declare(strict_types=1);

namespace App\Modules\Till\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Till\Repositories\TillRepository;
use App\Modules\Till\Support\ReceiptVerifier;
use App\Shared\Http\AppUrl;
use App\Shared\Money\CurrencyFormatter;
use App\Shared\Money\Money;
use App\Shared\Security\RateLimiter;

/**
 * Public receipt verification — albabook.co.ke/verify/{REFERENCE-SIGNATURE},
 * the target of the receipt QR code. Confirms a receipt is genuine and shows
 * only what proves it: business, receipt number, date, amount, payment
 * status. Never the customer's name or contact details.
 *
 * Unforgeable (HMAC signature, ReceiptVerifier) and rate-limited per IP, so
 * receipt numbers cannot be enumerated. Invalid and unknown codes get the
 * same response (no oracle).
 */
final class ReceiptVerifyController
{
    private const LIMIT = 30;
    private const WINDOW_SECONDS = 600;

    public function __construct(
        private readonly View $view,
        private readonly TillRepository $till,
        private readonly ReceiptVerifier $verifier,
        private readonly RateLimiter $limiter,
        private readonly CurrencyFormatter $money,
        private readonly AppUrl $urls,
    ) {}

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $limit = $this->limiter->hit(
            'receipt-verify:ip:' . RateLimiter::clientKey($request->clientIp() ?: 'unknown'),
            self::LIMIT,
            self::WINDOW_SECONDS,
        );
        if (!$limit->allowed) {
            return $this->page($request, null, 429)->withHeader('Retry-After', (string) $limit->retryAfterSeconds);
        }

        $receipt = null;
        $parsed = ReceiptVerifier::parse((string) ($params['code'] ?? ''));
        if ($parsed !== null) {
            [$reference, $signature] = $parsed;
            foreach ($this->till->appointmentsByReference($reference) as $row) {
                if ($this->verifier->matches((int) $row['tenant_id'], (int) $row['id'], $reference, $signature)) {
                    $receipt = $this->summary((int) $row['tenant_id'], (int) $row['id']);
                    break;
                }
            }
        }

        return $this->page($request, $receipt, $receipt === null ? 404 : 200)
            // Verification results must not be cached or indexed.
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /** @return array<string, string>|null */
    private function summary(int $tenantId, int $appointmentId): ?array
    {
        $context = $this->till->receiptContext($tenantId, $appointmentId);
        if ($context === null) {
            return null;
        }
        $totals = $this->till->computeTotals($tenantId, $appointmentId);
        try {
            $tz = new \DateTimeZone((string) ($context['timezone'] ?: 'UTC'));
        } catch (\Exception) {
            $tz = new \DateTimeZone('UTC');
        }
        $when = (new \DateTimeImmutable((string) $context['start_at'], new \DateTimeZone('UTC')))->setTimezone($tz);
        $status = (string) ($context['payment_status'] ?? 'unpaid');

        return [
            'business' => (string) $context['tenant_name'],
            'branch' => (string) $context['branch_name'],
            'reference' => (string) $context['reference'],
            'date' => $when->format('j M Y, h:i A'),
            'amount' => $this->money->format(new Money((int) $totals['total_minor'], (string) $context['currency'])),
            'status' => ucfirst(str_replace('_', ' ', $status)),
            'paid' => $status === 'paid' ? '1' : '',
        ];
    }

    /** @param array<string, string>|null $receipt */
    private function page(Request $request, ?array $receipt, int $status): Response
    {
        return $this->view->response('marketing/verify.twig', [
            'receipt' => $receipt,
            'rate_limited' => $status === 429,
            'active_nav' => '',
            'signup_url' => $this->urls->app('/register', $request),
            'login_url' => $this->urls->app('/login', $request),
        ], $status);
    }
}
