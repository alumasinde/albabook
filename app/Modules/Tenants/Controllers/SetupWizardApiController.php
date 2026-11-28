<?php

declare(strict_types=1);

namespace App\Modules\Tenants\Controllers;

use App\Core\Http\HostGroup;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Auth\Context\AuthContext;
use App\Modules\Auth\Context\MembershipContext;
use App\Modules\Booking\Repositories\BookingEditorRepository;
use App\Modules\Branches\Repositories\BranchRepository;
use App\Modules\Services\Repositories\ServiceRepository;
use App\Modules\Settings\Repositories\BookingPolicyRepository;
use App\Modules\Settings\Support\BookingRules;
use App\Modules\Staff\Repositories\StaffRepository;
use App\Modules\Tenants\Services\SetupReadiness;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\AppUrl;
use App\Tenancy\TenantContext;

/**
 * One read for the setup wizard (update.md 2.2): the saved state of every
 * step plus the SetupReadiness checklist, so the wizard can resume where the
 * owner stopped and prefill what is already saved. Writes go through the
 * normal module APIs — this endpoint only reads.
 */
final class SetupWizardApiController
{
    public function __construct(
        private readonly BranchRepository $branches,
        private readonly ServiceRepository $services,
        private readonly StaffRepository $staff,
        private readonly BookingPolicyRepository $policies,
        private readonly BookingEditorRepository $editor,
        private readonly SetupReadiness $readiness,
        private readonly AuthContext $auth,
        private readonly AppUrl $urls,
    ) {}

    /** @param array<string, string> $params */
    public function state(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('settings.view')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to view setup.', 403);
        }
        $tenantId = $tenant->tenantId;

        $branches = [];
        foreach ($this->branches->listForTenant($tenantId, [])['rows'] as $row) {
            $hours = [];
            foreach ($this->branches->businessHours($tenantId, (int) $row['id']) as $h) {
                $hours[] = [
                    'weekday' => (int) $h['weekday'],
                    'opens_at' => substr((string) $h['opens_at'], 0, 5),
                    'closes_at' => substr((string) $h['closes_at'], 0, 5),
                ];
            }
            $branches[] = [
                'id' => (int) $row['id'],
                'uuid' => $row['uuid'],
                'name' => $row['name'],
                'address_line1' => $row['address_line1'],
                'address_line2' => $row['address_line2'],
                'town' => $row['town'],
                'county' => $row['county'],
                'phone' => $row['phone'],
                'email' => $row['email'],
                'timezone' => $row['timezone'],
                'is_active' => (bool) $row['is_active'],
                'hours' => $hours,
            ];
        }

        $services = [];
        foreach ($this->services->listForTenant($tenantId, [], 1, 100)['rows'] as $row) {
            $services[] = [
                'id' => (int) $row['id'],
                'uuid' => $row['uuid'],
                'name' => $row['name'],
                'description' => $row['description'],
                'category_id' => $row['service_category_id'] !== null ? (int) $row['service_category_id'] : null,
                'duration_minutes' => (int) $row['duration_minutes'],
                'buffer_before_minutes' => (int) $row['buffer_before_minutes'],
                'buffer_after_minutes' => (int) $row['buffer_after_minutes'],
                'price_type' => $row['price_type'],
                'price_amount_minor' => $row['price_amount_minor'] !== null ? (int) $row['price_amount_minor'] : null,
                'min_amount_minor' => $row['min_amount_minor'] !== null ? (int) $row['min_amount_minor'] : null,
                'max_amount_minor' => $row['max_amount_minor'] !== null ? (int) $row['max_amount_minor'] : null,
                'sort_position' => (int) $row['sort_position'],
                'is_active' => (bool) $row['is_active'],
                'is_bookable_online' => (bool) $row['is_bookable_online'],
                'branch_ids' => $this->services->branchIds($tenantId, (int) $row['id']),
            ];
        }

        $staff = [];
        foreach ($this->staff->listForTenant($tenantId, [], 1, 100)['rows'] as $row) {
            $member = $this->staff->findByUuid($tenantId, (string) $row['uuid']);
            if ($member === null) {
                continue;
            }
            $staffId = (int) $member['id'];
            $schedule = [];
            foreach ($this->staff->schedules($tenantId, $staffId) as $s) {
                $weekday = (int) $s['weekday'];
                if (isset($schedule[$weekday])) {
                    continue; // same rows repeat per branch; first wins
                }
                $schedule[$weekday] = [
                    'weekday' => $weekday,
                    'starts_at' => substr((string) $s['starts_at'], 0, 5),
                    'ends_at' => substr((string) $s['ends_at'], 0, 5),
                ];
            }
            $staff[] = [
                'id' => $staffId,
                'uuid' => $member['uuid'],
                'first_name' => $member['first_name'],
                'last_name' => $member['last_name'],
                'display_name' => $member['display_name'],
                'email' => $member['email'],
                'phone_e164' => $member['phone_e164'],
                'tenant_user_id' => $member['tenant_user_id'] !== null ? (int) $member['tenant_user_id'] : null,
                'bio' => $member['bio'],
                'commission_pct' => $member['commission_pct'] !== null ? (float) $member['commission_pct'] : null,
                'booking_priority' => (int) $member['booking_priority'],
                'status' => $member['status'],
                'is_bookable_online' => (bool) $member['is_bookable_online'],
                'branch_ids' => $this->staff->branchIds($tenantId, $staffId),
                'service_ids' => $this->staff->serviceIds($tenantId, $staffId),
                'schedule' => array_values($schedule),
            ];
        }

        $policyRow = $this->policies->tenantRow($tenantId);
        $settings = $this->editor->settings($tenantId);
        $user = $this->auth->user;

        return ApiResponse::ok([
            'tenant' => [
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'timezone' => $tenant->timezone,
                'currency' => $tenant->currency,
                // pending = still in setup; going live starts the free trial.
                'status' => $tenant->status,
            ],
            'trial_days' => \App\Tenancy\TenantLifecycleService::TRIAL_DAYS,
            'booking_url' => $this->urls->to(HostGroup::Booking, '/' . $tenant->slug, $request),
            'me' => [
                'tenant_user_id' => $membership->tenantUserId,
                'first_name' => $user !== null ? $user->firstName : '',
                'last_name' => $user !== null ? $user->lastName : '',
                'email' => $user !== null ? $user->email : '',
                'email_verified' => $user !== null && $user->emailVerified,
            ],
            'readiness' => $this->readiness->check($tenantId),
            'branches' => $branches,
            'services' => $services,
            'staff' => $staff,
            'categories' => $this->services->categories($tenantId),
            'rules' => BookingRules::fromRow($policyRow),
            'rules_saved' => $policyRow !== null,
            'page' => [
                'is_enabled' => (bool) ($settings['is_enabled'] ?? false),
                'booking_flow' => $settings['booking_flow'] ?? 'step_by_step',
                'staff_selection' => $settings['staff_selection'] ?? 'optional',
                'branch_selection' => $settings['branch_selection'] ?? 'optional',
                'service_presentation' => $settings['service_presentation'] ?? 'cards',
                'calendar_presentation' => $settings['calendar_presentation'] ?? 'standard',
                'time_slot_presentation' => $settings['time_slot_presentation'] ?? 'grid',
                'default_locale' => $settings['default_locale'] ?? 'en',
                'seo_title' => $settings['seo_title'] ?? null,
                'seo_description' => $settings['seo_description'] ?? null,
                'og_title' => $settings['og_title'] ?? null,
                'og_description' => $settings['og_description'] ?? null,
                'allow_indexing' => (bool) ($settings['allow_indexing'] ?? true),
                'show_prices' => (bool) ($settings['show_prices'] ?? true),
                'show_duration' => (bool) ($settings['show_duration'] ?? true),
            ],
            'can' => [
                'branches' => $membership->can('branches.manage'),
                'services' => $membership->can('services.manage'),
                'staff' => $membership->can('staff.manage'),
                'rules' => $membership->can('settings.update'),
                'page' => $membership->can('booking_page.manage'),
                'publish' => $membership->can('booking_page.publish'),
            ],
        ]);
    }

    /** @return array{0: TenantContext, 1: MembershipContext} */
    private function context(Request $request): array
    {
        $tenant = $request->attribute('tenant');
        $membership = $request->attribute('membership');
        if (!$tenant instanceof TenantContext || !$membership instanceof MembershipContext) {
            throw new \LogicException('Tenant context required.');
        }

        return [$tenant, $membership];
    }
}
