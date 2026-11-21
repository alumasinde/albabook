<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Auth\Context\AuthContext;
use App\Modules\Auth\Dto\ResolvedSession;
use App\Modules\Auth\Services\SessionService;
use App\Shared\Security\AuthCookieFactory;
use App\Tenancy\TenantMembershipRepository;
use App\Tenancy\TenantRegistrationService;

/**
 * Business profile after the account is verified (plan.md §25.1). Each profile
 * creates a tenant; the owner membership comes from the role templates.
 */
final class OnboardingController
{
    public function __construct(
        private readonly View $view,
        private readonly AuthContext $auth,
        private readonly TenantMembershipRepository $memberships,
        private readonly TenantRegistrationService $registration,
        private readonly SessionService $sessions,
        private readonly AuthCookieFactory $cookies,
    ) {}

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $user = $this->auth->user;
        if ($user === null) {
            return Response::redirect('/login');
        }
        if ($this->memberships->countActiveForUser($user->id) > 0) {
            return Response::redirect('/dashboard');
        }

        return $this->view->response('onboarding/business.twig', [
            'old' => [],
            'errors' => [],
        ]);
    }

    /** @param array<string, string> $params */
    public function create(Request $request, array $params): Response
    {
        $user = $this->auth->user;
        $session = $request->attribute('auth_session');
        if ($user === null) {
            return Response::redirect('/login');
        }

        try {
            $tenant = $this->registration->register($request->body(), $user->id, $request);
        } catch (ValidationException $e) {
            return $this->view->response('onboarding/business.twig', [
                'old' => $e->old,
                'errors' => $e->errors,
            ], 422);
        }

        $response = Response::redirect('/dashboard');
        if ($session instanceof ResolvedSession) {
            $this->sessions->setActiveTenant($session->sessionId, $tenant->tenantId);
            $issued = $this->sessions->rotate($session);
            $response = $response->withCookie($this->cookies->userSession($request, $issued->token, $issued->expiresAt));
        }

        return $response;
    }
}
