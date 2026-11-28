<?php

declare(strict_types=1);

namespace App\Modules\Settings\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Auth\Context\MembershipContext;
use App\Modules\Settings\Repositories\BookingPolicyRepository;
use App\Modules\Settings\Support\BookingRules;
use App\Shared\Http\ApiResponse;
use App\Tenancy\TenantContext;

/**
 * Settings → Booking rules: confirmation mode (auto / manual approval),
 * request expiry, notice, advance window, slot interval and the customer
 * cancel / reschedule limits. Reused by the setup wizard.
 */
final class BookingRulesApiController
{
    public function __construct(
        private readonly BookingPolicyRepository $policies,
        private readonly \App\Modules\Booking\Services\BookingVerificationService $verification,
    ) {}

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('settings.view')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to view settings.', 403);
        }

        return ApiResponse::ok($this->payload($tenant->tenantId, $membership));
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('settings.update')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to update settings.', 403);
        }

        $body = $request->body();
        // Callers that do not know about this rule (the setup wizard) keep
        // whatever the business chose instead of silently switching it off.
        if (!array_key_exists('customer_verification', $body)) {
            $body['customer_verification'] = BookingRules::fromRow($this->policies->tenantRow($tenant->tenantId))['customer_verification'];
        }
        [$rules, $fields] = BookingRules::normalise($body);
        $turningOn = ($rules['customer_verification'] ?? 'none') === 'otp_phone'
            && BookingRules::fromRow($this->policies->tenantRow($tenant->tenantId))['customer_verification'] !== 'otp_phone';
        // A code nobody can receive is pointless; refuse to switch it on.
        if ($turningOn && !$this->verification->smsAvailable($tenant->tenantId)) {
            $fields['customer_verification'] = 'Connect an SMS account first (Settings → Notifications), or use a plan that includes SMS.';
        }
        if ($fields !== []) {
            return ApiResponse::error('VALIDATION', 'Fix the highlighted fields.', 422, $fields);
        }
        $this->policies->saveTenantRules($tenant->tenantId, $rules);

        return ApiResponse::ok($this->payload($tenant->tenantId, $membership));
    }

    /** @return array<string, mixed> */
    private function payload(int $tenantId, MembershipContext $membership): array
    {
        $row = $this->policies->tenantRow($tenantId);

        return [
            'rules' => BookingRules::fromRow($row),
            'saved' => $row !== null,
            'sms_available' => $this->verification->smsAvailable($tenantId),
            'can_update' => $membership->can('settings.update'),
        ];
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
