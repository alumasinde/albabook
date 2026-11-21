<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Auth\Context\AuthContext;
use App\Modules\Auth\Dto\MfaEnrollment;
use App\Modules\Auth\Dto\ResolvedSession;
use App\Modules\Auth\Exceptions\MfaFailedException;
use App\Modules\Auth\Mfa\MfaSubject;
use App\Modules\Auth\Services\MfaService;
use App\Modules\Auth\Services\SessionService;
use App\Shared\Security\AuthCookieFactory;

/**
 * TOTP setup for a tenant user. Recovery codes are returned exactly once, in
 * the confirming response; they are stored only as hashes (auth.md §8c).
 */
final class TwoFactorEnrollmentController
{
    public function __construct(
        private readonly View $view,
        private readonly AuthContext $auth,
        private readonly MfaService $mfa,
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
        if ($this->mfa->hasConfirmedFactor(MfaSubject::User, $user->id)) {
            return Response::redirect($user->mfaVerified ? '/security' : '/two-factor');
        }

        $enrollment = $this->mfa->beginEnrollment(MfaSubject::User, $user->id, $user->email);

        return $this->enrollmentPage($enrollment, $user->email, null);
    }

    /** @param array<string, string> $params */
    public function confirm(Request $request, array $params): Response
    {
        $user = $this->auth->user;
        if ($user === null) {
            return Response::redirect('/login');
        }

        $factorId = (int) $request->input('factor_id', 0);

        try {
            $codes = $this->mfa->confirmEnrollment(
                MfaSubject::User,
                $user->id,
                $factorId,
                (string) $request->input('code', ''),
                $request,
            );
        } catch (MfaFailedException $e) {
            $fresh = $this->mfa->beginEnrollment(MfaSubject::User, $user->id, $user->email);

            return $this->enrollmentPage($fresh, $user->email, $e->getMessage());
        }

        $session = $request->attribute('auth_session');
        if ($session instanceof ResolvedSession) {
            $this->sessions->markMfaVerified($session->sessionId);
            $issued = $this->sessions->rotate($session);
            $response = $this->view->response('auth/recovery-codes.twig', ['codes' => $codes]);
            return $response->withCookie($this->cookies->userSession($request, $issued->token, $issued->expiresAt));
        }

        return $this->view->response('auth/recovery-codes.twig', ['codes' => $codes]);
    }

    private function enrollmentPage(MfaEnrollment $enrollment, string $email, ?string $error): Response
    {
        return $this->view->response('auth/two-factor-enroll.twig', [
            'secret' => $enrollment->secret,
            'uri' => $enrollment->provisioningUri,
            'factor_id' => $enrollment->factorId,
            'email' => $email,
            'error' => $error,
        ], $error === null ? 200 : 422);
    }
}
