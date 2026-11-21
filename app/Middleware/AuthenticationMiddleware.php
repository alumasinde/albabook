<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config\Config;
use App\Core\Http\HostGroup;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;
use App\Modules\Auth\Context\AuthContext;
use App\Modules\Auth\Context\AuthenticatedUser;
use App\Modules\Auth\Context\CurrentMembership;
use App\Modules\Auth\Mfa\MfaSubject;
use App\Modules\Auth\Repositories\MfaRepository;
use App\Modules\Auth\Services\AuthenticationService;
use App\Modules\Auth\Services\SessionService;
use App\Shared\Security\AuthCookieFactory;
use App\Shared\Security\SessionCookieNames;
use App\Shared\Time\Clock;
use App\Tenancy\CurrentTenant;

/**
 * Resolves the tenant-user session (or a remembered device) before any route
 * middleware runs. It never blocks a request; RequireAuthMiddleware does that,
 * so public auth pages can still see who is signed in.
 */
final class AuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthContext $auth,
        private readonly CurrentMembership $membership,
        private readonly CurrentTenant $tenant,
        private readonly SessionService $sessions,
        private readonly AuthenticationService $service,
        private readonly MfaRepository $mfa,
        private readonly AuthCookieFactory $cookies,
        private readonly SessionCookieNames $cookieNames,
        private readonly Config $config,
        private readonly Clock $clock,
    ) {}

    public function process(Request $request, callable $next): Response
    {
        $this->auth->reset();
        $this->membership->set(null);
        $this->tenant->set(null);

        if ($request->hostGroup() !== HostGroup::Tenant) {
            return $next($request);
        }

        $issuedCookies = [];
        $session = $this->sessions->resolve($request);

        if ($session === null && $request->isMethodSafe()) {
            $remembered = $this->service->resumeFromRemember($request);
            if ($remembered !== null) {
                $session = $remembered->session;
                $issuedCookies[] = $this->cookies->userSession($request, $remembered->session->token, $this->expiry());
                $issuedCookies[] = $this->cookies->remember($request, $remembered->remember->token, $remembered->remember->expiresAt);
            } elseif ($request->cookie($this->cookieNames->remember($request)) !== null) {
                $issuedCookies[] = $this->cookies->clearRemember($request);
            }
        }

        if ($session === null && $request->cookie($this->cookieNames->user($request)) !== null) {
            $issuedCookies[] = $this->cookies->clearUserSession($request);
        }

        if ($session !== null) {
            $this->auth->user = new AuthenticatedUser(
                id: $session->userId,
                uuid: $session->userUuid,
                email: $session->email,
                firstName: $session->firstName,
                lastName: $session->lastName,
                emailVerified: $session->emailVerified,
                locale: $session->locale,
                sessionId: $session->sessionId,
                activeTenantId: $session->activeTenantId,
                mfaVerified: $session->mfaVerified,
                sessionToken: $session->token,
            );
            $this->auth->mfaPending = !$session->mfaVerified
                && $this->mfa->hasConfirmedFactor(MfaSubject::User, $session->userId);
        }

        $response = $next($request->withAttribute('auth_session', $session));
        foreach ($issuedCookies as $cookie) {
            $response = $response->withCookie($cookie);
        }

        return $response;
    }

    private function expiry(): int
    {
        return $this->clock->now()->getTimestamp()
            + $this->config->int('security.session.absolute_timeout_minutes', 720) * 60;
    }
}
