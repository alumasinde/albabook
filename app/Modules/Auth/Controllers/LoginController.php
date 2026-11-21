<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Auth\Context\AuthContext;
use App\Modules\Auth\Exceptions\InvalidCredentialsException;
use App\Modules\Auth\Exceptions\TooManyAttemptsException;
use App\Modules\Auth\Services\AuthenticationService;
use App\Modules\Auth\Services\RememberService;
use App\Shared\Http\LocalRedirect;
use App\Shared\Security\AuthCookieFactory;

/**
 * Password login for tenant users (auth.md §3). MFA, when enrolled, is a
 * separate step: the session exists but cannot pass TenantMiddleware until a
 * TOTP code is verified.
 */
final class LoginController
{
    public function __construct(
        private readonly View $view,
        private readonly AuthContext $auth,
        private readonly AuthenticationService $authentication,
        private readonly RememberService $remember,
        private readonly AuthCookieFactory $cookies,
    ) {}

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        if ($this->auth->user !== null) {
            return Response::redirect($this->auth->mfaPending ? '/two-factor' : '/dashboard');
        }

        return $this->view->response('auth/login.twig', [
            'email' => '',
            'error' => null,
            'next' => $request->query('next', ''),
            'status' => $request->query('status', ''),
        ]);
    }

    /** @param array<string, string> $params */
    public function login(Request $request, array $params): Response
    {
        if ($this->auth->user !== null) {
            return Response::redirect($this->auth->mfaPending ? '/two-factor' : '/dashboard');
        }

        $email = (string) $request->input('email', '');
        try {
            $outcome = $this->authentication->login($email, (string) $request->input('password', ''), $request);
        } catch (InvalidCredentialsException $e) {
            return $this->form($request, $email, $e->getMessage(), 422);
        } catch (TooManyAttemptsException $e) {
            return $this->form($request, $email, $e->getMessage(), 429)
                ->withHeader('Retry-After', (string) $e->retryAfterSeconds);
        }

        $next = LocalRedirect::next($request, '/dashboard');
        $target = $outcome->mfaPending
            ? '/two-factor' . ($next === '/dashboard' ? '' : '?next=' . urlencode($next))
            : $next;

        $response = Response::redirect($target)
            ->withCookie($this->cookies->userSession($request, $outcome->session->token, $outcome->session->expiresAt));

        if (in_array($request->input('remember'), ['1', 'on', 'yes'], true)) {
            $issued = $this->remember->issue($outcome->userId);
            $response = $response->withCookie($this->cookies->remember($request, $issued->token, $issued->expiresAt));
        }

        return $response;
    }

    private function form(Request $request, string $email, string $error, int $status): Response
    {
        return $this->view->response('auth/login.twig', [
            'email' => $email,
            'error' => $error,
            'next' => $request->input('next', ''),
            'status' => '',
        ], $status);
    }
}
