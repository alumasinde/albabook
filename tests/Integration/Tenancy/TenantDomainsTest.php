<?php

declare(strict_types=1);

namespace Tests\Integration\Tenancy;

use App\Core\Config\Config;
use App\Core\Http\HostGroup;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Middleware\TrustedHostMiddleware;
use App\Shared\Support\HostMap;
use App\Shared\Support\TrustedProxies;
use App\Tenancy\TenantDomainResolver;
use App\Tenancy\TenantDomainsRepository;
use Tests\Integration\Support\Phase2TestCase;

/**
 * Custom-domain lifecycle (multi-tenancy.md §4): pending → verified → active,
 * and only 'active' rows resolve an incoming host to the booking group.
 */
final class TenantDomainsTest extends Phase2TestCase
{
    private TenantDomainsRepository $domains;

    protected function setUp(): void
    {
        parent::setUp();
        $this->domains = new TenantDomainsRepository($this->db);
    }

    private function resolver(): TenantDomainResolver
    {
        return new TenantDomainResolver(new Config([
            'app' => ['hosts' => ['app.albabook.test', 'book.albabook.test', 'api.albabook.test', 'admin.albabook.test', 'site' => 'albabook.test']],
        ]));
    }

    private function process(string $host, RequestContext $context): ?HostGroup
    {
        $middleware = new TrustedHostMiddleware(
            new HostMap(['app' => 'app.albabook.test', 'book' => 'book.albabook.test', 'api' => 'api.albabook.test', 'admin' => 'admin.albabook.test', 'site' => 'albabook.test']),
            new TrustedProxies([]),
            $context,
            $this->tenants,
            $this->resolver(),
        );

        try {
            $middleware->process(Request::create('GET', 'http://' . $host . '/'), static fn(Request $r): Response => Response::text('ok'));
        } catch (\App\Core\Http\HttpException) {
            return null;
        }

        return $context->hostGroup;
    }

    public function testPendingDomainDoesNotResolve(): void
    {
        $tenant = $this->provision($this->createUser($this->uniqueEmail('owner')));
        $domain = 'book-' . $this->uniqueSlug('x') . '.example.test';
        $this->domains->insert($tenant['tenant_id'], $domain, hash('sha256', 'tok'));

        $context = new RequestContext();
        self::assertNull($this->process($domain, $context));
        self::assertNull($context->customDomain);
    }

    public function testActiveDomainResolvesToBookingGroup(): void
    {
        $tenant = $this->provision($this->createUser($this->uniqueEmail('owner')));
        $domain = 'book-' . $this->uniqueSlug('y') . '.example.test';
        $id = $this->domains->insert($tenant['tenant_id'], $domain, hash('sha256', 'tok'));

        $this->domains->markVerified($tenant['tenant_id'], $id);
        // Verified but not yet active still must not resolve.
        self::assertNull($this->process($domain, new RequestContext()));

        $this->domains->activate($tenant['tenant_id'], $id);
        $context = new RequestContext();
        self::assertSame(HostGroup::Booking, $this->process($domain, $context));
        self::assertSame($domain, $context->customDomain);
        $tenantRow = $this->tenants->findById($tenant['tenant_id']);
        self::assertNotNull($tenantRow);
        $resolved = $this->tenants->findByDomain($domain);
        self::assertNotNull($resolved);
        self::assertSame($tenantRow['slug'], $resolved['slug']);
    }

    public function testDisabledDomainStopsResolving(): void
    {
        $tenant = $this->provision($this->createUser($this->uniqueEmail('owner')));
        $domain = 'book-' . $this->uniqueSlug('z') . '.example.test';
        $id = $this->domains->insert($tenant['tenant_id'], $domain, hash('sha256', 'tok'));
        $this->domains->markVerified($tenant['tenant_id'], $id);
        $this->domains->activate($tenant['tenant_id'], $id);
        self::assertSame(HostGroup::Booking, $this->process($domain, new RequestContext()));

        $this->domains->disable($tenant['tenant_id'], $id);
        self::assertNull($this->process($domain, new RequestContext()));
    }

    public function testDomainIsUniqueAcrossTenants(): void
    {
        $a = $this->provision($this->createUser($this->uniqueEmail('a')));
        $b = $this->provision($this->createUser($this->uniqueEmail('b')));
        $domain = 'book-' . $this->uniqueSlug('u') . '.example.test';
        $this->domains->insert($a['tenant_id'], $domain, hash('sha256', 't1'));
        self::assertNotNull($this->domains->findByDomain($domain));

        $this->expectException(\PDOException::class);
        $this->domains->insert($b['tenant_id'], $domain, hash('sha256', 't2'));
    }

    public function testPlatformHostsCannotBeRegisteredAsDomains(): void
    {
        self::assertNull($this->resolver()->normalise('app.albabook.test'));
        self::assertNull($this->resolver()->normalise('https://evil.test/path'));
        self::assertNull($this->resolver()->normalise(''));
        self::assertSame('bookings.example.test', $this->resolver()->normalise(' Bookings.Example.test '));
        self::assertSame('bookings.example.test', $this->resolver()->normalise('bookings.example.test:8443'));
    }
}
