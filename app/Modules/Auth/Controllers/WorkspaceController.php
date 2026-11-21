<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Auth\Context\AuthContext;
use App\Modules\Auth\Dto\ResolvedSession;
use App\Modules\Auth\Services\SessionService;
use App\Shared\Security\AuthCookieFactory;
use App\Tenancy\TenantMembershipRepository;

/**
 * Workspace picker. The selected tenant is only accepted after checking a new
 * membership record, and the session token is regenerated on the switch
 * (multi-tenancy.md §8).
 */
final class WorkspaceController
{
    public function __construct(
        private readonly View $view,
        private readonly AuthContext $auth,
        private readonly TenantMembershipRepository $memberships,
        private readonly SessionService $sessions,
        private readonly AuthCookieFactory $cookies,
    ) {}

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $user = $this->auth->user;
        if ($user === null) {
            return Response::redirect('/login');
        }

        $active = $this->memberships->activeForUser($user->id);
        if ($active === []) {
            return Response::redirect('/onboarding/business');
        }

        return $this->view->response('workspaces/index.twig', [
            'workspaces' => $active,
            'activeTenantId' => $user->activeTenantId,
        ]);
    }

    /** @param array<string, string> $params */
    public function switch(Request $request, array $params): Response
    {
        $user = $this->auth->user;
        $session = $request->attribute('auth_session');
        if ($user === null || !$session instanceof ResolvedSession) {
            return Response::redirect('/login');
        }

        $tenantId = (int) ($params['tenant'] ?? 0);
        $target = null;
        foreach ($this->memberships->activeForUser($user->id) as $membership) {
            if ((int) $membership['tenant_id'] === $tenantId) {
                $target = $membership;
                break;
            }
        }
        if ($target === null) {
            throw HttpException::notFound('That workspace is not available to you.');
        }

        $this->sessions->setActiveTenant($session->sessionId, $tenantId);
        $issued = $this->sessions->rotate($session);

        return Response::redirect('/dashboard')
            ->withCookie($this->cookies->userSession($request, $issued->token, $issued->expiresAt));
    }
}
