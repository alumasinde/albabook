<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Auth\Dto\ResolvedSession;
use App\Modules\Auth\Mfa\MfaSubject;
use App\Modules\Auth\Services\MfaService;
use App\Modules\Auth\Services\SessionService;
use App\Shared\Http\LocalRedirect;
use App\Shared\Security\AuthCookieFactory;

/**
 * Second factor for a password-authenticated session. The accepted code is
 * recorded so it can never be replayed (auth.md §8c).
 */
final class TwoFactorController
{
    public function __construct(
        private readonly View $view,
        private readonly MfaService $mfa,
        private readonly SessionService $sessions,
        private readonly AuthCookieFactory $cookies,
    ) {}

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $session = $this->session($request);
        if ($session === null) {
            return Response::redirect('/login');
        }
        if ($session->mfaVerified) {
            return Response::redirect(LocalRedirect::next($request, '/dashboard'));
        }
        if (!$this->mfa->hasConfirmedFactor(MfaSubject::User, $session->userId)) {
            return Response::redirect('/two-factor/enroll');
        }

        return $this->view->response('auth/two-factor.twig', [
            'error' => null,
            'next' => LocalRedirect::next($request, '/dashboard'),
        ]);
    }

    /** @param array<string, string> $params */
    public function verify(Request $request, array $params): Response
    {
        $session = $this->session($request);
        if ($session === null) {
            return Response::redirect('/login');
        }

        $code = (string) $request->input('code', '');
        if (!$this->mfa->verify(MfaSubject::User, $session->userId, $code, $request)) {
            return $this->view->response('auth/two-factor.twig', [
                'error' => 'That code is not correct. Try again or use a recovery code.',
                'next' => LocalRedirect::next($request, '/dashboard'),
            ], 422);
        }

        $this->sessions->markMfaVerified($session->sessionId);
        $issued = $this->sessions->rotate($session);

        return Response::redirect(LocalRedirect::next($request, '/dashboard'))
            ->withCookie($this->cookies->userSession($request, $issued->token, $issued->expiresAt));
    }

    private function session(Request $request): ?ResolvedSession
    {
        $session = $request->attribute('auth_session');

        return $session instanceof ResolvedSession ? $session : null;
    }
}
