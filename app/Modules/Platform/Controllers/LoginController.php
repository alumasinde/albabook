<?php

declare(strict_types=1);

namespace App\Modules\Platform\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Auth\Exceptions\InvalidCredentialsException;
use App\Modules\Auth\Exceptions\TooManyAttemptsException;
use App\Modules\Platform\Context\PlatformAuthContext;
use App\Modules\Platform\Services\PlatformAuthenticationService;
use App\Shared\Security\AuthCookieFactory;

/**
 * Admin sign-in on the platform host. The session is always issued MFA-pending;
 * every admin route is gated by PlatformRequireAuthMiddleware (auth.md §10).
 */
final class LoginController
{
    public function __construct(
        private readonly View $view,
        private readonly PlatformAuthContext $auth,
        private readonly PlatformAuthenticationService $authentication,
        private readonly AuthCookieFactory $cookies,
    ) {}

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        if ($this->auth->isAuthenticated()) {
            return Response::redirect($this->destination());
        }

        return $this->view->response('admin/login.twig', [
            'email' => '',
            'error' => null,
            'status' => $request->query('status', ''),
        ]);
    }

    /** @param array<string, string> $params */
    public function login(Request $request, array $params): Response
    {
        $email = (string) $request->input('email', '');

        try {
            $outcome = $this->authentication->login($email, (string) $request->input('password', ''), $request);
        } catch (InvalidCredentialsException $e) {
            return $this->form($email, $e->getMessage(), 422);
        } catch (TooManyAttemptsException $e) {
            return $this->form($email, $e->getMessage(), 429)->withHeader('Retry-After', (string) $e->retryAfterSeconds);
        } catch (HttpException $e) {
            return $this->form($email, $e->getMessage(), $e->status);
        }

        $target = $outcome->mfaEnrolled ? '/two-factor' : '/mfa/enroll';

        return Response::redirect($target)
            ->withCookie($this->cookies->platformSession($request, $outcome->session->token, $outcome->session->expiresAt));
    }

    private function destination(): string
    {
        if (!$this->auth->mfaPending) {
            return '/dashboard';
        }

        return $this->auth->user?->mfaEnrolled === true ? '/two-factor' : '/mfa/enroll';
    }

    private function form(string $email, string $error, int $status): Response
    {
        return $this->view->response('admin/login.twig', [
            'email' => $email,
            'error' => $error,
            'status' => '',
        ], $status);
    }
}
