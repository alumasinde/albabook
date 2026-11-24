<?php

declare(strict_types=1);

namespace Tests\Integration\Tenancy;

use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Validation\ValidationException;
use App\Core\Validation\Validator;
use App\Modules\Billing\Services\FeatureGate;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditLogger;
use App\Tenancy\SlugPolicy;
use App\Tenancy\TenantProvisioningService;
use App\Tenancy\TenantRegistrationService;
use App\Tenancy\TenantResolver;
use App\Tenancy\TenantSlugService;
use Tests\Integration\Support\Phase2TestCase;

/**
 * Wraps real transactions, so this class cannot use the per-test rollback.
 * Every test creates its own randomly named tenant and user.
 */
final class TenantRegistrationTest extends Phase2TestCase
{
    protected function setUp(): void
    {
        $this->wrapInTransaction = false;
        parent::setUp();
    }

    public function testRegistrationCreatesAPendingTenantOwnedByTheRegistrant(): void
    {
        $ownerId = $this->createUser($this->uniqueEmail('owner'));
        $slug = $this->uniqueSlug('bella');

        $tenant = $this->service()->register(
            ['name' => 'Bella Beauty', 'slug' => $slug, 'timezone' => 'Africa/Nairobi', 'currency' => 'kes'],
            $ownerId,
            $this->request(),
        );

        self::assertSame('pending', $tenant->status, 'a new business starts pending review');
        self::assertSame($slug, $tenant->slug);
        self::assertSame('KES', $tenant->currency, 'codes are upper-cased');
        self::assertSame('tenant_user', $tenant->actorType);

        $membership = $this->memberships->findActive($ownerId, $tenant->tenantId);
        self::assertNotNull($membership);
        self::assertSame(
            ['owner'],
            array_column($this->roles->roleSummariesForMembership($tenant->tenantId, (int) $membership['id']), 'slug'),
        );

        $audit = $this->pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE action = :action AND tenant_id = :tenant_id');
        $audit->execute(['action' => AuditAction::TENANT_REGISTERED, 'tenant_id' => $tenant->tenantId]);
        self::assertSame(1, (int) $audit->fetchColumn(), 'registration is audited');

        // A trial subscription is started when the trial plan exists, so the
        // business never runs on default-permit feature rules.
        $trialPlan = $this->pdo->query("SELECT id FROM plans WHERE code = 'trial' AND is_active = 1")->fetchColumn();
        $subs = $this->pdo->prepare('SELECT status, plan_id FROM tenant_subscriptions WHERE tenant_id = :t');
        $subs->execute(['t' => $tenant->tenantId]);
        $rows = $subs->fetchAll();
        if ($trialPlan !== false) {
            self::assertCount(1, $rows);
            self::assertSame('trialing', $rows[0]['status']);
            self::assertSame((int) $trialPlan, (int) $rows[0]['plan_id']);
        } else {
            self::assertSame([], $rows);
        }
    }

    public function testPreviouslyUsedAndReservedSlugsAreRejected(): void
    {
        $ownerId = $this->createUser($this->uniqueEmail('owner'));
        $slug = $this->uniqueSlug('taken');
        $this->service()->register(['name' => 'First', 'slug' => $slug], $ownerId, $this->request());

        $otherOwner = $this->createUser($this->uniqueEmail('other'));

        foreach ([[$slug, 'already taken'], ['admin', 'reserved'], ['not a slug', 'lowercase letters']] as [$candidate, $expected]) {
            try {
                $this->service()->register(['name' => 'Second', 'slug' => $candidate], $otherOwner, $this->request());
                self::fail("Expected \"{$candidate}\" to be rejected.");
            } catch (ValidationException $e) {
                self::assertArrayHasKey('slug', $e->errors);
                self::assertStringContainsString($expected, $e->errors['slug']);
            }
        }
    }

    public function testTimeZoneAndCurrencyAreValidatedBeforeAnythingIsWritten(): void
    {
        $ownerId = $this->createUser($this->uniqueEmail('owner'));

        try {
            $this->service()->register(
                ['name' => 'Bad Settings', 'slug' => $this->uniqueSlug('bad'), 'timezone' => 'Nairobi', 'currency' => 'KS'],
                $ownerId,
                $this->request(),
            );
            self::fail('Expected validation to fail.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('timezone', $e->errors);
            self::assertArrayHasKey('currency', $e->errors);
        }

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM tenants WHERE name = :name');
        $count->execute(['name' => 'Bad Settings']);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    private function service(): TenantRegistrationService
    {
        $resolver = new TenantResolver($this->tenants, $this->memberships);
        $audit = new AuditLogger($this->db, $this->clock, new RequestContext());

        return new TenantRegistrationService(
            $this->config,
            $this->db,
            new Validator(),
            new TenantSlugService(new SlugPolicy($this->config), $this->tenants, $resolver, $this->db, $audit),
            new TenantProvisioningService($this->db, $this->tenants, $this->roles, $this->memberships, $this->uuids),
            $resolver,
            $audit,
            new FeatureGate($this->db),
            $this->uuids,
        );
    }

    private function request(): Request
    {
        return Request::create('POST', 'http://app.albabook.test/onboarding/business');
    }
}
