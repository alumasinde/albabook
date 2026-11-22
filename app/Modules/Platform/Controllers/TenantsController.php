<?php

declare(strict_types=1);

namespace App\Modules\Platform\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Auth\PlatformPermission;
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
    private const STATUSES = ['pending', 'trial', 'active', 'suspended', 'cancelled', 'archived'];

    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly FeatureGate $features,
        private readonly UuidFactory $uuids,
        private readonly PlatformAuthContext $auth,
        private readonly AuditLogger $audit,
        private readonly View $view,
    ) {}

    /** Tenant detail: identity, subscription, lifecycle actions, feature overrides.
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $tenant = $this->tenants->findByUuid((string) $params['uuid']);
        if ($tenant === null) {
            throw HttpException::notFound('Business not found.');
        }
        $tenantId = (int) $tenant['id'];

        return $this->view->response('admin/tenant.twig', [
            'tenant' => $tenant,
            'subscription' => $this->features->subscriptionFor($tenantId),
            'plans' => $this->features->plans(),
            'overrides' => $this->features->overrides($tenantId),
            'featureKeys' => $this->features->featureKeys(),
            'statuses' => self::STATUSES,
            'canBilling' => $this->auth->can(PlatformPermission::BillingManage->value),
            'canTenants' => $this->auth->can(PlatformPermission::TenantsManage->value),
            'status' => $request->query('status', ''),
            'active_nav' => 'overview',
            'page_title' => 'Business: ' . $tenant['name'],
            'admin_email' => $this->auth->user?->email,
        ]);
    }

    /** Lifecycle transition — status policy decides what the tenant can still do.
     * @param array<string, string> $params
     */
    public function updateStatus(Request $request, array $params): Response
    {
        $tenant = $this->tenants->findByUuid((string) $params['uuid']);
        $status = trim((string) $request->input('status', ''));
        if ($tenant === null || !in_array($status, self::STATUSES, true)) {
            return Response::redirect('/dashboard?status=tenant_invalid', 303);
        }

        $before = (string) $tenant['status'];
        $this->tenants->setStatus((int) $tenant['id'], $status);
        $this->audit->record(new AuditEntry(
            action: AuditAction::TENANT_STATUS_CHANGED,
            tenantId: (int) $tenant['id'],
            actorType: 'platform_user',
            actorId: $this->auth->user?->id,
            targetType: 'tenant',
            targetId: (string) $tenant['uuid'],
            before: ['status' => $before],
            after: ['status' => $status],
            request: $request,
        ));

        return Response::redirect('/tenants/' . $tenant['uuid'] . '?status=updated', 303);
    }

    /** Create/update a per-tenant feature override (beats the plan until expiry).
     * @param array<string, string> $params
     */
    public function setOverride(Request $request, array $params): Response
    {
        $tenant = $this->tenants->findByUuid((string) $params['uuid']);
        $feature = trim((string) $request->input('feature_key', ''));
        if ($tenant === null || !in_array($feature, $this->features->featureKeys(), true)) {
            return Response::redirect('/dashboard?status=override_invalid', 303);
        }

        $state = (string) $request->input('state', 'enabled');
        $limitRaw = trim((string) $request->input('limit', ''));
        $limit = $limitRaw === '' ? null : max(0, (int) $limitRaw);
        $expiresRaw = trim((string) $request->input('expires_at', ''));
        $expiresAt = $expiresRaw === '' ? null : $expiresRaw . ' 23:59:59';
        $enabled = $state !== 'disabled';

        $this->features->setOverride((int) $tenant['id'], $feature, $enabled, $enabled ? $limit : null, $expiresAt);
        $this->audit->record(new AuditEntry(
            action: AuditAction::FEATURE_OVERRIDE_SET,
            tenantId: (int) $tenant['id'],
            actorType: 'platform_user',
            actorId: $this->auth->user?->id,
            targetType: 'tenant',
            targetId: (string) $tenant['uuid'],
            before: [],
            after: ['feature' => $feature, 'enabled' => $enabled, 'limit' => $limit, 'expires_at' => $expiresAt],
            request: $request,
        ));

        return Response::redirect('/tenants/' . $tenant['uuid'] . '?status=override_saved', 303);
    }

    /** @param array<string, string> $params */
    public function clearOverride(Request $request, array $params): Response
    {
        $tenant = $this->tenants->findByUuid((string) $params['uuid']);
        if ($tenant === null) {
            return Response::redirect('/dashboard?status=tenant_not_found', 303);
        }
        $feature = (string) $params['feature'];
        $this->features->clearOverride((int) $tenant['id'], $feature);
        $this->audit->record(new AuditEntry(
            action: AuditAction::FEATURE_OVERRIDE_CLEARED,
            tenantId: (int) $tenant['id'],
            actorType: 'platform_user',
            actorId: $this->auth->user?->id,
            targetType: 'tenant',
            targetId: (string) $tenant['uuid'],
            before: ['feature' => $feature],
            after: [],
            request: $request,
        ));

        return Response::redirect('/tenants/' . $tenant['uuid'] . '?status=override_cleared', 303);
    }

    /** End the live subscription — plan features stop applying immediately.
     * @param array<string, string> $params
     */
    public function cancelSubscription(Request $request, array $params): Response
    {
        $tenant = $this->tenants->findByUuid((string) $params['uuid']);
        if ($tenant === null) {
            return Response::redirect('/dashboard?status=tenant_not_found', 303);
        }
        $before = $this->features->subscriptionFor((int) $tenant['id']);
        $this->features->cancelSubscription((int) $tenant['id']);
        $this->audit->record(new AuditEntry(
            action: AuditAction::SUBSCRIPTION_CANCELLED,
            tenantId: (int) $tenant['id'],
            actorType: 'platform_user',
            actorId: $this->auth->user?->id,
            targetType: 'tenant',
            targetId: (string) $tenant['uuid'],
            before: $before === null ? [] : ['plan_code' => $before['plan_code'], 'status' => $before['status']],
            after: ['status' => 'cancelled'],
            request: $request,
        ));

        return Response::redirect('/tenants/' . $tenant['uuid'] . '?status=subscription_cancelled', 303);
    }

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

        return Response::redirect('/tenants/' . $tenant['uuid'] . '?status=plan_assigned', 303);
    }
}
