<?php

declare(strict_types=1);

namespace App\Modules\Platform\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Billing\Services\FeatureGate;
use App\Modules\Platform\Context\PlatformAuthContext;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditLogger;
use App\Shared\Support\UuidFactory;
use App\Tenancy\TenantRepository;

/**
 * Platform-side tenant administration. Assigning a plan ends the live
 * subscription and starts a fresh monthly period — admin-only, audited
 * through the platform permission layer.
 */
final class TenantsController
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly FeatureGate $features,
        private readonly UuidFactory $uuids,
        private readonly PlatformAuthContext $auth,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, string> $params */
    public function assignPlan(Request $request, array $params): Response
    {
        $tenant = $this->tenants->findByUuid((string) $params['uuid']);
        if ($tenant === null) {
            return Response::redirect('/dashboard?status=tenant_not_found', 303);
        }

        $planCode = trim((string) $request->input('plan', ''));
        $plan = null;
        foreach ($this->features->plans() as $candidate) {
            if ($candidate['code'] === $planCode) {
                $plan = $candidate;
                break;
            }
        }
        if ($plan === null) {
            return Response::redirect('/dashboard?status=plan_invalid', 303);
        }

        $tenantId = (int) $tenant['id'];
        $before = $this->features->subscriptionFor($tenantId);
        $this->features->assignPlan($tenantId, (int) $plan['id'], $this->uuids->generate());

        $this->audit->record(new AuditEntry(
            action: AuditAction::PLAN_ASSIGNED,
            tenantId: $tenantId,
            actorType: 'platform_user',
            actorId: $this->auth->user?->id,
            targetType: 'tenant',
            targetId: (string) $tenant['uuid'],
            before: $before === null ? [] : ['plan_code' => $before['plan_code'], 'status' => $before['status']],
            after: ['plan_code' => $plan['code']],
            request: $request,
        ));

        return Response::redirect('/dashboard?status=plan_assigned', 303);
    }
}
