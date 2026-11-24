<?php

declare(strict_types=1);

namespace App\Modules\Api\Controllers;

use App\Core\Config\Config;
use App\Core\Http\HostGroup;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Appointments\Repositories\AppointmentRepository;
use App\Modules\Appointments\Services\AppointmentTransitionService;
use App\Modules\Appointments\Services\ManualBookingService;
use App\Modules\Appointments\Services\TransitionException;
use App\Modules\Booking\Repositories\BookingHoldRepository;
use App\Modules\Booking\Repositories\BookingPageRepository;
use App\Modules\Booking\Services\AvailabilityService;
use App\Modules\Booking\Services\BookingHoldService;
use App\Modules\Notifications\Repositories\NotificationRepository;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\AppUrl;
use App\Shared\Security\RateLimiter;
use App\Shared\Time\DateTimeService;
use App\Shared\Validation\CustomerContact;
use App\Tenancy\TenantRepository;

/**
 * Public booking API (booking-engine.md §18, decisions.md D-008). Served on
 * the api host only. Every identifier crossing the boundary is a uuid, a
 * booking reference or a hashed-token capability — never an auto-increment
 * id. Unknown slugs and non-bookable tenants both answer BUSINESS_NOT_FOUND
 * so the endpoint cannot be used to enumerate businesses.
 */
final class PublicBookingApiController
{
    private const WRITE_RATE_LIMIT = 30;
    private const WRITE_RATE_WINDOW = 600;
    private const LOOKUP_RATE_LIMIT = 6;
    private const LOOKUP_RATE_WINDOW = 600;

    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly BookingPageRepository $pages,
        private readonly BookingHoldRepository $holds,
        private readonly BookingHoldService $holdService,
        private readonly AppointmentRepository $appointments,
        private readonly ManualBookingService $bookings,
        private readonly AppointmentTransitionService $transitions,
        private readonly AvailabilityService $availability,
        private readonly DateTimeService $dates,
        private readonly RateLimiter $limiter,
        private readonly Config $config,
        private readonly NotificationRepository $notifications,
    ) {}

    /** @param array<string, string> $params */
    public function business(Request $request, array $params): Response
    {
        $tenant = $this->resolveTenant($params);
        if ($tenant === null) {
            return ApiResponse::error('BUSINESS_NOT_FOUND', 'Business not found.', 404);
        }
        $settings = $this->pages->pageSettings((int) $tenant['id']) ?? [];

        return ApiResponse::ok([
            'name' => (string) $tenant['name'],
            'slug' => (string) $tenant['slug'],
            'timezone' => (string) $tenant['timezone'],
            'currency' => (string) $tenant['currency'],
            'locale' => (string) ($settings['default_locale'] ?? 'en'),
            'staff_selection' => (bool) ($settings['staff_selection'] ?? true),
            'branch_selection' => (bool) ($settings['branch_selection'] ?? true),
        ]);
    }

    /** @param array<string, string> $params */
    public function services(Request $request, array $params): Response
    {
        $tenant = $this->resolveTenant($params);
        if ($tenant === null) {
            return ApiResponse::error('BUSINESS_NOT_FOUND', 'Business not found.', 404);
        }

        $services = array_map(fn(array $s): array => [
            'uuid' => (string) $s['uuid'],
            'name' => (string) $s['name'],
            'description' => (string) ($s['description'] ?? ''),
            'category' => $s['category_name'] !== null ? (string) $s['category_name'] : null,
            'duration_minutes' => (int) $s['duration_minutes'],
            'price_type' => (string) $s['price_type'],
            'price_minor' => $s['price_amount_minor'] !== null ? (int) $s['price_amount_minor'] : null,
            'price_min_minor' => $s['min_amount_minor'] !== null ? (int) $s['min_amount_minor'] : null,
            'price_max_minor' => $s['max_amount_minor'] !== null ? (int) $s['max_amount_minor'] : null,
            'currency' => (string) $s['currency'],
        ], $this->pages->services((int) $tenant['id']));

        return ApiResponse::ok(['services' => $services]);
    }

    /** @param array<string, string> $params */
    public function branches(Request $request, array $params): Response
    {
        $tenant = $this->resolveTenant($params);
        if ($tenant === null) {
            return ApiResponse::error('BUSINESS_NOT_FOUND', 'Business not found.', 404);
        }

        $branches = array_map(fn(array $b): array => [
            'uuid' => (string) $b['uuid'],
            'name' => (string) $b['name'],
            'address' => trim(implode(', ', array_filter([
                (string) ($b['address_line1'] ?? ''),
                (string) ($b['address_line2'] ?? ''),
                (string) ($b['town'] ?? ''),
            ]))),
            'phone' => $b['phone'] !== null ? (string) $b['phone'] : null,
            'timezone' => (string) ($b['timezone'] ?: $tenant['timezone']),
        ], $this->pages->branches((int) $tenant['id']));

        return ApiResponse::ok(['branches' => $branches]);
    }

    /** @param array<string, string> $params */
    public function availabilityDays(Request $request, array $params): Response
    {
        $resolved = $this->resolveContext($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenant, $branch, $service, $staffId] = $resolved;
        $branchTz = (string) ($branch['timezone'] ?: $tenant['timezone']);

        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) !== 1 || $from > $to) {
            return ApiResponse::error('VALIDATION_FAILED', 'Pick a valid date range.', 422, ['from' => 'Valid Y-m-d range required.']);
        }
        $maxRange = $this->config->int('booking.availability_max_range_days', 62);
        $cursor = new \DateTimeImmutable($from, new \DateTimeZone('UTC'));
        $last = new \DateTimeImmutable($to, new \DateTimeZone('UTC'));
        if ((int) $cursor->diff($last)->format('%a') > $maxRange) {
            return ApiResponse::error('VALIDATION_FAILED', "Range may not exceed {$maxRange} days.", 422, ['to' => 'Range too large.']);
        }

        $days = [];
        while ($cursor <= $last) {
            $date = $cursor->format('Y-m-d');
            $days[] = [
                'date' => $date,
                'available' => $this->availability->slots(
                    (int) $tenant['id'], $branch, $service, $staffId, $date, $branchTz,
                ) !== [],
            ];
            $cursor = $cursor->modify('+1 day');
        }

        return ApiResponse::ok([
            'branch' => (string) $branch['uuid'],
            'timezone' => $branchTz,
            'days' => $days,
        ]);
    }

    /** @param array<string, string> $params */
    public function availabilitySlots(Request $request, array $params): Response
    {
        $resolved = $this->resolveContext($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenant, $branch, $service, $staffId] = $resolved;
        $branchTz = (string) ($branch['timezone'] ?: $tenant['timezone']);

        $date = (string) $request->query('date', '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return ApiResponse::error('VALIDATION_FAILED', 'Pick a valid date.', 422, ['date' => 'Valid Y-m-d required.']);
        }

        $staffUuids = [];
        foreach ($this->availability->candidates((int) $tenant['id'], (int) $service['id'], (int) $branch['id']) as $member) {
            $staffUuids[(int) $member['id']] = (string) ($member['uuid'] ?? '');
        }

        $slots = [];
        foreach ($this->availability->slots((int) $tenant['id'], $branch, $service, $staffId, $date, $branchTz) as $slot) {
            $startUtc = $this->dates->localToUtc($date, (string) $slot['start_time'], $branchTz);
            $slots[] = [
                'start' => (string) $slot['start_time'],
                'start_utc' => $startUtc !== null ? $startUtc->format('Y-m-d\TH:i:s\Z') : null,
                'end' => (string) $slot['end_time'],
                'staff_uuid' => $staffUuids[(int) $slot['staff_id']] ?? null,
            ];
        }

        return ApiResponse::ok([
            'branch' => (string) $branch['uuid'],
            'date' => $date,
            'timezone' => $branchTz,
            'slots' => $slots,
        ]);
    }

    /** @param array<string, string> $params */
    public function createHold(Request $request, array $params): Response
    {
        $resolved = $this->resolveWriteContext($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenant, $branch, $service, $staffId, $date, $time] = $resolved;

        // A hold reserves one staff member's window — "any" is not holdable.
        if ($staffId === null) {
            return ApiResponse::error('VALIDATION_FAILED', 'Staff is required to hold a slot.', 422, ['staff' => 'Choose a staff member.']);
        }

        try {
            $hold = $this->holdService->create(
                (int) $tenant['id'],
                $branch,
                $service,
                $staffId,
                $date,
                $time,
                hash('sha256', 'hold:' . $request->clientIp()),
            );
        } catch (TransitionException $e) {
            return $this->withAlternatives($e, (int) $tenant['id'], $branch, $service, $staffId, $date);
        }

        return ApiResponse::ok([
            'hold_token' => $hold['hold_token'],
            'expires_at' => $hold['expires_at'],
        ], 201);
    }

    /** @param array<string, string> $params */
    public function releaseHold(Request $request, array $params): Response
    {
        $tenant = $this->resolveTenant($params);
        if ($tenant === null) {
            return ApiResponse::error('BUSINESS_NOT_FOUND', 'Business not found.', 404);
        }
        $token = trim((string) ($params['holdToken'] ?? ''));
        if (strlen($token) !== 48 || !ctype_xdigit($token)) {
            return ApiResponse::error('VALIDATION_FAILED', 'Invalid hold token.', 422);
        }
        // Idempotent — released/expired/unknown tokens all answer ok.
        $this->holdService->release((int) $tenant['id'], $token);

        return ApiResponse::ok(['released' => true]);
    }

    /** @param array<string, string> $params */
    public function createBooking(Request $request, array $params): Response
    {
        $resolved = $this->resolveWriteContext($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenant, $branch, $service, $staffId, $date, $time] = $resolved;
        $branchTz = (string) ($branch['timezone'] ?: $tenant['timezone']);

        $body = $request->body();
        // `customer` must be an object; any other shape falls back to the
        // flat fields (a string here used to fatal on offset access).
        $c = is_array($body['customer'] ?? null) ? $body['customer'] : [];
        $field = static fn(string $k): string => is_scalar($c[$k] ?? null) ? (string) $c[$k]
            : (is_scalar($body[$k] ?? null) ? (string) $body[$k] : '');
        $customer = [
            'name' => trim($field('name')),
            'phone' => trim($field('phone')) ?: null,
            'email' => trim($field('email')) ?: null,
            'notes' => trim($field('notes')) ?: null,
        ];
        // Name, email format, phone (normalised to E.164) and lengths.
        $fields = CustomerContact::validate($customer, 'customer.');
        if ($fields !== []) {
            return ApiResponse::error('VALIDATION_FAILED', 'Check the highlighted fields.', 422, $fields);
        }

        $idempotencyKey = trim((string) ($request->header('Idempotency-Key') ?? $body['idempotency_key'] ?? ''));
        if ($idempotencyKey !== '' && !preg_match('/^[A-Za-z0-9_-]{8,64}$/', $idempotencyKey)) {
            return ApiResponse::error('VALIDATION_FAILED', 'Invalid idempotency key.', 422, ['idempotency_key' => 'Invalid key.']);
        }
        $holdToken = trim((string) ($body['hold_token'] ?? ''));
        if ($holdToken !== '' && (strlen($holdToken) !== 48 || !ctype_xdigit($holdToken))) {
            return ApiResponse::error('VALIDATION_FAILED', 'Invalid hold token.', 422, ['hold_token' => 'Invalid token.']);
        }

        // A hold pins its staff member (same rule as the booking page).
        if ($holdToken !== '') {
            $hold = $this->holds->findActiveByTokenHash(hash('sha256', $holdToken));
            if ($hold === null || (int) $hold['tenant_id'] !== (int) $tenant['id']) {
                return ApiResponse::error('HOLD_EXPIRED', 'Reservation expired.', 410, ['time' => 'Reservation expired. Pick the time again.']);
            }
            $holdItems = $this->holds->itemsFor((int) $tenant['id'], (int) $hold['id']);
            $holdStaffId = (int) ($holdItems[0]['staff_id'] ?? 0);
            if ($staffId === null) {
                $staffId = $holdStaffId;
            }
            if ($staffId !== $holdStaffId) {
                return ApiResponse::error('VALIDATION_FAILED', 'That reservation does not match this booking.', 422, ['hold_token' => 'The reservation is for a different slot.']);
            }
        }

        if ($staffId === null) {
            // Contract allows "any" — first available candidate wins.
            foreach ($this->availability->candidates((int) $tenant['id'], (int) $service['id'], (int) $branch['id']) as $member) {
                if ($this->availability->slotAvailableForStaff(
                    (int) $tenant['id'], $branch, $service, (int) $member['id'], $date, $time, $branchTz,
                )) {
                    $staffId = (int) $member['id'];
                    break;
                }
            }
            if ($staffId === null) {
                return ApiResponse::error('SLOT_UNAVAILABLE', 'That time is no longer available.', 409, ['time' => 'Pick another time.']);
            }
        } elseif ($holdToken === '' && !$this->availability->slotAvailableForStaff(
            (int) $tenant['id'], $branch, $service, $staffId, $date, $time, $branchTz,
        )) {
            // A named staff member without a hold must still be a real slot
            // on the published grid: minimum notice, slot interval, working
            // hours and breaks — the same rule the booking page applies.
            // (A hold was validated when it was taken.)
            return ApiResponse::error('SLOT_UNAVAILABLE', 'That time is not available.', 409, ['time' => 'Pick one of the available times.']);
        }

        try {
            $result = $this->bookings->create(
                (int) $tenant['id'],
                (int) $branch['id'],
                $branchTz,
                $staffId,
                (int) $service['id'],
                $date,
                $time,
                $customer,
                'api',
                in_array($body['locale'] ?? 'en', ['en', 'sw'], true) ? (string) ($body['locale'] ?? 'en') : 'en',
                (string) $tenant['currency'],
                null,
                'customer',
                false,
                null,
                $idempotencyKey !== '' ? $idempotencyKey : null,
                $holdToken !== '' ? $holdToken : null,
            );
        } catch (TransitionException $e) {
            return $this->withAlternatives($e, (int) $tenant['id'], $branch, $service, $staffId, $date);
        }

        return ApiResponse::ok([
            'uuid' => $result['uuid'],
            'reference' => $result['reference'],
            'status' => $result['status'],
            'start_at' => $result['start_at'],
            'end_at' => $result['end_at'],
            'existing' => $result['existing'],
            'manage_token' => $result['manage_token'],
            'manage_url' => $result['manage_token'] !== null ? $this->manageUrl($tenant, $result['manage_token'], $request) : null,
        ], $result['existing'] ? 200 : 201);
    }

    /**
     * Booking-lookup (§18): never reveals whether a reference/contact pair
     * matched — always 200. On a match a fresh manage token is rotated in
     * (the old one is hashed, unrecoverable) and the link is queued as a
     * manage_link notification to the booking's own stored contact.
     * @param array<string, string> $params
     */
    public function bookingLookup(Request $request, array $params): Response
    {
        $tenant = $this->resolveTenant($params);
        if ($tenant === null) {
            return ApiResponse::ok(['sent' => true]); // same response, no oracle
        }
        if (!$this->limiter->hit('api-lookup:' . $tenant['id'] . ':' . $request->clientIp(), self::LOOKUP_RATE_LIMIT, self::LOOKUP_RATE_WINDOW)->allowed) {
            return ApiResponse::error('RATE_LIMITED', 'Too many attempts. Try again shortly.', 429);
        }

        $body = $request->body();
        $reference = strtoupper(trim((string) ($body['reference'] ?? '')));
        $contact = mb_strtolower(trim((string) ($body['contact'] ?? '')));

        if (strlen($reference) === 8 && $contact !== '') {
            $appointment = $this->appointments->findByReference((int) $tenant['id'], $reference);
            if ($appointment !== null && $this->contactMatches($appointment, $contact)) {
                $token = bin2hex(random_bytes(24));
                $this->appointments->setManageToken((int) $tenant['id'], (int) $appointment['id'], hash('sha256', $token));
                $this->queueManageLink($appointment, $contact, $this->manageUrl($tenant, $token, $request));
            }
        }

        return ApiResponse::ok(['sent' => true]);
    }

    /** @param array<string, string> $params */
    public function bookingShow(Request $request, array $params): Response
    {
        $appointment = $this->resolveByToken($params);
        if ($appointment === null) {
            return ApiResponse::error('NOT_FOUND', 'Booking not found.', 404);
        }

        return ApiResponse::ok($this->bookingPayload($appointment));
    }

    /** @param array<string, string> $params */
    public function bookingCancel(Request $request, array $params): Response
    {
        $appointment = $this->resolveByToken($params);
        if ($appointment === null) {
            return ApiResponse::error('NOT_FOUND', 'Booking not found.', 404);
        }
        $tenantId = (int) $appointment['tenant_id'];

        if (!in_array((string) $appointment['status'], ['pending', 'confirmed'], true)) {
            return ApiResponse::error('POLICY_VIOLATION', 'This booking can no longer be cancelled.', 422, ['rule' => 'status']);
        }
        if ($this->cutoffPassed($tenantId, $appointment, 'cancellation_cutoff_minutes')) {
            return ApiResponse::error('POLICY_VIOLATION', 'The cancellation deadline has passed — contact the business.', 422, ['rule' => 'cancellation_cutoff_minutes']);
        }

        try {
            $row = $this->transitions->transition(
                $tenantId,
                (string) $appointment['uuid'],
                'cancelled',
                null,
                null,
                'customer',
                isset($request->body()['reason']) ? mb_substr(trim((string) $request->body()['reason']), 0, 255) : null,
            );
        } catch (TransitionException $e) {
            return ApiResponse::error($e->apiCode, $e->getMessage(), $e->status, $e->fields ?: null);
        }

        return ApiResponse::ok(['uuid' => (string) $row['uuid'], 'status' => (string) $row['status']]);
    }

    /** @param array<string, string> $params */
    public function bookingReschedule(Request $request, array $params): Response
    {
        $appointment = $this->resolveByToken($params);
        if ($appointment === null) {
            return ApiResponse::error('NOT_FOUND', 'Booking not found.', 404);
        }
        $tenantId = (int) $appointment['tenant_id'];

        $body = $request->body();
        $date = (string) ($body['date'] ?? '');
        $time = (string) ($body['time'] ?? '');
        $fields = [];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            $fields['date'] = 'Valid Y-m-d required.';
        }
        if (preg_match('/^\d{2}:\d{2}$/', $time) !== 1) {
            $fields['time'] = 'Valid H:i required.';
        }
        if ($fields !== []) {
            return ApiResponse::error('VALIDATION_FAILED', 'Check the highlighted fields.', 422, $fields);
        }

        $staffId = null;
        if (isset($body['staff']) && (string) $body['staff'] !== '') {
            $staff = $this->pages->staffByUuid($tenantId, (string) $body['staff']);
            if ($staff === null) {
                return ApiResponse::error('VALIDATION_FAILED', 'Unknown staff.', 422, ['staff' => 'Choose a listed staff member.']);
            }
            $staffId = (int) $staff['id'];
        }

        if ($this->cutoffPassed($tenantId, $appointment, 'reschedule_cutoff_minutes')) {
            return ApiResponse::error('POLICY_VIOLATION', 'The reschedule deadline has passed — contact the business.', 422, ['rule' => 'reschedule_cutoff_minutes']);
        }
        $policy = $this->pages->policy($tenantId, (int) $appointment['branch_id']) ?? [];
        if (isset($policy['max_reschedules']) && (int) $appointment['reschedule_count'] >= (int) $policy['max_reschedules']) {
            return ApiResponse::error('POLICY_VIOLATION', 'This booking cannot be rescheduled again.', 422, ['rule' => 'max_reschedules']);
        }

        $expectedVersion = isset($body['version']) ? (int) $body['version'] : null;
        try {
            $result = $this->bookings->reschedule(
                $tenantId,
                (string) $appointment['uuid'],
                $date,
                $time,
                $staffId,
                $expectedVersion,
                null,
                'customer',
            );
        } catch (TransitionException $e) {
            return ApiResponse::error($e->apiCode, $e->getMessage(), $e->status, $e->fields ?: null);
        }

        return ApiResponse::ok($result);
    }

    /** @param array<string, string> $params */
    public function bookingCalendar(Request $request, array $params): Response
    {
        $appointment = $this->resolveByToken($params);
        if ($appointment === null) {
            return ApiResponse::error('NOT_FOUND', 'Booking not found.', 404);
        }
        $tenantId = (int) $appointment['tenant_id'];
        $items = $this->appointments->itemsFor($tenantId, (int) $appointment['id']);
        $branch = $this->pages->branch($tenantId, (int) $appointment['branch_id']);
        $tenant = $this->tenants->findById($tenantId);

        $summary = (string) ($items[0]['service_name_snapshot'] ?? 'Appointment')
            . ' — ' . (string) ($tenant['name'] ?? '');
        $location = $branch !== null
            ? trim(implode(', ', array_filter([(string) $branch['name'], (string) ($branch['address_line1'] ?? '')])))
            : '';
        $stamp = gmdate('Ymd\THis\Z');
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//AlbaBook//Booking//EN',
            'BEGIN:VEVENT',
            'UID:' . (string) $appointment['uuid'] . '@albabook',
            'DTSTAMP:' . $stamp,
            'DTSTART:' . str_replace(['-', ':'], '', (string) $appointment['start_at']) . 'Z',
            'DTEND:' . str_replace(['-', ':'], '', (string) $appointment['end_at']) . 'Z',
            'SUMMARY:' . $this->icsEscape($summary),
            $location !== '' ? 'LOCATION:' . $this->icsEscape($location) : 'LOCATION:',
            'DESCRIPTION:' . $this->icsEscape('Booking reference ' . (string) $appointment['reference']),
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);

        return new Response($ics, 200, ['Content-Type' => 'text/calendar; charset=utf-8']);
    }

    /**
     * Resolve slug → publicly bookable tenant (BUSINESS_NOT_FOUND conflates
     * "no such slug" and "booking page disabled" — no enumeration oracle).
     * @param array<string, string> $params
     * @return array<string, mixed>|null
     */
    private function resolveTenant(array $params): ?array
    {
        $tenant = $this->tenants->findBySlug(strtolower(trim((string) ($params['slug'] ?? ''))));
        if ($tenant === null || !in_array($tenant['status'], ['active', 'trial'], true)) {
            return null;
        }
        $settings = $this->pages->pageSettings((int) $tenant['id']);
        if ($settings === null || !(bool) $settings['is_enabled']) {
            return null;
        }

        return $tenant;
    }

    /**
     * Shared read-path resolution: tenant + branch + service (+ optional
     * staff uuid) for availability endpoints. `items` is the contract's
     * comma-separated service-uuid list; the single-service engine uses the
     * first item.
     * @param array<string, string> $params
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>, 3: ?int}|Response
     */
    private function resolveContext(Request $request, array $params): array|Response
    {
        $tenant = $this->resolveTenant($params);
        if ($tenant === null) {
            return ApiResponse::error('BUSINESS_NOT_FOUND', 'Business not found.', 404);
        }

        $serviceUuid = $this->firstServiceUuid((string) $request->query('items', (string) $request->query('service', '')));
        $service = null;
        foreach ($this->pages->services((int) $tenant['id']) as $row) {
            if ((string) $row['uuid'] === $serviceUuid) {
                $service = $row;
                break;
            }
        }
        if ($service === null) {
            return ApiResponse::error('VALIDATION_FAILED', 'Unknown service.', 422, ['items' => 'Choose a listed service.']);
        }

        $branchUuid = trim((string) $request->query('branch', ''));
        $branch = $branchUuid !== ''
            ? $this->pages->branchByUuid((int) $tenant['id'], $branchUuid)
            : ($this->pages->branches((int) $tenant['id'])[0] ?? null);
        if ($branch === null) {
            return ApiResponse::error('VALIDATION_FAILED', 'Unknown branch.', 422, ['branch' => 'Choose a listed branch.']);
        }

        $staffId = null;
        $staffUuid = trim((string) $request->query('staff', ''));
        if ($staffUuid !== '') {
            $staff = $this->pages->staffByUuid((int) $tenant['id'], $staffUuid);
            if ($staff === null) {
                return ApiResponse::error('VALIDATION_FAILED', 'Unknown staff.', 422, ['staff' => 'Choose a listed staff member.']);
            }
            $staffId = (int) $staff['id'];
        }

        return [$tenant, $branch, $service, $staffId];
    }

    /**
     * Write-path resolution for holds/bookings: context from JSON body +
     * per-tenant/IP rate limit + date/time shape.
     * @param array<string, string> $params
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>, 3: ?int, 4: string, 5: string}|Response
     */
    private function resolveWriteContext(Request $request, array $params): array|Response
    {
        $tenant = $this->resolveTenant($params);
        if ($tenant === null) {
            return ApiResponse::error('BUSINESS_NOT_FOUND', 'Business not found.', 404);
        }
        if (!$this->limiter->hit('api-write:' . $tenant['id'] . ':' . $request->clientIp(), self::WRITE_RATE_LIMIT, self::WRITE_RATE_WINDOW)->allowed) {
            return ApiResponse::error('RATE_LIMITED', 'Too many attempts. Try again shortly.', 429);
        }

        $body = $request->body();
        $serviceUuid = $this->firstServiceUuid((string) ($body['items'][0] ?? $body['service'] ?? ''));
        $service = null;
        foreach ($this->pages->services((int) $tenant['id']) as $row) {
            if ((string) $row['uuid'] === $serviceUuid) {
                $service = $row;
                break;
            }
        }
        if ($service === null) {
            return ApiResponse::error('VALIDATION_FAILED', 'Unknown service.', 422, ['service' => 'Choose a listed service.']);
        }

        $branchUuid = trim((string) ($body['branch'] ?? ''));
        $branch = $branchUuid !== ''
            ? $this->pages->branchByUuid((int) $tenant['id'], $branchUuid)
            : ($this->pages->branches((int) $tenant['id'])[0] ?? null);
        if ($branch === null) {
            return ApiResponse::error('VALIDATION_FAILED', 'Unknown branch.', 422, ['branch' => 'Choose a listed branch.']);
        }

        $staffId = null;
        $staffUuid = trim((string) ($body['staff'] ?? ''));
        if ($staffUuid !== '') {
            $staff = $this->pages->staffByUuid((int) $tenant['id'], $staffUuid);
            if ($staff === null) {
                return ApiResponse::error('VALIDATION_FAILED', 'Unknown staff.', 422, ['staff' => 'Choose a listed staff member.']);
            }
            $staffId = (int) $staff['id'];
        }

        $date = (string) ($body['date'] ?? '');
        $time = (string) ($body['time'] ?? '');
        $fields = [];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            $fields['date'] = 'Valid Y-m-d required.';
        }
        if (preg_match('/^\d{2}:\d{2}$/', $time) !== 1) {
            $fields['time'] = 'Valid H:i required.';
        }
        if ($fields !== []) {
            return ApiResponse::error('VALIDATION_FAILED', 'Check the highlighted fields.', 422, $fields);
        }

        return [$tenant, $branch, $service, $staffId, $date, $time];
    }

    /** `items` arrives as a comma-separated uuid list or `items[]`; take the first. */
    private function firstServiceUuid(string $items): string
    {
        $first = trim(explode(',', $items)[0] ?? '');

        return preg_match('/^[0-9a-fA-F-]{36}$/', $first) === 1 ? $first : '';
    }

    /**
     * Manage-token resolution for the /v1/public/bookings/* family — the
     * token is the capability, no slug needed (D-008).
     * @param array<string, string> $params
     * @return array<string, mixed>|null
     */
    private function resolveByToken(array $params): ?array
    {
        $token = trim((string) ($params['manageToken'] ?? ''));
        if (strlen($token) !== 48 || !ctype_xdigit($token)) {
            return null;
        }

        return $this->appointments->findByManageToken(hash('sha256', $token));
    }

    /** @param array<string, mixed> $appointment */
    private function cutoffPassed(int $tenantId, array $appointment, string $policyKey): bool
    {
        $policy = $this->pages->policy($tenantId, (int) $appointment['branch_id']) ?? [];
        if (!isset($policy[$policyKey])) {
            return false;
        }
        $start = new \DateTimeImmutable((string) $appointment['start_at'], new \DateTimeZone('UTC'));

        return $this->dates->now() > $start->modify('-' . (int) $policy[$policyKey] . ' minutes');
    }

    /** @param array<string, mixed> $appointment */
    private function contactMatches(array $appointment, string $contact): bool
    {
        $email = mb_strtolower(trim((string) ($appointment['customer_email_snapshot'] ?? '')));
        if ($email !== '' && hash_equals($email, $contact)) {
            return true;
        }
        // Phone: compare on trailing digits so +254712…/0712…/254712… all match.
        $digits = static fn(string $s): string => (string) preg_replace('/\D/', '', $s);
        $stored = $digits((string) ($appointment['customer_phone_snapshot'] ?? ''));
        $given = $digits($contact);

        return $stored !== '' && $given !== '' && str_ends_with($stored, substr($given, -9));
    }

    /**
     * Queue a manage_link notification — mirrors AppointmentEventService::notify.
     * @param array<string, mixed> $appointment
     */
    private function queueManageLink(array $appointment, string $contact, string $manageUrl): void
    {
        $channel = str_contains($contact, '@') ? 'email' : 'sms';
        $recipient = $channel === 'email'
            ? (string) ($appointment['customer_email_snapshot'] ?? '')
            : (string) ($appointment['customer_phone_snapshot'] ?? '');
        if (trim($recipient) === '') {
            return;
        }
        $tenantId = (int) $appointment['tenant_id'];
        $tenant = $this->tenants->findById($tenantId);
        $payload = json_encode([
            'event' => 'manage_link',
            'appointment_uuid' => (string) $appointment['uuid'],
            'reference' => (string) $appointment['reference'],
            'business_name' => (string) ($tenant['name'] ?? ''),
            'manage_url' => $manageUrl,
        ], JSON_THROW_ON_ERROR);

        $this->notifications->enqueue($tenantId, (int) $appointment['id'], $channel, $recipient, $payload);
    }

    /** @param array<string, mixed> $tenant */
    private function manageUrl(array $tenant, string $token, Request $request): string
    {
        // Scheme and any non-standard port follow the current request.
        return (new AppUrl($this->config))->to(
            HostGroup::Booking,
            '/' . (string) $tenant['slug'] . '/manage/' . $token,
            $request,
        );
    }

    /**
     * @param array<string, mixed> $appointment
     * @return array<string, mixed>
     */
    private function bookingPayload(array $appointment): array
    {
        $tenantId = (int) $appointment['tenant_id'];
        $items = $this->appointments->itemsFor($tenantId, (int) $appointment['id']);
        $item = $items[0] ?? [];
        $branch = $this->pages->branch($tenantId, (int) $appointment['branch_id']);
        $tenant = $this->tenants->findById($tenantId);
        $tz = (string) (($branch['timezone'] ?? null) ?: ($tenant['timezone'] ?? 'Africa/Nairobi'));

        $status = (string) $appointment['status'];
        $live = in_array($status, ['pending', 'confirmed'], true);

        return [
            'uuid' => (string) $appointment['uuid'],
            'reference' => (string) $appointment['reference'],
            'status' => $status,
            'version' => (int) $appointment['version'],
            'start_at' => (string) $appointment['start_at'],
            'end_at' => (string) $appointment['end_at'],
            'timezone' => $tz,
            'service' => [
                'name' => (string) ($item['service_name_snapshot'] ?? ''),
                'duration_minutes' => (int) ($item['duration_minutes'] ?? 0),
            ],
            'staff' => ['uuid' => (string) ($item['staff_uuid'] ?? ''), 'name' => (string) ($item['staff_name'] ?? '')],
            'branch' => ['uuid' => (string) ($branch['uuid'] ?? ''), 'name' => (string) ($branch['name'] ?? '')],
            'price_minor' => (int) $appointment['total_amount_minor'],
            'currency' => (string) $appointment['currency'],
            'can_cancel' => $live && !$this->cutoffPassed($tenantId, $appointment, 'cancellation_cutoff_minutes'),
            'can_reschedule' => $live && !$this->cutoffPassed($tenantId, $appointment, 'reschedule_cutoff_minutes'),
        ];
    }

    /**
     * SLOT_UNAVAILABLE carries up to 5 alternative start times per §18's
     * error contract — the client can offer a one-tap re-pick.
     * @param array<string, mixed> $branch
     * @param array<string, mixed> $service
     */
    private function withAlternatives(
        TransitionException $e,
        int $tenantId,
        array $branch,
        array $service,
        ?int $staffId,
        string $date,
    ): Response {
        $details = $e->fields ?: null;
        if ($e->apiCode === 'SLOT_UNAVAILABLE' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            $tz = (string) ($branch['timezone'] ?: 'Africa/Nairobi');
            $alternatives = array_slice(
                array_map(
                    static fn(array $slot): string => (string) $slot['start_time'],
                    $this->availability->slots($tenantId, $branch, $service, $staffId, $date, $tz),
                ),
                0,
                5,
            );
            $details = ($details ?? []) + ['alternatives' => $alternatives];
        }

        return ApiResponse::error($e->apiCode, $e->getMessage(), $e->status, $details);
    }

    private function icsEscape(string $text): string
    {
        return str_replace(["\\", ',', ';', "\n"], ['\\\\', '\\,', '\\;', '\\n'], $text);
    }
}
