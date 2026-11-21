<?php

declare(strict_types=1);

namespace App\Modules\Platform\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Auth\Mfa\MfaSubject;
use App\Modules\Auth\Services\MfaService;
use App\Modules\Platform\Context\PlatformAuthContext;
use App\Modules\Platform\Dto\ResolvedPlatformSession;
use App\Modules\Platform\Services\PlatformAuthenticationService;
use App\Modules\Platform\Services\PlatformSessionService;
use App\Shared\Security\AuthCookieFactory;

final class TwoFactorController
{
    public function __construct(
        private readonly View $view,
        private readonly PlatformAuthContext $auth,
        private readonly MfaService $mfa,
        private readonly PlatformAuthenticationService $authentication,
        private readonly PlatformSessionService $sessions,
        private readonly AuthCookieFactory $cookies,
    ) {}

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        if (!$this->auth->isAuthenticated()) {
            return Response::redirect('/login');
        }
        if (!$this->auth->mfaPending) {
            return Response::redirect('/dashboard');
        }
        if ($this->auth->user?->mfaEnrolled !== true) {
            return Response::redirect('/mfa/enroll');
        }

        return $this->view->response('admin/two-factor.twig', ['error' => null]);
    }

    /** @param array<string, string> $params */
    public function verify(Request $request, array $params): Response
    {
        $session = $request->attribute('platform_session');
        $user = $this->auth->user;
        if (!$session instanceof ResolvedPlatformSession || $user === null) {
            return Response::redirect('/login');
        }

        if (!$this->mfa->verify(MfaSubject::PlatformUser, $user->id, (string) $request->input('code', ''), $request)) {
            if ($this->sessions->recordMfaFailure($session->sessionId) >= MfaService::MAX_ATTEMPTS) {
                $this->sessions->revoke($session->sessionId);

                return Response::redirect('/login?status=mfa_locked')
                    ->withCookie($this->cookies->clearPlatformSession($request));
            }

            return $this->view->response('admin/two-factor.twig', [
                'error' => 'That code is not correct. Try again or use a recovery code.',
            ], 422);
        }

        $issued = $this->authentication->completeMfa($session);

        return Response::redirect('/dashboard')
            ->withCookie($this->cookies->platformSession($request, $issued->token, $issued->expiresAt));
    }
}
