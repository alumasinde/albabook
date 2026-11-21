<?php

declare(strict_types=1);

namespace Tests\Integration\Tenancy;

use App\Core\Http\RequestContext;
use App\Core\Validation\ValidationException;
use App\Shared\Audit\AuditLogger;
use App\Tenancy\SlugPolicy;
use App\Tenancy\TenantResolver;
use App\Tenancy\TenantSlugService;
use Tests\Integration\Support\Phase2TestCase;

/**
 * Renames wrap their own transaction, so this class runs without the per-test
 * rollback and relies on randomly named tenants.
 */
final class TenantSlugHistoryTest extends Phase2TestCase
{
    protected function setUp(): void
    {
        $this->wrapInTransaction = false;
        parent::setUp();
    }

    public function testARenamedSlugStaysTakenAndRedirectsToTheTenant(): void
    {
        $ownerId = $this->createUser($this->uniqueEmail('owner'));
        $oldSlug = $this->uniqueSlug('old');
        $created = $this->provision($ownerId, $oldSlug);

        $resolver = new TenantResolver($this->tenants, $this->memberships);
        $service = new TenantSlugService(
            new SlugPolicy($this->config),
            $this->tenants,
            $resolver,
            $this->db,
            new AuditLogger($this->db, $this->clock, new RequestContext()),
        );

        $newSlug = $this->uniqueSlug('new');
        $context = $resolver->forUser($ownerId, $created['tenant_id']);
        $renamed = $service->rename($context, $newSlug);

        self::assertSame($newSlug, $renamed->slug);
        self::assertSame($newSlug, $this->tenants->findById($created['tenant_id'])['slug'] ?? null);
        self::assertTrue($this->tenants->slugTaken($oldSlug), 'history keeps old slugs unclaimable');
        self::assertSame($created['tenant_id'], $resolver->historic($oldSlug)?->tenantId);

        try {
            $service->assertAvailable($oldSlug);
            self::fail('The previous slug must not be claimable again.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('already taken', $e->errors['slug']);
        }

        self::assertSame($newSlug, $service->assertAvailable($newSlug, $created['tenant_id']), 'a tenant may keep its own slug');
    }

    public function testRenamingToTheCurrentSlugIsANoOp(): void
    {
        $ownerId = $this->createUser($this->uniqueEmail('owner'));
        $slug = $this->uniqueSlug('same');
        $created = $this->provision($ownerId, $slug);

        $resolver = new TenantResolver($this->tenants, $this->memberships);
        $service = new TenantSlugService(
            new SlugPolicy($this->config),
            $this->tenants,
            $resolver,
            $this->db,
            new AuditLogger($this->db, $this->clock, new RequestContext()),
        );

        $context = $resolver->forUser($ownerId, $created['tenant_id']);
        $renamed = $service->rename($context, $slug);

        self::assertSame($slug, $renamed->slug);
        self::assertFalse($this->tenants->slugTaken($slug, $created['tenant_id']));
    }
}
