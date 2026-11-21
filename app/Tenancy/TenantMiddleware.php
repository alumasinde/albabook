<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;
use App\Modules\Auth\Context\AuthContext;
use App\Modules\Auth\Context\CurrentMembership;
use App\Modules\Auth\Context\MembershipContext;
use App\Modules\Auth\Mfa\MfaSubject;
use App\Modules\Auth\Repositories\MfaRepository;
use App\Modules\Auth\Services\MembershipContextFactory;
use App\Modules\Auth\Services\MfaPolicy;
use App\Modules\Auth\Services\SessionService;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Exceptions\TenantNotFoundException;
use App\Tenancy\Support\SupportAccess;
use App\Tenancy\Support\SupportSessionLocator;
use App\Tenancy\TenantAccessChecker;
use App\Tenancy\TenantMembershipRepository;
use App\Tenancy\TenantResolver;

/**
 * Resolves the active tenant for dashboard routes. The active tenant always
 * comes from the server-side session, is re-validated against tenant_users on
 * every request, and is paired with the membership permission set
 * (multi-tenancy.md §1, §7, §8).
 */
final class TenantMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthContext $auth,
        private readonly CurrentTenant $current,
        private readonly CurrentMembership $membership,
        private readonly TenantResolver $resolver,
        private readonly TenantMembershipRepository $memberships,
        private readonly MembershipContextFactory $membershipFactory,
        private readonly TenantAccessChecker $access,
        private readonly SessionService $sessions,
        private readonly MfaRepository $mfa,
        private readonly MfaPolicy $mfaPolicy,
        private readonly SupportSessionLocator $support,
        private readonly RequestContext $context,
        private readonly RoleRepository $roles,
    ) {}

    public function process(Request $request, callable $next): Response
    {
        // A valid support session outranks the browser's own login: it comes
        // from the audited record, never from a request parameter.
        $support = $this->support->locate($request);
        if ($support !== null) {
            return $this->asSupport($request, $next, $support);
        }

        $user = $this->auth->user;
        if ($user === null) {
            throw new HttpException(401, 'UNAUTHENTICATED', 'Sign in to continue.');
        }

        $tenantId = $user->activeTenantId;
        if ($tenantId === null) {
            $active = $this->memberships->activeForUser($user->id);
            if ($active === []) {
                return Response::redirect('/onboarding/business');
            }
            if (count($active) > 1) {
                return Response::redirect('/workspaces');
            }
            $tenantId = (int) $active[0]['tenant_id'];
            $this->sessions->setActiveTenant($user->sessionId, $tenantId);
        }

        try {
            $tenant = $this->resolver->forUser($user->id, $tenantId);
        } catch (TenantNotFoundException) {
            $this->sessions->setActiveTenant($user->sessionId, null);

            return Response::redirect(
                $this->memberships->countActiveForUser($user->id) === 0 ? '/onboarding/business' : '/workspaces',
            );
        }

        $this->access->assertCanAccess($tenant, 'dashboard.view');

        $membership = $this->membershipFactory->build($user->id, $tenantId);
        if ($membership === null) {
            $this->sessions->setActiveTenant($user->sessionId, null);

            return Response::redirect('/workspaces');
        }

        if (!$user->mfaVerified) {
            if ($this->mfa->hasConfirmedFactor(MfaSubject::User, $user->id)) {
                return Response::redirect('/two-factor');
            }
            if ($this->mfaPolicy->isRequiredForMembership($tenantId, $membership)) {
                return Response::redirect('/two-factor/enroll');
            }
        }

        $this->current->set($tenant);
        $this->membership->set($membership);

        return $next($request
            ->withAttribute('tenant', $tenant)
            ->withAttribute('membership', $membership));
    }

    /**
     * Support admins see the tenant workspace without a membership. The
     * context is built from the support record and downgraded to read-only
     * unless the session allows writes (multi-tenancy.md §7).
     */
    private function asSupport(Request $request, callable $next, SupportAccess $access): Response
    {
        try {
            $tenant = $this->resolver->forSupport($access);
        } catch (TenantNotFoundException) {
            throw new HttpException(403, 'SUPPORT_ACCESS_DENIED', 'That support session is no longer valid.');
        }

        if ($tenant->status === 'archived') {
            throw new HttpException(403, 'SUPPORT_ACCESS_DENIED', 'Archived businesses cannot be opened in a support session.');
        }

        if ($tenant->readOnly && !$request->isMethodSafe()) {
            throw new HttpException(403, 'SUPPORT_READ_ONLY', 'This support session is read-only.');
        }

        // Support sessions get a synthetic membership so API controllers keep
        // working: all tenant permissions when writes are allowed, *.view
        // only otherwise. tenantOperations still gates on account status —
        // support cannot act on behalf of a cancelled business.
        $permissions = $this->roles->tenantPermissionIdentifiers();
        if (!$access->writeAllowed) {
            $permissions = array_values(array_filter(
                $permissions,
                static fn(string $p): bool => str_ends_with($p, '.view') || str_ends_with($p, '.export'),
            ));
        }
        $operations = $this->access->allowedOperations($tenant->status);
        $membership = new MembershipContext(
            tenantUserId: 0,
            userId: 0,
            tenantId: $tenant->tenantId,
            roleSlugs: ['platform-support'],
            permissions: $permissions,
            branchIds: null,
            tenantOperations: $operations,
        );

        $this->current->set($tenant);
        $this->membership->set($membership);
        $this->context->supportSession = [
            'reason' => $access->reason,
            'expires_at' => $access->expiresAt->getTimestamp(),
            'write_allowed' => $access->writeAllowed,
        ];

        return $next($request
            ->withAttribute('tenant', $tenant)
            ->withAttribute('membership', $membership)
            ->withAttribute('support_access', $access));
    }
}
