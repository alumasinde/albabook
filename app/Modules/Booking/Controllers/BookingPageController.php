<?php

declare(strict_types=1);

namespace App\Modules\Booking\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Appointments\Services\ManualBookingService;
use App\Modules\Appointments\Services\TransitionException;
use App\Modules\Billing\Services\FeatureGate;
use App\Modules\Booking\Repositories\BookingHoldRepository;
use App\Modules\Booking\Repositories\BookingPageRepository;
use App\Modules\Booking\Services\AvailabilityService;
use App\Shared\Http\ApiResponse;
use App\Shared\Money\CurrencyFormatter;
use App\Shared\Money\Money;
use App\Shared\Security\RateLimiter;
use App\Shared\Validation\CustomerContact;
use App\Tenancy\TenantRepository;

/**
 * Public booking page on the book.* host (plan.md §7, booking-engine.md §5):
 * GET /{slug} renders the page, GET /{slug}/availability returns slot JSON,
 * POST /{slug}/book writes the appointment through the same service the
 * dashboard uses. Tenant identity always comes from the slug lookup —
 * request bodies can carry service/staff ids only.
 */
final class BookingPageController
{
    private const BOOK_RATE_LIMIT = 12;
    private const BOOK_RATE_WINDOW = 600;

    public function __construct(
        private readonly View $view,
        private readonly TenantRepository $tenants,
        private readonly BookingPageRepository $pages,
        private readonly BookingHoldRepository $holds,
        private readonly AvailabilityService $availability,
        private readonly ManualBookingService $bookings,
        private readonly RateLimiter $limiter,
        private readonly CurrencyFormatter $currency,
        private readonly FeatureGate $features,
        private readonly \App\Core\Http\RequestContext $context,
    ) {}

    /** @param array<string, string> $params */
    public function page(Request $request, array $params): Response
    {
        $tenant = $this->tenant($params);
        if ($tenant === null) {
            throw HttpException::notFound('This booking page is not available.');
        }

        $settings = $this->pages->pageSettings((int) $tenant['id']) ?? [];
        $branding = $this->pages->branding((int) $tenant['id']);
        $branches = $this->pages->branches((int) $tenant['id']);
        $services = array_map(
            fn(array $service): array => $service + ['price_label' => $this->priceLabel($service)],
            $this->pages->services((int) $tenant['id']),
        );
        $openingHours = $branches !== [] ? $this->pages->openingHours((int) $tenant['id'], (int) $branches[0]['id']) : [];

        // Slugless endpoints when the request arrived on the tenant's
        // verified custom domain; canonical slug endpoints otherwise.
        $apiBase = $this->context->customDomain !== null ? '' : '/' . $tenant['slug'];

        // Canonical URL = the address actually being served, so custom-domain
        // tenants get their own domain in og:url/canonical, not book.*.
        $port = $request->port();
        $canonical = ($request->isSecure() ? 'https' : 'http') . '://' . $request->host()
            . ($port !== null && $port !== 80 && $port !== 443 ? ':' . $port : '')
            . ($apiBase === '' ? '/' : $apiBase);

        $seoDescription = trim((string) ($settings['seo_description'] ?? ''));

        return $this->view->response('booking/page.twig', [
            'tenant' => $tenant,
            'settings' => $settings,
            'branding' => $branding,
            'branches' => $branches,
            'services' => $services,
            'opening_hours' => $openingHours,
            'business_name' => $tenant['name'],
            'theme_css' => $this->themeCss($branding),
            'sections' => $this->pages->sections((int) $tenant['id']),
            'faqs' => $this->pages->faqs((int) $tenant['id']),
            'show_powered_by' => !$this->features->enabled((int) $tenant['id'], 'white_label'),
            'api_base' => $apiBase,
            // Brand link returns to this booking page in the visitor's locale.
            'home_url' => $apiBase . '?lang=' . $this->context->locale,
            'canonical_url' => $canonical,
            'seo' => [
                'description' => $seoDescription,
                // JSON_HEX_* flags keep tenant text inert inside <script>.
                'structured' => $this->structuredData($tenant, $branches[0] ?? null, $services, $openingHours, $canonical, $seoDescription),
            ],
            // Bookings submitted from the framed widget are recorded with
            // booking_source 'widget' (the page JS reads data-embed).
            'embed' => str_ends_with($request->path(), '/embed'),
        ]);
    }

    /**
     * Widget frame variant of the booking page (plan.md §12.2): identical
     * markup, but served with `frame-ancestors *` so customer sites can
     * embed it. CSP frame-ancestors supersedes X-Frame-Options in modern
     * browsers; the plain page stays locked to 'none'.
     * @param array<string, string> $params
     */
    public function embed(Request $request, array $params): Response
    {
        $response = $this->page($request, $params);
        $nonce = (string) $this->context->cspNonce;

        return $response->withHeader(
            'Content-Security-Policy',
            implode('; ', [
                "default-src 'self'",
                "script-src 'self'",
                "style-src 'self' 'nonce-{$nonce}'",
                "img-src 'self' data:",
                "font-src 'self'",
                "connect-src 'self'",
                "form-action 'self'",
                "frame-ancestors *",
                "base-uri 'self'",
                "object-src 'none'",
            ]),
        );
    }

    /**
     * Tenant theme rendered inside `.booking-page` scope (plan.md §8.8 —
     * CSP requires a nonce'd style block; dashboards are never re-themed).
     * Returns null when branding is absent so the view can skip the tag.
     *
     * @param array<string, mixed>|null $branding
     */
    /**
     * schema.org LocalBusiness + OfferCatalog for the booking page — lets
     * search engines and WhatsApp/social previews understand the business.
     * Output is JSON_HEX_* escaped so tenant text cannot break the tag.
     *
     * @param array<string, mixed> $tenant
     * @param array<string, mixed>|null $branch
     * @param list<array<string, mixed>> $services
     * @param list<array<string, mixed>> $openingHours
     */
    private function structuredData(array $tenant, ?array $branch, array $services, array $openingHours, string $canonical, string $description): string
    {
        $business = [
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => (string) $tenant['name'],
            'url' => $canonical,
        ];
        if ($description !== '') {
            $business['description'] = $description;
        }
        if ($branch !== null) {
            $street = trim(implode(' ', array_filter([(string) ($branch['address_line1'] ?? ''), (string) ($branch['address_line2'] ?? '')])));
            $address = array_filter([
                '@type' => 'PostalAddress',
                'streetAddress' => $street !== '' ? $street : null,
                'addressLocality' => ($branch['town'] ?? '') !== '' ? (string) $branch['town'] : null,
                'addressRegion' => ($branch['county'] ?? '') !== '' ? (string) $branch['county'] : null,
                'addressCountry' => 'KE',
            ]);
            if (count($address) > 2) {
                $business['address'] = $address;
            }
            if (($branch['phone'] ?? '') !== '') {
                $business['telephone'] = (string) $branch['phone'];
            }
        }

        if ($openingHours !== []) {
            $days = [1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
            $business['openingHoursSpecification'] = array_map(static fn(array $row): array => [
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => $days[(int) $row['weekday']] ?? 'Monday',
                'opens' => substr((string) $row['opens_at'], 0, 5),
                'closes' => substr((string) $row['closes_at'], 0, 5),
            ], $openingHours);
        }

        $offers = [];
        foreach ($services as $service) {
            $offer = ['@type' => 'Offer', 'itemOffered' => ['@type' => 'Service', 'name' => (string) $service['name']]];
            if ($service['price_type'] === 'fixed' && $service['price_amount_minor'] !== null) {
                $offer['price'] = number_format(((int) $service['price_amount_minor']) / 100, 2, '.', '');
                $offer['priceCurrency'] = (string) $service['currency'];
            }
            $offers[] = $offer;
        }
        if ($offers !== []) {
            $business['hasOfferCatalog'] = ['@type' => 'OfferCatalog', 'name' => 'Services', 'itemListElement' => $offers];
        }

        return json_encode($business, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed>|null $branding */
    private function themeCss(?array $branding): ?string
    {
        if ($branding === null) {
            return null;
        }

        $primary = $this->hexChannels((string) ($branding['primary_color'] ?? '#6D5CE8'));
        $secondary = $this->hexChannels((string) ($branding['secondary_color'] ?? '#171A2C'));
        $accent = $this->hexChannels((string) ($branding['accent_color'] ?? '#16A085'));
        $text = $this->hexChannels((string) ($branding['text_color'] ?? '#202124'));
        $background = $this->hexChannels((string) ($branding['background_color'] ?? '#FFFFFF'));

        $channels = static fn(array $c): string => implode(' ', $c);
        $vars = [
            '--color-primary' => $channels($primary),
            '--color-primary-strong' => $this->mix($primary, [0, 0, 0], 0.15),
            '--color-primary-soft' => $this->mix($primary, [255, 255, 255], 0.93),
            '--color-navy' => $channels($secondary),
            '--color-success' => $channels($accent),
            '--color-success-strong' => $this->mix($accent, [0, 0, 0], 0.15),
            '--color-success-soft' => $this->mix($accent, [255, 255, 255], 0.92),
            '--color-ink' => $channels($text),
            '--color-surface' => $channels($background),
        ];

        if (($branding['appearance'] ?? 'light') === 'dark') {
            $vars += [
                '--color-muted' => '148 156 180',
                '--color-line' => '45 50 68',
                '--brand-card' => $this->mix($background, [255, 255, 255], 0.08),
            ];
        }

        $declarations = '';
        foreach ($vars as $name => $value) {
            $declarations .= "{$name}:{$value};";
        }

        $css = ".booking-page{{$declarations}}";
        if (($branding['appearance'] ?? 'light') === 'dark') {
            $css .= '.booking-page .bg-white{background-color:rgb(var(--brand-card));}';
        }

        return $css;
    }

    /** Hex (#RRGGBB) → "R G B" channels for Tailwind token vars.
     * @return array{0: int, 1: int, 2: int}
     */
    private function hexChannels(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) {
            return [109, 92, 232];
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /** Linear mix of two RGB channel sets; `$weight` = share of `$b`.
     * @param array{0: int, 1: int, 2: int} $a
     * @param array{0: int, 1: int, 2: int} $b
     */
    private function mix(array $a, array $b, float $weight): string
    {
        $channel = static fn(int $x, int $y): int => (int) round($x + ($y - $x) * $weight);

        return sprintf('%d %d %d', $channel($a[0], $b[0]), $channel($a[1], $b[1]), $channel($a[2], $b[2]));
    }

    /**
     * Bookable staff for a service at a branch — feeds the "Select staff"
     * dropdown once the customer picks a service.
     *
     * @param array<string, string> $params
     */
    public function staffList(Request $request, array $params): Response
    {
        $tenant = $this->tenant($params);
        if ($tenant === null) {
            return ApiResponse::error('NOT_FOUND', 'Booking page not found.', 404);
        }

        $serviceId = (int) $request->query('service', 0);
        $branchId = (int) $request->query('branch', 0);
        $branch = $this->resolveBranch((int) $tenant['id'], $branchId);
        if ($branch === null || $serviceId <= 0) {
            return ApiResponse::error('VALIDATION', 'Choose a service first.', 422, ['service' => 'Choose a service.']);
        }

        $staff = array_map(
            static fn(array $member): array => [
                'id' => (int) $member['id'],
                'name' => (string) $member['display_name'],
            ],
            $this->availability->candidates((int) $tenant['id'], $serviceId, (int) $branch['id']),
        );

        return ApiResponse::ok(['staff' => $staff]);
    }

    /** @param array<string, string> $params */
    public function availability(Request $request, array $params): Response
    {
        $tenant = $this->tenant($params);
        if ($tenant === null) {
            return ApiResponse::error('NOT_FOUND', 'Booking page not found.', 404);
        }

        $serviceId = (int) $request->query('service', 0);
        $branchId = (int) $request->query('branch', 0);
        $staffId = (int) $request->query('staff', 0) ?: null;
        $date = (string) $request->query('date', '');

        $fields = [];
        if ($serviceId <= 0) {
            $fields['service'] = 'Choose a service.';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            $fields['date'] = 'Pick a valid date.';
        }
        if ($fields !== []) {
            return ApiResponse::error('VALIDATION', 'Choose a service and a date.', 422, $fields);
        }

        $branch = $this->resolveBranch((int) $tenant['id'], $branchId);
        $service = $this->resolveService((int) $tenant['id'], $serviceId);
        if ($branch === null || $service === null) {
            return ApiResponse::error('NOT_FOUND', 'That service or location is not available.', 404);
        }

        $slots = $this->availability->slots(
            (int) $tenant['id'], $branch, $service, $staffId, $date, (string) $tenant['timezone'],
        );

        return ApiResponse::ok(['slots' => $slots]);
    }

    /** @param array<string, string> $params */
    public function book(Request $request, array $params): Response
    {
        $tenant = $this->tenant($params);
        if ($tenant === null) {
            return ApiResponse::error('NOT_FOUND', 'Booking page not found.', 404);
        }

        $hit = $this->limiter->hit(
            'public-book:' . $tenant['id'] . ':' . $request->clientIp(),
            self::BOOK_RATE_LIMIT,
            self::BOOK_RATE_WINDOW,
        );
        if (!$hit->allowed) {
            return ApiResponse::error('RATE_LIMITED', 'Too many attempts. Try again shortly.', 429);
        }

        $body = $request->body();
        $serviceId = (int) ($body['service'] ?? 0);
        $branchId = (int) ($body['branch'] ?? 0);
        $staffId = isset($body['staff']) && $body['staff'] !== '' ? (int) $body['staff'] : null;
        $date = (string) ($body['date'] ?? '');
        $time = (string) ($body['time'] ?? '');
        $customer = [
            'name' => trim((string) ($body['name'] ?? '')),
            'phone' => trim((string) ($body['phone'] ?? '')) ?: null,
            'email' => trim((string) ($body['email'] ?? '')) ?: null,
            'notes' => trim((string) ($body['notes'] ?? '')) ?: null,
        ];
        $locale = in_array($body['locale'] ?? 'en', ['en', 'sw'], true) ? (string) ($body['locale'] ?? 'en') : 'en';
        $idempotencyKey = trim((string) ($body['idempotency_key'] ?? ''));
        if ($idempotencyKey !== '' && !preg_match('/^[A-Za-z0-9_-]{8,64}$/', $idempotencyKey)) {
            return ApiResponse::error('VALIDATION', 'Invalid idempotency key.', 422, ['idempotency_key' => 'Invalid key.']);
        }
        $holdToken = trim((string) ($body['hold_token'] ?? ''));
        if ($holdToken !== '' && (strlen($holdToken) !== 48 || !ctype_xdigit($holdToken))) {
            return ApiResponse::error('VALIDATION', 'Invalid reservation token.', 422, ['hold_token' => 'Invalid reservation token.']);
        }

        $fields = [];
        if ($serviceId <= 0) {
            $fields['service'] = 'Choose a service.';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            $fields['date'] = 'Pick a valid date.';
        }
        if (preg_match('/^\d{2}:\d{2}$/', $time) !== 1) {
            $fields['time'] = 'Pick a time.';
        }
        // Name, email format, phone (normalised to E.164) and lengths.
        $fields += CustomerContact::validate($customer);
        if ($fields !== []) {
            return ApiResponse::error('VALIDATION', 'Check the highlighted fields.', 422, $fields);
        }

        $branch = $this->resolveBranch((int) $tenant['id'], $branchId);
        $service = $this->resolveService((int) $tenant['id'], $serviceId);
        if ($branch === null || $service === null) {
            return ApiResponse::error('NOT_FOUND', 'That service or location is not available.', 404);
        }

        if ($holdToken !== '') {
            // A hold pins the staff it was taken for and marks its own
            // window busy on the grid — so the grid check is skipped here
            // and create() (which excludes this hold) is the arbiter.
            $hold = $this->holds->findActiveByTokenHash(hash('sha256', $holdToken));
            if ($hold === null || (int) $hold['tenant_id'] !== (int) $tenant['id']) {
                return ApiResponse::error('HOLD_EXPIRED', 'Your reservation expired — pick the time again.', 410, ['time' => 'Reservation expired. Pick the time again.']);
            }
            $holdItems = $this->holds->itemsFor((int) $tenant['id'], (int) $hold['id']);
            $holdStaffId = (int) ($holdItems[0]['staff_id'] ?? 0);
            if ($staffId === null) {
                $staffId = $holdStaffId;
            }
            $staff = null;
            foreach ($this->availability->candidates((int) $tenant['id'], (int) $service['id'], (int) $branch['id']) as $member) {
                if ((int) $member['id'] === $staffId) {
                    $staff = $member;
                    break;
                }
            }
            if ($staff === null || $staffId !== $holdStaffId) {
                return ApiResponse::error('VALIDATION_FAILED', 'That reservation does not match this booking.', 422, ['hold_token' => 'The reservation is for a different slot.']);
            }
        } else {
            $staff = $this->pickStaff((int) $tenant['id'], $branch, $service, $staffId, $date, $time, (string) $tenant['timezone']);
            if ($staff === null) {
                return ApiResponse::error('SLOT_UNAVAILABLE', 'That time is no longer available. Pick another.', 409, ['time' => 'Pick another time.']);
            }
        }

        try {
            $result = $this->bookings->create(
                (int) $tenant['id'],
                (int) $branch['id'],
                $branch['timezone'] ?: (string) $tenant['timezone'],
                (int) $staff['id'],
                (int) $service['id'],
                $date,
                $time,
                $customer,
                // The widget frame posts source=widget; anything else from
                // the public page stays 'public_page' (client can't pick
                // privileged sources).
                ($body['source'] ?? '') === 'widget' ? 'widget' : 'public_page',
                $locale,
                (string) $tenant['currency'],
                null,
                'customer',
                false,
                null,
                $idempotencyKey !== '' ? $idempotencyKey : null,
                $holdToken !== '' ? $holdToken : null,
            );
        } catch (TransitionException $e) {
            return ApiResponse::error($e->apiCode, $e->getMessage(), $e->status, $e->fields ?: null);
        }

        return ApiResponse::ok([
            'uuid' => $result['uuid'],
            'reference' => $result['reference'],
            'status' => $result['status'],
            'start_at' => $result['start_at'],
            'staff_name' => (string) $staff['display_name'],
            'service_name' => (string) $service['name'],
            'existing' => $result['existing'],
            'manage_url' => $result['manage_token'] !== null
                ? ($this->context->customDomain !== null ? '' : '/' . $tenant['slug']) . '/manage/' . $result['manage_token']
                : null,
        ], $result['existing'] ? 200 : 201);
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>|null
     */
    private function tenant(array $params): ?array
    {
        $slug = strtolower(trim((string) ($params['slug'] ?? '')));
        // On a verified custom domain the slug segment is absent: the host
        // itself identifies the tenant (TrustedHostMiddleware only maps
        // 'active' tenant_domains here).
        $tenant = $slug === ''
            ? ($this->context->customDomain !== null ? $this->tenants->findByDomain($this->context->customDomain) : null)
            : $this->tenants->findBySlug($slug);
        if ($tenant === null || !in_array($tenant['status'], ['active', 'trial'], true)) {
            return null;
        }
        $settings = $this->pages->pageSettings((int) $tenant['id']);
        if ($settings === null || !(bool) $settings['is_enabled']) {
            return null;
        }

        return $tenant;
    }

    /** @return array<string, mixed>|null */
    private function resolveBranch(int $tenantId, int $branchId): ?array
    {
        if ($branchId > 0) {
            return $this->pages->branch($tenantId, $branchId);
        }
        $branches = $this->pages->branches($tenantId);

        return $branches[0] ?? null;
    }

    /** @return array<string, mixed>|null */
    private function resolveService(int $tenantId, int $serviceId): ?array
    {
        foreach ($this->pages->services($tenantId) as $service) {
            if ((int) $service['id'] === $serviceId) {
                return $service;
            }
        }

        return null;
    }

    /**
     * Verify the chosen staff can take the slot, or pick the first candidate
     * in priority order that can.
     *
     * @param array<string, mixed> $branch
     * @param array<string, mixed> $service
     * @return array<string, mixed>|null
     */
    private function pickStaff(
        int $tenantId,
        array $branch,
        array $service,
        ?int $staffId,
        string $date,
        string $time,
        string $timezone,
    ): ?array {
        $candidates = $this->availability->candidates($tenantId, (int) $service['id'], (int) $branch['id']);
        if ($staffId !== null) {
            $candidates = array_values(array_filter($candidates, static fn($s): bool => (int) $s['id'] === $staffId));
        }
        foreach ($candidates as $member) {
            if ($this->availability->slotAvailableForStaff(
                $tenantId, $branch, $service, (int) $member['id'], $date, $time, $timezone,
            )) {
                return $member;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $service */
    private function priceLabel(array $service): string
    {
        $currency = (string) $service['currency'];
        $format = fn(?int $minor): string => $this->currency->format(new Money($minor ?? 0, $currency));

        return match ($service['price_type']) {
            'fixed' => $format($service['price_amount_minor'] !== null ? (int) $service['price_amount_minor'] : null),
            'from' => 'From ' . $format(isset($service['min_amount_minor']) ? (int) $service['min_amount_minor'] : null),
            'range' => $format(isset($service['min_amount_minor']) ? (int) $service['min_amount_minor'] : null)
                . ' – ' . $format(isset($service['max_amount_minor']) ? (int) $service['max_amount_minor'] : null),
            default => $format(null),
        };
    }
}
