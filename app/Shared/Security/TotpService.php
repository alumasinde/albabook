<?php

declare(strict_types=1);

namespace App\Shared\Security;

use App\Core\Config\Config;
use OTPHP\TOTP;

/**
 * RFC 6238 TOTP with a ±1 step window and replay protection by time step
 * (auth.md §8c). Verification never returns which step matched to the caller;
 * the matched step is stored so the same code cannot be reused.
 */
final class TotpService
{
    public function __construct(private readonly Config $config) {}

    public function generateSecret(): string
    {
        return TOTP::generate()->getSecret();
    }

    public function provisioningUri(string $secret, string $account): string
    {
        $totp = $this->totp($secret);
        /** @var non-empty-string $label */
        $label = $account === '' ? $this->issuer() : $account;
        /** @var non-empty-string $issuer */
        $issuer = $this->issuer();
        $totp->setLabel($label);
        $totp->setIssuer($issuer);

        return $totp->getProvisioningUri();
    }

    /**
     * @return int|null the accepted time step, or null when the code is wrong
     */
    public function verify(string $secret, string $code, int $timestamp, ?int $lastUsedStep = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (preg_match('/^\d{' . $this->config->int('auth.mfa.digits', 6) . '}$/', $code) !== 1) {
            return null;
        }

        $period = $this->config->int('auth.mfa.period', 30);
        $leeway = $this->config->int('auth.mfa.leeway_steps', 1);
        $current = intdiv($timestamp, $period);
        $totp = $this->totp($secret);

        for ($step = $current - $leeway; $step <= $current + $leeway; $step++) {
            /** @var int<0, max> $input */
            $input = max(0, $step) * $period;
            if (hash_equals($totp->at($input), $code)) {
                if ($lastUsedStep !== null && $step <= $lastUsedStep) {
                    return null;
                }

                return $step;
            }
        }

        return null;
    }

    private function totp(string $secret): TOTP
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('A TOTP secret is required.');
        }

        return TOTP::createFromSecret($secret)
            ->withPeriod($this->config->int('auth.mfa.period', 30))
            ->withDigits($this->config->int('auth.mfa.digits', 6));
    }

    private function issuer(): string
    {
        $issuer = $this->config->string('auth.mfa.issuer', 'AlbaBook');

        return $issuer === '' ? 'AlbaBook' : $issuer;
    }
}
