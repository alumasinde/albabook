<?php

declare(strict_types=1);

namespace App\Modules\Booking\Controllers;

use App\Core\Database\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Auth\Context\MembershipContext;
use App\Modules\Booking\Repositories\BookingEditorRepository;
use App\Modules\Tenants\Services\SetupReadiness;
use App\Shared\Http\ApiResponse;
use App\Shared\Support\HostMap;
use App\Shared\Support\UuidFactory;
use App\Tenancy\TenantContext;

/**
 * Private JSON API for the booking-page editor (session-authenticated,
 * D-008). Reads require booking_page.view; edits require
 * booking_page.manage; publishing requires booking_page.publish.
 */
final class BookingEditorApiController
{
    private const FLOWS = ['step_by_step', 'compact'];
    private const SELECTIONS = ['required', 'optional', 'hidden'];
    private const SERVICE_LAYOUTS = ['cards', 'list'];
    private const CALENDAR_LAYOUTS = ['standard', 'compact'];
    private const SLOT_LAYOUTS = ['grid', 'list'];

    public function __construct(
        private readonly BookingEditorRepository $editor,
        private readonly Database $db,
        private readonly UuidFactory $uuids,
        private readonly HostMap $hosts,
        private readonly SetupReadiness $readiness,
        private readonly \App\Tenancy\TenantLifecycleService $lifecycle,
    ) {}

    /** Current settings, sections, FAQs, publish history + the live URL.
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('booking_page.view')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to view the booking page editor.', 403);
        }

        $settings = $this->editor->settings($tenant->tenantId);
        $tenantRow = $this->editor->tenant($tenant->tenantId) ?? [];

        return ApiResponse::ok([
            'settings' => $this->shape($settings),
            'sections' => $this->editor->sections($tenant->tenantId),
            'faqs' => $this->editor->faqs($tenant->tenantId),
            'versions' => $this->editor->versions($tenant->tenantId),
            'locales' => array_column($this->editor->locales(), 'code'),
            'booking_url' => $this->bookingUrl($request, (string) ($tenantRow['slug'] ?? '')),
            'options' => [
                'booking_flow' => self::FLOWS,
                'staff_selection' => self::SELECTIONS,
                'branch_selection' => self::SELECTIONS,
                'service_presentation' => self::SERVICE_LAYOUTS,
                'calendar_presentation' => self::CALENDAR_LAYOUTS,
                'time_slot_presentation' => self::SLOT_LAYOUTS,
            ],
        ]);
    }

    /** Update booking-page settings.
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('booking_page.manage')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to edit the booking page.', 403);
        }

        $body = $request->body();
        $fields = [];

        $seoTitle = $this->shortText($body, 'seo_title', 70, $fields);
        $seoDesc = $this->shortText($body, 'seo_description', 160, $fields);
        $ogTitle = $this->shortText($body, 'og_title', 70, $fields);
        $ogDesc = $this->shortText($body, 'og_description', 160, $fields);

        // Go-live gate (update.md 2.4): never switch on a page that cannot
        // take a booking. Switching off is always allowed.
        if (!empty($body['is_enabled']) && ($blockers = $this->readiness->blockers($tenant->tenantId)) !== []) {
            return $this->notReady($blockers);
        }

        $data = [
            'is_enabled' => !empty($body['is_enabled']) ? 1 : 0,
            'seo_title' => $seoTitle,
            'seo_description' => $seoDesc,
            'og_title' => $ogTitle,
            'og_description' => $ogDesc,
            'allow_indexing' => !empty($body['allow_indexing']) ? 1 : 0,
            'show_prices' => !empty($body['show_prices']) ? 1 : 0,
            'show_duration' => !empty($body['show_duration']) ? 1 : 0,
        ];

        foreach ([
            'booking_flow' => self::FLOWS,
            'staff_selection' => self::SELECTIONS,
            'branch_selection' => self::SELECTIONS,
            'service_presentation' => self::SERVICE_LAYOUTS,
            'calendar_presentation' => self::CALENDAR_LAYOUTS,
            'time_slot_presentation' => self::SLOT_LAYOUTS,
        ] as $key => $allowed) {
            $value = (string) ($body[$key] ?? '');
            if (!in_array($value, $allowed, true)) {
                $fields[$key] = 'Choose a valid option.';
            } else {
                $data[$key] = $value;
            }
        }

        $locale = (string) ($body['default_locale'] ?? '');
        $validLocales = array_column($this->editor->locales(), 'code');
        if (!in_array($locale, $validLocales, true)) {
            $fields['default_locale'] = 'Choose a valid language.';
        } else {
            $data['default_locale'] = $locale;
        }

        if ($fields !== []) {
            return ApiResponse::error('VALIDATION', 'Fix the highlighted fields.', 422, $fields);
        }

        $this->editor->updateSettings($tenant->tenantId, $data);
        // Going live (readiness already checked above) starts the free trial
        // for a business still in setup.
        $trialStarted = $data['is_enabled'] === 1
            && $this->lifecycle->goLive($tenant->tenantId, $membership->userId, $request);

        return ApiResponse::ok([
            'settings' => $this->shape($this->editor->settings($tenant->tenantId)),
            'trial_started' => $trialStarted,
            'trial_days' => \App\Tenancy\TenantLifecycleService::TRIAL_DAYS,
        ]);
    }

    /** Bulk enable/order of page sections.
     * @param array<string, string> $params
     */
    public function updateSections(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('booking_page.manage')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to edit the booking page.', 403);
        }

        $body = $request->body();
        $items = $body['sections'] ?? null;
        if (!is_array($items)) {
            return ApiResponse::error('VALIDATION', 'Sections payload is required.', 422);
        }

        $validKeys = array_column($this->editor->sections($tenant->tenantId), 'code');
        $clean = [];
        $position = 10;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = (string) ($item['key'] ?? '');
            if (!in_array($key, $validKeys, true)) {
                continue; // ignore unknown keys rather than failing the batch
            }
            $clean[] = [
                'key' => $key,
                'enabled' => !empty($item['enabled']),
                'position' => isset($item['position']) && is_numeric($item['position'])
                    ? max(0, (int) $item['position'])
                    : $position,
            ];
            $position += 10;
        }

        $this->editor->syncSections($tenant->tenantId, $clean);

        return ApiResponse::ok(['sections' => $this->editor->sections($tenant->tenantId)]);
    }

    /** Create a FAQ.
     * @param array<string, string> $params
     */
    public function createFaq(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('booking_page.manage')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to edit the booking page.', 403);
        }

        [$data, $fields] = $this->validatedFaq($request->body());
        if ($fields !== []) {
            return ApiResponse::error('VALIDATION', 'Fix the highlighted fields.', 422, $fields);
        }

        $this->editor->insertFaq($tenant->tenantId, $data);

        return ApiResponse::ok(['faqs' => $this->editor->faqs($tenant->tenantId)], 201);
    }

    /** Update a FAQ.
     * @param array<string, string> $params
     */
    public function updateFaq(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('booking_page.manage')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to edit the booking page.', 403);
        }

        $id = (int) $params['id'];
        if ($this->editor->findFaq($tenant->tenantId, $id) === null) {
            return ApiResponse::error('NOT_FOUND', 'FAQ not found.', 404);
        }

        [$data, $fields] = $this->validatedFaq($request->body());
        if ($fields !== []) {
            return ApiResponse::error('VALIDATION', 'Fix the highlighted fields.', 422, $fields);
        }

        $this->editor->updateFaq($tenant->tenantId, $id, $data);

        return ApiResponse::ok(['faqs' => $this->editor->faqs($tenant->tenantId)]);
    }

    /** Delete a FAQ.
     * @param array<string, string> $params
     */
    public function deleteFaq(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('booking_page.manage')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to edit the booking page.', 403);
        }

        $id = (int) $params['id'];
        if ($this->editor->findFaq($tenant->tenantId, $id) === null) {
            return ApiResponse::error('NOT_FOUND', 'FAQ not found.', 404);
        }

        $this->editor->deleteFaq($tenant->tenantId, $id);

        return ApiResponse::ok(['faqs' => $this->editor->faqs($tenant->tenantId)]);
    }

    /** Publish a snapshot of settings + sections + FAQs as the live version.
     * @param array<string, string> $params
     */
    public function publish(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('booking_page.publish')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to publish the booking page.', 403);
        }
        if (($blockers = $this->readiness->blockers($tenant->tenantId)) !== []) {
            return $this->notReady($blockers);
        }

        $settings = $this->editor->settings($tenant->tenantId);
        $snapshot = json_encode([
            'settings' => $this->shape($settings),
            'sections' => $this->editor->sections($tenant->tenantId),
            'faqs' => $this->editor->faqs($tenant->tenantId),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $version = $this->editor->nextVersion($tenant->tenantId);
        $uuid = $this->uuids->generate();

        $this->db->transaction(function () use ($tenant, $uuid, $version, $snapshot, $membership): void {
            $this->editor->deactivateVersions($tenant->tenantId);
            $this->editor->insertVersion($tenant->tenantId, $uuid, $version, $snapshot, $membership->actorUserId());
        });
        // Publishing a ready page while the page is switched on is going live too.
        $settings = $this->editor->settings($tenant->tenantId);
        $trialStarted = (bool) ($settings['is_enabled'] ?? false)
            && $this->lifecycle->goLive($tenant->tenantId, $membership->userId, $request);

        return ApiResponse::ok([
            'trial_started' => $trialStarted,
            'trial_days' => \App\Tenancy\TenantLifecycleService::TRIAL_DAYS,
            'version' => $version,
            'versions' => $this->editor->versions($tenant->tenantId),
        ], 201);
    }

    /* ------------------------------------------------------------------ */

    /** @param list<string> $blockers */
    private function notReady(array $blockers): Response
    {
        return ApiResponse::error(
            'NOT_READY',
            'Your booking page cannot go live yet: ' . implode('; ', $blockers) . '.',
            422,
            ['missing' => $blockers],
        );
    }

    /** Absolute public booking URL; keeps the dev-server port when present. */
    private function bookingUrl(Request $request, string $slug): string
    {
        $host = $this->hosts->hostFor(\App\Core\Http\HostGroup::Booking);
        $currentHost = (string) $request->header('host', '');
        if (($port = parse_url('http://' . $currentHost, PHP_URL_PORT)) !== null) {
            $host .= ':' . $port;
        }
        $scheme = $request->header('x-forwarded-proto', 'http');

        return $scheme . '://' . $host . '/' . $slug;
    }

    /** @return array{TenantContext, MembershipContext} */
    private function context(Request $request): array
    {
        return [$request->attribute('tenant'), $request->attribute('membership')];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shape(array $row): array
    {
        return [
            'is_enabled' => (bool) ($row['is_enabled'] ?? false),
            'seo_title' => $row['seo_title'] ?? null,
            'seo_description' => $row['seo_description'] ?? null,
            'og_title' => $row['og_title'] ?? null,
            'og_description' => $row['og_description'] ?? null,
            'allow_indexing' => (bool) ($row['allow_indexing'] ?? true),
            'booking_flow' => $row['booking_flow'] ?? 'step_by_step',
            'staff_selection' => $row['staff_selection'] ?? 'optional',
            'branch_selection' => $row['branch_selection'] ?? 'optional',
            'service_presentation' => $row['service_presentation'] ?? 'cards',
            'calendar_presentation' => $row['calendar_presentation'] ?? 'standard',
            'time_slot_presentation' => $row['time_slot_presentation'] ?? 'grid',
            'show_prices' => (bool) ($row['show_prices'] ?? true),
            'show_duration' => (bool) ($row['show_duration'] ?? true),
            'default_locale' => $row['default_locale'] ?? 'en',
        ];
    }

    /** @param array<string, mixed> $body
     * @param array<string, string> $fields
     */
    private function shortText(array $body, string $key, int $max, array &$fields): ?string
    {
        $value = trim((string) ($body[$key] ?? ''));
        if (mb_strlen($value) > $max) {
            $fields[$key] = "Too long (max {$max} characters).";

            return null;
        }

        return $value !== '' ? $value : null;
    }

    /** @param array<string, mixed> $body
     * @return array{array{question: string, answer: string, sort_position: int}, array<string, string>}
     */
    private function validatedFaq(array $body): array
    {
        $fields = [];
        $question = trim((string) ($body['question'] ?? ''));
        $answer = trim((string) ($body['answer'] ?? ''));

        if ($question === '' || mb_strlen($question) > 300) {
            $fields['question'] = 'Question is required (max 300 characters).';
        }
        if ($answer === '') {
            $fields['answer'] = 'Answer is required.';
        }

        $position = (int) ($body['sort_position'] ?? 0);

        return [
            ['question' => $question, 'answer' => $answer, 'sort_position' => max(0, $position)],
            $fields,
        ];
    }
}
