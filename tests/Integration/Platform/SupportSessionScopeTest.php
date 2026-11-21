<?php

declare(strict_types=1);

namespace Tests\Integration\Platform;

use App\Modules\Auth\PlatformPermission;
use App\Core\Config\ConfigValidator;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Modules\Platform\Context\PlatformAuthContext;
use App\Modules\Platform\Context\PlatformUser;
use App\Modules\Platform\Repositories\SupportSessionRepository;
use App\Modules\Platform\Services\SupportSessionService;
use App\Modules\Platform\Support\SupportEntryToken;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditLogger;
use App\Tenancy\Support\SupportAccess;
use App\Tenancy\TenantSettingsService;
use Tests\Integration\Support\Phase2TestCase;

final class SupportSessionScopeTest extends Phase2TestCase
{
    private SupportSessionService $support;
    private SupportEntryToken $tokens;
    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = $this->createUser($this->uniqueEmail('owner'));
        $this->tenantId = $this->provision($owner)['tenant_id'];

        $key = ConfigValidator::decodeKey($this->config->string('app.key'));
        self::assertNotNull($key, 'APP_KEY must be configured for support-token tests');
        $this->tokens = new SupportEntryToken($key, $this->clock);

        $this->support = new SupportSessionService(
            $this->config,
            $this->clock,
            $this->uuids,
            new SupportSessionRepository($this->db),
            $this->tenants,
            new TenantSettingsService($this->db),
            $this->tokens,
            new AuditLogger($this->db, $this->clock, new RequestContext()),
        );
    }

    /**
     * Exchanges the one-time entry code the way POST /support/enter does:
     * consume the code, mint a distinct cookie token, then locate it.
     * @param array<string, mixed> $started
     */
    private function cookieToken(array $started): ?string
    {
        $claims = $this->support->exchangeEntryCode((string) $started['code']);
        if ($claims === null) {
            return null;
        }

        return $this->tokens->issue($claims['uuid'], $claims['expires_at'], $claims['write_allowed']);
    }

    /** @param array<string, mixed> $started */
    private function enter(array $started): ?SupportAccess
    {
        $token = $this->cookieToken($started);

        return $token === null ? null : $this->support->locateToken($token);
    }

    public function testAReadSessionIsAuditedAndLocatable(): void
    {
        $admin = $this->admin([PlatformPermission::SupportRead->value]);

        $started = $this->support->start($admin, $this->tenantId, 'Investigating a missing appointment', 60, false, $this->request());

        self::assertFalse($started['write_allowed']);
        $access = $this->enter($started);
        self::assertNotNull($access);
        self::assertSame($this->tenantId, $access->tenantId);
        self::assertSame($started['id'], $access->sessionId);
        self::assertFalse($access->writeAllowed);
        self::assertSame('Investigating a missing appointment', $access->reason);

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE action = :action');
        $count->execute(['action' => AuditAction::SUPPORT_STARTED]);
        self::assertSame(1, (int) $count->fetchColumn(), 'starting support is audited');
    }

    public function testWriteScopeNeedsThePlatformPermission(): void
    {
        $readOnlyAdmin = $this->admin([PlatformPermission::SupportRead->value]);

        try {
            $this->support->start($readOnlyAdmin, $this->tenantId, 'Investigating a missing appointment', 60, true, $this->request());
            self::fail('Expected write support to be refused without the permission.');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }
    }

    public function testWriteScopeNeedsTheTenantOptInAndIsRechecked(): void
    {
        $admin = $this->admin([PlatformPermission::SupportRead->value, PlatformPermission::SupportWrite->value]);
        $this->setTenantWriteOptIn(false);

        $readOnly = $this->support->start($admin, $this->tenantId, 'Investigating a missing appointment', 60, true, $this->request());
        self::assertFalse($readOnly['write_allowed'], 'without tenant opt-in write collapses to read');

        $this->setTenantWriteOptIn(true);
        $write = $this->support->start($admin, $this->tenantId, 'Fixing a booking after the call', 60, true, $this->request());
        self::assertTrue($write['write_allowed']);
        $token = $this->cookieToken($write);
        self::assertNotNull($token);
        self::assertTrue($this->support->locateToken($token)?->writeAllowed);

        $this->setTenantWriteOptIn(false);
        self::assertFalse(
            $this->support->locateToken($token)?->writeAllowed,
            'revoking the opt-in downgrades an open session immediately',
        );
    }

    public function testEndingASessionInvalidatesItsToken(): void
    {
        $admin = $this->admin([PlatformPermission::SupportRead->value]);
        $started = $this->support->start($admin, $this->tenantId, 'Investigating a missing appointment', 60, false, $this->request());
        $token = $this->cookieToken($started);
        self::assertNotNull($token);

        $this->support->end($admin, $started['id'], $this->request());

        self::assertNull($this->support->locateToken($token));
        $this->support->end($admin, $started['id'], $this->request());

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE action = :action');
        $count->execute(['action' => AuditAction::SUPPORT_ENDED]);
        self::assertSame(1, (int) $count->fetchColumn(), 'ending is audited once');
    }

    public function testAnExpiredSessionNoLongerLocates(): void
    {
        $admin = $this->admin([PlatformPermission::SupportRead->value]);
        $started = $this->support->start($admin, $this->tenantId, 'Investigating a missing appointment', 30, false, $this->request());

        $this->clock->advance('+31 minutes');

        self::assertNull($this->enter($started));
    }

    public function testDurationIsClampedAndTheReasonIsRequired(): void
    {
        $admin = $this->admin([PlatformPermission::SupportRead->value]);

        $longest = $this->support->start($admin, $this->tenantId, 'Investigating a missing appointment', 9999, false, $this->request());
        self::assertSame($this->clock->now()->getTimestamp() + 240 * 60, $longest['expires_at']);

        $default = $this->support->start($admin, $this->tenantId, 'Investigating a missing appointment', 0, false, $this->request());
        self::assertSame($this->clock->now()->getTimestamp() + 60 * 60, $default['expires_at']);

        try {
            $this->support->start($admin, $this->tenantId, 'short', 60, false, $this->request());
            self::fail('Expected a too-short reason to be refused.');
        } catch (HttpException $e) {
            self::assertSame(422, $e->status);
        }
    }

    public function testAnUnknownTenantCannotBeEntered(): void
    {
        $admin = $this->admin([PlatformPermission::SupportRead->value]);

        try {
            $this->support->start($admin, 99_999_999, 'Investigating a missing appointment', 60, false, $this->request());
            self::fail('Expected an unknown tenant to be refused.');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status);
        }
    }

    /** @param list<string> $permissions */
    private function admin(array $permissions): PlatformAuthContext
    {
        $id = $this->insert('platform_users', [
            'uuid' => $this->uuids->generate(),
            'first_name' => 'Ada',
            'middle_name' => null,
            'last_name' => 'Admin',
            'email' => $this->uniqueEmail('admin'),
            'password_hash' => 'not-used',
            'mfa_enrolled_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);

        $context = new PlatformAuthContext();
        $context->user = new PlatformUser(
            id: $id,
            uuid: $this->uuids->generate(),
            email: 'ada@example.test',
            firstName: 'Ada',
            lastName: 'Admin',
            sessionId: 1,
            sessionToken: 'token',
            mfaVerified: true,
            mfaEnrolled: true,
        );
        $context->permissions = $permissions;

        return $context;
    }

    private function setTenantWriteOptIn(bool $enabled): void
    {
        $this->pdo->prepare('UPDATE tenant_settings SET support_write_enabled = :enabled WHERE tenant_id = :tenant_id')
            ->execute(['enabled' => $enabled ? 1 : 0, 'tenant_id' => $this->tenantId]);
    }

    private function request(): Request
    {
        return Request::create('POST', 'http://admin.albabook.test/dashboard');
    }
}
