<?php

declare(strict_types=1);

namespace App\Modules\Reports\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Auth\Context\MembershipContext;
use App\Modules\Reports\Repositories\ReportRepository;
use App\Shared\Http\ApiResponse;
use App\Tenancy\TenantContext;

/**
 * Private JSON API for reports (session-authenticated, D-008).
 * reports.view for metrics; financial blocks additionally require
 * reports.view_financial; CSV export requires reports.export.
 */
final class ReportsApiController
{
    public function __construct(private readonly ReportRepository $reports) {}

    /** @param array<string, string> $params */
    public function summary(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('reports.view')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to view reports.', 403);
        }

        $range = $this->range($request);
        if ($range === null) {
            return ApiResponse::error('VALIDATION', 'Choose a valid date range.', 422, ['range' => 'Invalid date range.']);
        }
        [$from, $to, $toLabel] = $range;

        $data = [
            'from' => substr($from, 0, 10),
            'to' => $toLabel,
            'summary' => $this->reports->summary($tenant->tenantId, $from, $to),
            'daily' => $this->reports->daily($tenant->tenantId, $from, $to),
            'top_services' => $this->reports->topServices($tenant->tenantId, $from, $to),
            'appointments_by_day' => $this->reports->appointmentsByDay($tenant->tenantId, $from, $to),
            'by_service' => $this->reports->byService($tenant->tenantId, $from, $to),
            'noshows_by_service' => $this->reports->noShowsByService($tenant->tenantId, $from, $to),
            'resource_utilization' => $this->reports->resourceUtilization($tenant->tenantId, $from, $to),
            'currency' => $this->reports->currency($tenant->tenantId),
        ];
        if ($membership->can('reports.view_financial')) {
            $data['financial'] = $this->reports->financial($tenant->tenantId, $from, $to);
            $data['sales_by_day'] = $this->reports->salesByDay($tenant->tenantId, $from, $to);
        }

        return ApiResponse::ok($data);
    }

    /** CSV export of the appointments in the window (reports.export).
     * @param array<string, string> $params
     */
    public function export(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('reports.export')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to export reports.', 403);
        }

        $range = $this->range($request);
        if ($range === null) {
            return ApiResponse::error('VALIDATION', 'Choose a valid date range.', 422, ['range' => 'Invalid date range.']);
        }
        [$from, $to, $toLabel] = $range;

        $type = (string) $request->query('type', 'appointments');
        if ($type !== 'appointments' && !$membership->can('reports.view_financial')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to export this report.', 403);
        }

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return ApiResponse::error('SERVER_ERROR', 'Could not build the export.', 500);
        }
        if ($type === 'sales') {
            fputcsv($stream, ['Day', 'Payments', 'Gross (minor)', 'Refunded (minor)', 'Net (minor)']);
            foreach ($this->reports->salesByDay($tenant->tenantId, $from, $to) as $row) {
                fputcsv($stream, [
                    $row['day'], (int) $row['payments_count'], (int) $row['gross_minor'],
                    (int) $row['refunded_minor'], (int) $row['gross_minor'] - (int) $row['refunded_minor'],
                ]);
            }
        } else {
            $type = 'appointments';
            fputcsv($stream, ['Reference', 'Start', 'End', 'Status', 'Payment', 'Customer', 'Branch', 'Total (minor)', 'Currency']);
            foreach ($this->reports->exportRows($tenant->tenantId, $from, $to) as $row) {
                fputcsv($stream, [
                    $row['reference'], $row['start_at'], $row['end_at'], $row['status'],
                    $row['payment_status'], $row['customer'], $row['branch'],
                    (int) $row['total_amount_minor'], $row['currency'],
                ]);
            }
        }
        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        $filename = 'albabook-' . $type . '-' . substr($from, 0, 10) . '-to-' . $toLabel . '.csv';

        return Response::text($csv)
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    /** @return array{TenantContext, MembershipContext} */
    private function context(Request $request): array
    {
        return [$request->attribute('tenant'), $request->attribute('membership')];
    }

    /** [from, to) UTC datetimes + the user-facing end date; null when invalid.
     * @return array{string, string, string}|null
     */
    private function range(Request $request): ?array
    {
        $from = trim((string) $request->query('from', ''));
        $to = trim((string) $request->query('to', ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            return null;
        }
        $fromDay = \DateTimeImmutable::createFromFormat('!Y-m-d', $from, new \DateTimeZone('UTC'));
        $toDay = \DateTimeImmutable::createFromFormat('!Y-m-d', $to, new \DateTimeZone('UTC'));
        if ($fromDay === false || $toDay === false || $toDay <= $fromDay) {
            return null;
        }
        // $to is inclusive in the UI — move to the exclusive upper bound.
        $toExclusive = $toDay->modify('+1 day');
        if ($fromDay->diff($toExclusive)->days > 370) {
            return null; // cap the window at ~a year
        }

        return [
            $fromDay->format('Y-m-d H:i:s'),
            $toExclusive->format('Y-m-d H:i:s'),
            $to,
        ];
    }
}
