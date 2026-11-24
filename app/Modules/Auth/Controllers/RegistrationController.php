<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Core\Config\Config;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Auth\Services\RegistrationService;
use App\Shared\Security\RateLimiter;

/**
 * Creates the account only. Duplicate emails and fresh sign-ups see the same
 * response, so the page cannot be used to enumerate accounts (auth.md §8b).
 */
final class RegistrationController
{
    public function __construct(
        private readonly View $view,
        private readonly RegistrationService $registration,
        private readonly RateLimiter $limiter,
        private readonly Config $config,
    ) {}

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        return $this->view->response('auth/register.twig', [
            'old' => [],
            'errors' => [],
            'sent' => $request->query('status') === 'sent',
        ]);
    }

    /** @param array<string, string> $params */
    public function register(Request $request, array $params): Response
    {
        // Every registration emails the given address, so unthrottled sign-up
        // is an email-bombing and account-farming tool. Per IP (IPv6 by /64)
        // and per target address, so a botnet cannot flood one victim either.
        $email = strtolower(trim((string) $request->input('email', '')));
        $window = 3600;
        $byIp = $this->limiter->hit(
            'register:ip:' . RateLimiter::clientKey($request->clientIp() ?: 'unknown'),
            $this->config->int('auth.login.register_max_per_ip_per_hour', 5),
            $window,
        );
        $byEmail = $email === '' ? null : $this->limiter->hit(
            'register:email:' . hash('sha256', $email),
            $this->config->int('auth.login.register_max_per_email_per_hour', 3),
            $window,
        );
        if (!$byIp->allowed || ($byEmail !== null && !$byEmail->allowed)) {
            $retry = max($byIp->retryAfterSeconds, $byEmail === null ? 0 : $byEmail->retryAfterSeconds);

            return $this->view->response('auth/register.twig', [
                'old' => array_diff_key($request->body(), ['password' => 1, 'password_confirmation' => 1]),
                'errors' => ['email' => 'Too many sign-up attempts. Please try again later.'],
                'sent' => false,
            ], 429)->withHeader('Retry-After', (string) $retry);
        }

        try {
            $this->registration->register($request->body(), $request);
        } catch (ValidationException $e) {
            return $this->view->response('auth/register.twig', [
                'old' => $e->old,
                'errors' => $e->errors,
                'sent' => false,
            ], 422);
        }

        return Response::redirect('/register?status=sent');
    }
}
