<?php

declare(strict_types=1);

namespace App\Modules\Till\Controllers;

use App\Core\Http\HostGroup;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Auth\Context\MembershipContext;
use App\Modules\Billing\Services\FeatureGate;
use App\Modules\Till\Repositories\TillRepository;
use App\Modules\Till\Support\ReceiptSettings;
use App\Modules\Till\Support\ReceiptVerifier;
use App\Shared\Http\AppUrl;
use App\Shared\Money\CurrencyFormatter;
use App\Shared\Money\Money;
use App\Shared\Validation\PhoneNumber;
use App\Tenancy\TenantContext;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;

/**
 * Printable customer receipt for a ticket (the appointment), laid out as an
 * 80/58 mm thermal receipt: business header, "PAYMENT RECEIPT" bar, receipt
 * facts, item table, inclusive levies, a total bar, tenders with masked
 * references, the payment status, and a QR that verifies the receipt on the
 * public site. Every element is configurable per business (ReceiptSettings).
 */
final class ReceiptPageController
{
    /** Bookings' payment_status → receipt badge. */
    private const PAYMENT_STATUS = [
        'paid' => ['label' => 'Paid', 'tone' => 'paid'],
        'partially_paid' => ['label' => 'Part paid', 'tone' => 'part'],
        'unpaid' => ['label' => 'Unpaid', 'tone' => 'due'],
        'refunded' => ['label' => 'Refunded', 'tone' => 'muted'],
        'partially_refunded' => ['label' => 'Part refunded', 'tone' => 'muted'],
        'not_required' => ['label' => 'No charge', 'tone' => 'muted'],
    ];

    public function __construct(
        private readonly View $view,
        private readonly TillRepository $till,
        private readonly CurrencyFormatter $money,
        private readonly FeatureGate $features,
        private readonly ReceiptVerifier $verifier,
        private readonly AppUrl $urls,
    ) {}

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request, 'payments.view');

        $appointment = $this->till->appointment($tenant->tenantId, (string) $params['uuid']);
        if ($appointment === null || !$membership->canAccessBranch((int) $appointment['branch_id'])) {
            throw new HttpException(404, 'NOT_FOUND', 'Receipt not found.');
        }
        $context = $this->till->receiptContext($tenant->tenantId, (int) $appointment['id']);
        if ($context === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Receipt not found.');
        }

        $appointmentId = (int) $appointment['id'];
        $currency = (string) $appointment['currency'];
        $fmt = fn(int $minor): string => $this->money->format(new Money($minor, $currency), true); // receipts always show cents

        $items = [];
        $services = [];
        foreach ($this->till->bookedItems($tenant->tenantId, $appointmentId) as $item) {
            $qty = (int) $item['quantity'];
            $items[] = ['label' => (string) $item['label'], 'qty' => $qty, 'amount' => $fmt((int) $item['unit_amount_minor'] * $qty), 'negative' => false];
            $services[] = (string) $item['label'];
        }
        foreach ($this->till->saleItems($tenant->tenantId, $appointmentId) as $item) {
            $total = (int) $item['total_minor'];
            $suffix = match ($item['kind']) { 'discount' => ' (discount)', 'tip' => ' (tip)', default => '' };
            $items[] = [
                'label' => (string) $item['label'] . $suffix,
                'qty' => (int) $item['quantity'],
                'amount' => ($total < 0 ? '−' : '') . $fmt(abs($total)),
                'negative' => $total < 0,
            ];
            if ($item['kind'] === 'service') {
                $services[] = (string) $item['label'];
            }
        }

        $methods = array_column($this->till->paymentMethods(), 'name', 'code');
        $settings = $this->settings($tenant->tenantId);
        $tenders = [];
        foreach ($this->till->payments($tenant->tenantId, $appointmentId) as $p) {
            if ($p['status'] !== 'succeeded') {
                continue;
            }
            $method = (string) $p['method'];
            $reference = $p['provider_reference'] !== null ? (string) $p['provider_reference'] : '';
            $tenders[] = [
                'method' => $methods[$method] ?? ucwords(str_replace('_', ' ', $method)),
                'reference_label' => ($methods[$method] ?? 'Payment') . ' reference',
                'reference' => $reference === '' ? null
                    : ($settings['mask_references'] ? ReceiptSettings::maskReference($reference) : $reference),
                'amount' => $fmt((int) $p['amount_minor']),
            ];
        }

        $totals = $this->till->computeTotals($tenant->tenantId, $appointmentId);
        $verifyCode = $this->verifier->code($tenant->tenantId, $appointmentId, (string) $context['reference']);

        return $this->render($request, $tenant, $context, $settings, [
            'items' => $items,
            'service_summary' => count($services) === 1 ? $services[0] : (count($services) > 1 ? count($services) . ' services' : null),
            'tenders' => $tenders,
            'totals' => $totals,
            'currency_fmt' => $fmt,
            'payment_status' => (string) ($context['payment_status'] ?? 'unpaid'),
            'verify_code' => $verifyCode,
            'when' => (string) $context['start_at'],
            'back_url' => '/appointments',
            'is_preview' => false,
        ]);
    }

    /**
     * Sample receipt with this business's saved design (Settings → Receipt).
     * @param array<string, string> $params
     */
    public function preview(Request $request, array $params): Response
    {
        [$tenant] = $this->context($request, 'settings.view');
        $header = $this->till->previewHeader($tenant->tenantId) ?? ['tenant_name' => $tenant->name, 'kra_pin' => null, 'tax_rate_bp' => 1600];
        $header += [
            'reference' => '2T3FBSBA',
            'customer_name_snapshot' => 'Test Customer',
            'staff_name' => 'Amina O.',
            'timezone' => 'Africa/Nairobi',
        ];
        $currency = $tenant->currency ?: 'KES';
        $fmt = fn(int $minor): string => $this->money->format(new Money($minor, $currency), true); // receipts always show cents
        $settings = $this->settings($tenant->tenantId);

        // 3,500.00 inclusive of 16% VAT and 5% service charge.
        $gross = 350000;
        $taxBp = (int) ($header['tax_rate_bp'] ?? 1600);
        $scBp = $this->till->serviceChargeBp($tenant->tenantId);
        $levies = $this->till->extractLevies($gross, $taxBp, $scBp);

        return $this->render($request, $tenant, $header, $settings, [
            'items' => [['label' => 'Deep Tissue Massage', 'qty' => 1, 'amount' => $fmt($gross), 'negative' => false]],
            'service_summary' => 'Deep Tissue Massage',
            'tenders' => [[
                'method' => 'M-Pesa',
                'reference_label' => 'M-Pesa reference',
                'reference' => $settings['mask_references'] ? ReceiptSettings::maskReference('RYH3KX9PD4') : 'RYH3KX9PD4',
                'amount' => $fmt($gross),
            ]],
            'totals' => [
                'subtotal_minor' => $gross, 'discount_minor' => 0, 'tip_minor' => 0,
                'tax_minor' => $levies['vat_minor'], 'service_charge_minor' => $levies['sc_minor'],
                'total_minor' => $gross, 'paid_minor' => $gross, 'balance_minor' => 0,
            ],
            'currency_fmt' => $fmt,
            'payment_status' => 'paid',
            'verify_code' => '2T3FBSBA-SAMPLE00',
            'when' => gmdate('Y-m-d H:i:s'),
            'back_url' => '/settings',
            'is_preview' => true,
        ]);
    }

    /**
     * @param array<string, mixed> $header
     * @param array<string, bool|string> $settings
     * @param array<string, mixed> $data
     */
    private function render(Request $request, TenantContext $tenant, array $header, array $settings, array $data): Response
    {
        /** @var callable(int): string $fmt */
        $fmt = $data['currency_fmt'];
        $totals = $data['totals'];
        $balance = max(0, (int) $totals['total_minor'] - (int) $totals['paid_minor']);

        try {
            $tz = new \DateTimeZone((string) ($header['timezone'] ?? 'UTC') ?: 'UTC');
        } catch (\Exception) {
            $tz = new \DateTimeZone('UTC');
        }
        $when = (new \DateTimeImmutable($data['when'], new \DateTimeZone('UTC')))->setTimezone($tz);

        $verifyUrl = $this->urls->to(HostGroup::Marketing, '/verify/' . $data['verify_code'], $request);
        $qr = $settings['show_qr']
            ? base64_encode((new SvgWriter())->write(QrCode::create($verifyUrl)->setSize(150)->setMargin(0))->getString())
            : null;

        $taxBp = (int) ($header['tax_rate_bp'] ?? 0);
        $scBp = $this->till->serviceChargeBp($tenant->tenantId);
        $status = self::PAYMENT_STATUS[$data['payment_status']] ?? self::PAYMENT_STATUS['unpaid'];
        $name = (string) ($header['tenant_name'] ?? $tenant->name);

        return $this->view->response('till/receipt.twig', [
            'r' => $header,
            'settings' => $settings,
            'logo_initial' => mb_strtoupper(mb_substr($name, 0, 1)),
            'business_name' => $name,
            'phone' => self::displayPhone((string) ($header['branch_phone'] ?? '')),
            'address' => implode(', ', array_filter([
                $header['address_line1'] ?? null, $header['address_line2'] ?? null, $header['town'] ?? null, $header['county'] ?? null,
            ])),
            'date' => $when->format('j M Y'),
            'time' => $when->format('h:i A'),
            'items' => $data['items'],
            'service_summary' => $data['service_summary'],
            'tenders' => $data['tenders'],
            'lines' => [
                'subtotal' => $fmt((int) $totals['subtotal_minor']),
                'discount' => (int) $totals['discount_minor'] > 0 ? $fmt((int) $totals['discount_minor']) : null,
                'vat' => $settings['show_levies'] && (int) $totals['tax_minor'] > 0 ? $fmt((int) $totals['tax_minor']) : null,
                'vat_rate' => rtrim(rtrim(number_format($taxBp / 100, 2), '0'), '.'),
                'service_charge' => $settings['show_levies'] && (int) $totals['service_charge_minor'] > 0 ? $fmt((int) $totals['service_charge_minor']) : null,
                'service_charge_rate' => rtrim(rtrim(number_format($scBp / 100, 2), '0'), '.'),
                'tips' => (int) $totals['tip_minor'] > 0 ? $fmt((int) $totals['tip_minor']) : null,
                'total' => $fmt((int) $totals['total_minor']),
                'paid' => $fmt((int) $totals['paid_minor']),
                'balance' => $balance > 0 ? $fmt($balance) : null,
            ],
            // "TOTAL PAID" only when it is fully settled; otherwise "TOTAL".
            'fully_paid' => $balance === 0 && (int) $totals['paid_minor'] > 0,
            'status' => $status,
            'qr_svg_b64' => $qr,
            'verify_url' => $verifyUrl,
            'verify_display' => preg_replace('#^https?://#', '', $verifyUrl),
            'footer_note' => $this->till->receiptFooterNote($tenant->tenantId),
            'show_powered_by' => $settings['show_powered_by'] || !$this->features->enabled($tenant->tenantId, 'white_label'),
            'back_url' => $data['back_url'],
            'is_preview' => $data['is_preview'],
        ]);
    }

    /** "0772717461" → "+254 772 717 461"; other numbers keep their E.164 form. */
    private static function displayPhone(string $raw): string
    {
        $e164 = PhoneNumber::normalise($raw);
        if ($e164 === null) {
            return trim($raw);
        }
        if (preg_match('/^\+254(\d{3})(\d{3})(\d{3})$/', $e164, $m) === 1) {
            return "+254 {$m[1]} {$m[2]} {$m[3]}";
        }

        return $e164;
    }

    /** @return array<string, bool|string> */
    private function settings(int $tenantId): array
    {
        return ReceiptSettings::fromStored($this->till->receiptSettingsRaw($tenantId));
    }

    /** @return array{0: TenantContext, 1: MembershipContext} */
    private function context(Request $request, string $permission): array
    {
        $tenant = $request->attribute('tenant');
        $membership = $request->attribute('membership');
        if (!$tenant instanceof TenantContext || !$membership instanceof MembershipContext) {
            throw new HttpException(401, 'UNAUTHENTICATED', 'Sign in to continue.');
        }
        if (!$membership->can($permission)) {
            throw new HttpException(403, 'FORBIDDEN', 'You do not have permission to view receipts.');
        }

        return [$tenant, $membership];
    }
}
