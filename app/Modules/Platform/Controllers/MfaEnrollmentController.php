<?php

declare(strict_types=1);

namespace App\Modules\Platform\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Auth\Dto\MfaEnrollment;
use App\Modules\Auth\Exceptions\MfaFailedException;
use App\Modules\Auth\Mfa\MfaSubject;
use App\Modules\Auth\Services\MfaService;
use App\Modules\Platform\Context\PlatformAuthContext;
use App\Modules\Platform\Dto\ResolvedPlatformSession;
use App\Modules\Platform\Services\PlatformAuthenticationService;
use App\Shared\Security\AuthCookieFactory;

/**
 * Mandatory TOTP enrolment for platform administrators (auth.md §10). Until a
 * factor is confirmed and verified, no admin page is reachable.
 */
final class MfaEnrollmentController
{
    public function __construct(
        private readonly View $view,
        private readonly PlatformAuthContext $auth,
        private readonly MfaService $mfa,
        private readonly PlatformAuthenticationService $authentication,
        private readonly AuthCookieFactory $cookies,
    ) {}

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $user = $this->auth->user;
        if ($user === null) {
            return Response::redirect('/login');
        }
        if ($user->mfaEnrolled) {
            return Response::redirect('/two-factor');
        }

        $enrollment = $this->mfa->beginEnrollment(MfaSubject::PlatformUser, $user->id, $user->email);

        return $this->page($enrollment, null);
    }

    /** @param array<string, string> $params */
    public function confirm(Request $request, array $params): Response
    {
        $user = $this->auth->user;
        $session = $request->attribute('platform_session');
        if ($user === null) {
            return Response::redirect('/login');
        }
        if (!$session instanceof ResolvedPlatformSession) {
            return Response::redirect('/login');
        }

        try {
            $codes = $this->mfa->confirmEnrollment(
                MfaSubject::PlatformUser,
                $user->id,
                (int) $request->input('factor_id', 0),
                (string) $request->input('code', ''),
                $request,
            );
        } catch (MfaFailedException $e) {
            $fresh = $this->mfa->beginEnrollment(MfaSubject::PlatformUser, $user->id, $user->email);

            return $this->page($fresh, $e->getMessage());
        }

        $issued = $this->authentication->completeMfa($session);

        return $this->view->response('admin/recovery-codes.twig', ['codes' => $codes])
            ->withCookie($this->cookies->platformSession($request, $issued->token, $issued->expiresAt));
    }

    private function page(MfaEnrollment $enrollment, ?string $error): Response
    {
        return $this->view->response('admin/mfa-enroll.twig', [
            'secret' => $enrollment->secret,
            'uri' => $enrollment->provisioningUri,
            'factor_id' => $enrollment->factorId,
            'error' => $error,
        ], $error === null ? 200 : 422);
    }
}
