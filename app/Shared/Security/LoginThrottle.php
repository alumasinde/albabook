<?php

declare(strict_types=1);

namespace App\Shared\Security;

use App\Core\Config\Config;
use App\Modules\Auth\Exceptions\TooManyAttemptsException;

/**
 * Brute-force limits shared by the tenant and platform login forms
 * (auth.md §8a).
 *
 *   - Per IP (IPv6 grouped by /64): a ceiling on all attempts.
 *   - Per account: a hard ceiling on FAILED attempts in the window, applied
 *     whatever IP the guesses come from — this is what stops a distributed
 *     botnet from guessing one account's password. While the account is
 *     locked even the correct password is refused, so a guess that happens
 *     to be right leaks nothing.
 *
 * Refusals answer 429 immediately. There is deliberately no sleep(): a
 * delay holds a PHP-FPM worker per request, so an attacker could exhaust the
 * pool by hammering one email.
 */
final class LoginThrottle
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly Config $config,
    ) {}

    /** @throws TooManyAttemptsException */
    public function beforeAttempt(string $scope, string $email, string $ip): void
    {
        $ipResult = $this->limiter->hit(
            "{$scope}:login:ip:" . RateLimiter::clientKey($ip),
            $this->config->int('auth.login.ip_max_attempts', 50),
            $this->config->int('auth.login.ip_window_minutes', 15) * 60,
        );
        if (!$ipResult->allowed) {
            throw new TooManyAttemptsException($ipResult->retryAfterSeconds);
        }

        if ($email === '') {
            return;
        }
        $account = $this->limiter->peek($this->accountKey($scope, $email), $this->accountLimit(), $this->windowSeconds());
        if (!$account->allowed) {
            throw new TooManyAttemptsException($account->retryAfterSeconds);
        }
    }

    /** Count one failed attempt against the account (unknown emails too, so no oracle). */
    public function recordFailure(string $scope, string $email): void
    {
        if ($email === '') {
            return;
        }
        $this->limiter->hit($this->accountKey($scope, $email), $this->accountLimit(), $this->windowSeconds());
    }

    private function accountKey(string $scope, string $email): string
    {
        return "{$scope}:login:acct:" . hash('sha256', strtolower(trim($email)));
    }

    private function accountLimit(): int
    {
        return $this->config->int('auth.login.account_max_failures', 10);
    }

    private function windowSeconds(): int
    {
        return $this->config->int('auth.login.account_window_minutes', 15) * 60;
    }
}
