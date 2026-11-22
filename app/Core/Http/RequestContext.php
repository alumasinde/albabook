<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * Request-scoped values that services outside the HTTP layer need
 * (logging, views): request id, host group, CSP nonce, CSRF token, locale.
 *
 * One instance per PHP-FPM request. CLI workers call reset() between jobs.
 * Tenant identity deliberately does NOT live here: it belongs to the
 * immutable TenantContext introduced in Phase 2 (multi-tenancy.md §5).
 */
final class RequestContext
{
    public ?string $requestId = null;
    public ?HostGroup $hostGroup = null;
    public ?string $cspNonce = null;
    public ?string $csrfToken = null;
    public string $locale = 'en';
    public bool $isSpa = false;

    /** @var array{reason: string, expires_at: int, write_allowed: bool}|null */
    public ?array $supportSession = null;

    /**
     * Verified tenant custom domain the request arrived on, when the host is
     * not one of the configured platform hosts (set by TrustedHostMiddleware).
     */
    public ?string $customDomain = null;

    public function reset(): void
    {
        $this->requestId = null;
        $this->hostGroup = null;
        $this->cspNonce = null;
        $this->csrfToken = null;
        $this->locale = 'en';
        $this->isSpa = false;
        $this->supportSession = null;
        $this->customDomain = null;
    }
}
