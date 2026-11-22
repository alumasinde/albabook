<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\HostGroup;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;
use App\Shared\Support\HostMap;
use App\Shared\Support\TrustedProxies;
use App\Tenancy\TenantDomainResolver;
use App\Tenancy\TenantRepository;

/**
 * Establishes the trusted host, scheme and client IP, then maps the host to a
 * HostGroup. Unknown hosts are rejected here, before routing, so a forged Host
 * header can never select a route table or (later) a tenant
 * (multi-tenancy.md §7, plan.md §24).
 *
 * X-Forwarded-Host/Proto/For are honoured only when the direct peer is a
 * configured trusted proxy.
 */
final class TrustedHostMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly HostMap $hosts,
        private readonly TrustedProxies $proxies,
        private readonly RequestContext $context,
        private readonly TenantRepository $tenants,
        private readonly TenantDomainResolver $domains,
    ) {}

    public function process(Request $request, callable $next): Response
    {
        $viaProxy = $this->proxies->isTrusted($request->remoteAddress());

        $rawHost = $viaProxy && $request->header('x-forwarded-host') !== null
            ? $this->firstValue((string) $request->header('x-forwarded-host'))
            : (string) $request->header('host', '');
        $host = self::normaliseHost($rawHost);

        $group = $host === null ? null : $this->hosts->groupFor($host);

        // Verified tenant custom domains resolve to the booking host
        // (multi-tenancy.md §4). findByDomain() matches 'active' rows only, so
        // pending/failed/disabled domains are rejected the same as any
        // unknown host — the Host header is never trusted on its own.
        $customDomain = null;
        if ($group === null && $host !== null) {
            $normalised = $this->domains->normalise($host);
            if ($normalised !== null && $this->domainResolves($normalised)) {
                $group = HostGroup::Booking;
                $customDomain = $normalised;
            }
        }
        if ($group === null) {
            throw HttpException::badRequest('UNKNOWN_HOST', 'Unknown host.');
        }

        $scheme = $request->isSecure() ? 'https' : 'http';
        if ($viaProxy && $request->header('x-forwarded-proto') !== null) {
            $scheme = strtolower($this->firstValue((string) $request->header('x-forwarded-proto'))) === 'https' ? 'https' : 'http';
        }

        $clientIp = $request->remoteAddress();
        if ($viaProxy && $request->header('x-forwarded-for') !== null) {
            $clientIp = $this->clientFromForwardedFor((string) $request->header('x-forwarded-for'), $clientIp);
        }

        $this->context->hostGroup = $group;
        $this->context->customDomain = $customDomain;

        return $next(
            $request
                ->withAttribute('host', $host)
                ->withAttribute('host_group', $group)
                ->withAttribute('scheme', $scheme)
                ->withAttribute('client_ip', $clientIp),
        );
    }

    /** Verified+active tenant_domains lookup; fails closed on lookup errors. */
    private function domainResolves(string $normalisedHost): bool
    {
        try {
            return $this->tenants->findByDomain($normalisedHost) !== null;
        } catch (\PDOException) {
            return false;
        }
    }

    /** Lower-case, strip port and trailing dot; null when malformed. */
    public static function normaliseHost(string $host): ?string
    {
        $host = strtolower(trim($host));
        if ($host === '' || str_starts_with($host, '[')) {
            return null; // IPv6 literals are never valid AlbaBook hosts.
        }
        $host = preg_replace('/:\d{1,5}$/', '', $host) ?? '';
        $host = rtrim($host, '.');

        return preg_match('/^[a-z0-9.-]{1,253}$/', $host) === 1 ? $host : null;
    }

    private function firstValue(string $header): string
    {
        return trim(explode(',', $header)[0]);
    }

    /** Walk X-Forwarded-For right to left, skipping our own proxies. */
    private function clientFromForwardedFor(string $header, string $fallback): string
    {
        $chain = array_reverse(array_map('trim', explode(',', $header)));
        foreach ($chain as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                return $fallback;
            }
            if (!$this->proxies->isTrusted($ip)) {
                return $ip;
            }
        }

        return $fallback;
    }
}
