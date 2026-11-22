<?php

declare(strict_types=1);

namespace App\Modules\Till\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Auth\Context\MembershipContext;
use App\Modules\Till\Repositories\TillRepository;
use App\Shared\Money\CurrencyFormatter;
use App\Shared\Money\Money;
use App\Tenancy\TenantContext;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;

/**
 * Printable customer receipt for a ticket (the appointment). Matches the
 * salon receipt design: business header, item table, tax/charge breakdown,
 * tenders and a verification QR.
 */
final class ReceiptPageController
{
    public function __construct(
        private readonly View $view,
        private readonly TillRepository $till,
        private readonly CurrencyFormatter $money,
    ) {}

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $tenant = $request->attribute('tenant');
        $membership = $request->attribute('membership');
        if (!$tenant instanceof TenantContext || !$membership instanceof MembershipContext) {
            throw new HttpException(401, 'UNAUTHENTICATED', 'Sign in to continue.');
        }
        if (!$membership->can('payments.view')) {
            throw new HttpException(403, 'FORBIDDEN', 'You do not have permission to view receipts.');
        }

        $appointment = $this->till->appointment($tenant->tenantId, (string) $params['uuid']);
        if ($appointment === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Receipt not found.');
        }
        $context = $this->till->receiptContext($tenant->tenantId, (int) $appointment['id']);
        if ($context === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Receipt not found.');
        }

        $currency = (string) $appointment['currency'];
        $fmt = fn(int $minor): string => $this->money->format(new Money($minor, $currency));

        $booked = $this->till->bookedItems($tenant->tenantId, (int) $appointment['id']);
        $sale = $this->till->saleItems($tenant->tenantId, (int) $appointment['id']);
        $payments = $this->till->payments($tenant->tenantId, (int) $appointment['id']);
        $totals = $this->till->computeTotals($tenant->tenantId, (int) $appointment['id']);

        $items = [];
        $pos = 1;
        $itemsCount = 0;
        foreach ($booked as $item) {
            $qty = (int) $item['quantity'];
            $items[] = [
                'no' => $pos++,
                'label' => (string) $item['label'],
                'qty' => $qty,
                'unit' => $fmt((int) $item['unit_amount_minor']),
                'amount' => $fmt((int) $item['unit_amount_minor'] * $qty),
            ];
            $itemsCount++;
        }
        foreach ($sale as $item) {
            $items[] = [
                'no' => $pos++,
                'label' => (string) $item['label'] . ($item['kind'] === 'discount' ? ' (discount)' : ($item['kind'] === 'tip' ? ' (tip)' : '')),
                'qty' => (int) $item['quantity'],
                'unit' => $fmt((int) $item['unit_amount_minor']),
                'amount' => ((int) $item['total_minor'] < 0 ? '−' : '') . $fmt(abs((int) $item['total_minor'])),
            ];
            if ($item['kind'] === 'service' || $item['kind'] === 'product') {
                $itemsCount++;
            }
        }

        $tenders = array_map(fn(array $p): array => [
            'method' => str_replace('_', ' ', (string) $p['method']),
            'reference' => $p['provider_reference'],
            'amount' => $fmt((int) $p['amount_minor']),
        ], array_values(array_filter($payments, static fn(array $p): bool => $p['status'] === 'succeeded')));

        $receiptUrl = ($request->isSecure() ? 'https' : 'http') . '://' . $request->host()
            . '/appointments/' . $appointment['uuid'] . '/receipt';
        $qr = (new SvgWriter())->write(QrCode::create($receiptUrl)->setSize(160)->setMargin(0));

        return $this->view->response('till/receipt.twig', [
            'tenant' => $tenant,
            'membership' => $membership,
            'receipt' => $context,
            'items' => $items,
            'tenders' => $tenders,
            'totals' => $totals,
            'items_count' => $itemsCount,
            'tax_rows' => array_map(fn(array $row): array => [
                'rate' => $row['rate_bp'] > 0 ? number_format($row['rate_bp'] / 100, 2) . '%' : null,
                'taxable' => $fmt($row['taxable_minor']),
                'vat' => $fmt($row['vat_minor']),
            ], $totals['tax_breakdown']),
            'totals_label' => [
                'subtotal' => $fmt($totals['subtotal_minor']),
                'discount' => $fmt($totals['discount_minor']),
                'payable' => $fmt($totals['subtotal_minor'] - $totals['discount_minor']),
                'service_charge' => $fmt($totals['service_charge_minor']),
                'tax' => $fmt($totals['tax_minor']),
                'tips' => $fmt($totals['tip_minor']),
                'total' => $fmt($totals['total_minor']),
                'paid' => $fmt($totals['paid_minor']),
                'balance' => $fmt(max(0, $totals['balance_minor'])),
            ],
            'service_charge_bp' => $this->till->serviceChargeBp($tenant->tenantId),
            'tax_rate_bp' => (int) $context['tax_rate_bp'],
            'footer_note' => $this->till->receiptFooterNote($tenant->tenantId),
            'qr_svg_b64' => base64_encode($qr->getString()),
        ]);
    }
}
