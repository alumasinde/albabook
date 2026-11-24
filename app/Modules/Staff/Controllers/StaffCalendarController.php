<?php

declare(strict_types=1);

namespace App\Modules\Staff\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Staff\Repositories\StaffRepository;
use App\Shared\Calendar\IcsDocument;
use App\Shared\Http\ApiResponse;
use App\Shared\Time\DateTimeService;

/**
 * Public per-staff .ics calendar feed (api host): Google/Outlook subscribe to
 * /v1/staff-calendar/{token}.ics and poll it on their own schedule. The token
 * is the capability — unguessable, stored only as SHA-256, revocable from the
 * staff drawer — matching the manage-token posture on bookings (D-008).
 */
final class StaffCalendarController
{
    private const WINDOW_DAYS = 90;
    private const PAST_DAYS = 14;

    public function __construct(
        private readonly StaffRepository $staff,
        private readonly DateTimeService $dates,
    ) {}

    /** @param array<string, string> $params */
    public function feed(Request $request, array $params): Response
    {
        $token = trim((string) ($params['token'] ?? ''));
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            return ApiResponse::error('NOT_FOUND', 'Calendar feed not found.', 404);
        }

        $member = $this->staff->findByCalendarTokenHash(hash('sha256', $token));
        if ($member === null) {
            return ApiResponse::error('NOT_FOUND', 'Calendar feed not found.', 404);
        }

        $tenantId = (int) $member['tenant_id'];
        $now = $this->dates->now();
        $rows = $this->staff->feedAppointments(
            $tenantId,
            (int) $member['id'],
            $this->dates->toDb($now->modify('-' . self::PAST_DAYS . ' days')),
            $this->dates->toDb($now->modify('+' . self::WINDOW_DAYS . ' days')),
        );

        $ics = new IcsDocument();
        foreach ($rows as $row) {
            $location = trim(implode(', ', array_filter([
                (string) ($row['branch_name'] ?? ''),
                (string) ($row['address_line1'] ?? ''),
                (string) ($row['town'] ?? ''),
            ])));
            $ics->addEvent([
                'uid' => (string) $row['uuid'] . '@albabook',
                'start' => (string) $row['start_at'],
                'end' => (string) $row['end_at'],
                'summary' => (string) ($row['service_names'] ?: 'Appointment')
                    . ' — ' . (string) $row['customer_name_snapshot'],
                'location' => $location,
                'description' => 'Booking ' . (string) $row['reference'] . ' — ' . (string) $row['customer_name_snapshot'],
            ]);
        }

        return new Response($ics->render(), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            // Calendar apps poll on their own schedule; short cache keeps
            // changes reasonably fresh without hammering the app.
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
