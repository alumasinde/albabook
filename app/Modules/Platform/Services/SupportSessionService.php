<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Auth\PlatformPermission;
use App\Core\Config\Config;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Modules\Platform\Context\PlatformAuthContext;
use App\Modules\Platform\Repositories\SupportSessionRepository;
use App\Modules\Platform\Support\SupportEntryToken;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditLogger;
use App\Shared\Support\UuidFactory;
use App\Shared\Time\Clock;
use App\Tenancy\Support\SupportAccess;
use App\Tenancy\Support\SupportSessionLocator;
use App\Tenancy\TenantRepository;
use App\Tenancy\TenantSettingsService;

/**
 * Audited, time-boxed support access (decisions.md D-011). Write scope needs
 * both the platform permission and the tenant's own opt-in, re-checked on
 * every request so a tenant can revoke it mid-session.
 */
final class SupportSessionService implements SupportSessionLocator
{
    public function __construct(
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly UuidFactory $uuids,
        private readonly SupportSessionRepository $sessions,
        private readonly TenantRepository $tenants,
        private readonly TenantSettingsService $settings,
        private readonly SupportEntryToken $tokens,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{id: int, uuid: string, token: string, expires_at: int, write_allowed: bool, tenant_slug: string}
     * @throws HttpException
     */
    public function start(
        PlatformAuthContext $admin,
        int $tenantId,
        string $reason,
        int $minutes,
        bool $write,
        Request $request,
    ): array {
        $user = $admin->user;
        if ($user === null) {
            throw new HttpException(401, 'UNAUTHENTICATED', 'Sign in to continue.');
        }
        if (!$admin->can(PlatformPermission::SupportRead->value)) {
            throw new HttpException(403, 'FORBIDDEN', 'You cannot start support sessions.');
        }
        if ($write && !$admin->can(PlatformPermission::SupportWrite->value)) {
            throw new HttpException(403, 'FORBIDDEN', 'You do not have write support access.');
        }

        $tenant = $this->tenants->findById($tenantId);
        if ($tenant === null) {
            throw new HttpException(404, 'TENANT_NOT_FOUND', 'That business could not be found.');
        }

        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw new HttpException(422, 'INVALID_REASON', 'Describe the reason for access in 10–500 characters.');
        }

        $minutes = $this->clampMinutes($minutes);
        $writeAllowed = $write && $this->settings->supportWriteEnabled($tenantId);

        $uuid = $this->uuids->generate();
        $now = $this->clock->now();
        $expiresAt = $now->modify("+{$minutes} minutes");

        $sessionId = $this->sessions->create(
            $uuid,
            $user->id,
            $tenantId,
            $reason,
            $writeAllowed ? 'write' : 'read',
            $expiresAt->format('Y-m-d H:i:s'),
        );

        $this->audit->record(new AuditEntry(
            action: AuditAction::SUPPORT_STARTED,
            tenantId: $tenantId,
            actorType: 'platform_user',
            actorId: $user->id,
            targetType: 'support_session',
            targetId: $uuid,
            after: [
                'reason' => $reason,
                'scope' => $writeAllowed ? 'write' : 'read',
                'minutes' => $minutes,
            ],
            request: $request,
        ));

        return [
            'id' => $sessionId,
            'uuid' => $uuid,
            'token' => $this->tokens->issue($uuid, $expiresAt->getTimestamp(), $writeAllowed),
            'expires_at' => $expiresAt->getTimestamp(),
            'write_allowed' => $writeAllowed,
            'tenant_slug' => (string) $tenant['slug'],
        ];
    }

    public function end(PlatformAuthContext $admin, int $sessionId, Request $request): void
    {
        $user = $admin->user;
        if ($user === null) {
            throw new HttpException(401, 'UNAUTHENTICATED', 'Sign in to continue.');
        }

        $session = $this->sessions->findForAdmin($sessionId, $user->id);
        if ($session === null) {
            throw new HttpException(404, 'SUPPORT_SESSION_NOT_FOUND', 'That support session was not found.');
        }
        if ($session['ended_at'] !== null) {
            return;
        }

        $this->sessions->end($sessionId, $this->clock->now()->format('Y-m-d H:i:s'));
        $this->audit->record(new AuditEntry(
            action: AuditAction::SUPPORT_ENDED,
            tenantId: (int) $session['tenant_id'],
            actorType: 'platform_user',
            actorId: $user->id,
            targetType: 'support_session',
            targetId: (string) $session['uuid'],
            request: $request,
        ));
    }

    /** @return list<array<string, mixed>> */
    public function recentForAdmin(PlatformAuthContext $admin): array
    {
        return $admin->user === null ? [] : $this->sessions->recentForAdmin($admin->user->id);
    }

    public function locate(Request $request): ?SupportAccess
    {
        $token = $request->cookie($this->config->string(
            $request->isSecure() ? 'security.support.cookie_secure' : 'security.support.cookie_insecure',
            'albabook_support',
        ));

        return $token === null || $token === '' ? null : $this->locateToken($token);
    }

    public function locateToken(string $token): ?SupportAccess
    {
        $claims = $this->tokens->parse($token);
        if ($claims === null) {
            return null;
        }

        $row = $this->sessions->findActiveByUuid($claims['uuid']);
        if ($row === null) {
            return null;
        }

        $expiresAt = new \DateTimeImmutable((string) $row['expires_at'], new \DateTimeZone('UTC'));
        if ($expiresAt->getTimestamp() <= $this->clock->now()->getTimestamp()) {
            return null;
        }

        $writeAllowed = $claims['write']
            && (string) $row['scope'] === 'write'
            && $this->settings->supportWriteEnabled((int) $row['tenant_id']);

        return new SupportAccess(
            sessionId: (int) $row['id'],
            uuid: (string) $row['uuid'],
            tenantId: (int) $row['tenant_id'],
            reason: (string) $row['reason'],
            expiresAt: $expiresAt,
            writeAllowed: $writeAllowed,
        );
    }

    private function clampMinutes(int $minutes): int
    {
        $default = $this->config->int('tenancy.support.default_minutes', 60);
        $max = $this->config->int('tenancy.support.max_minutes', 240);
        if ($minutes <= 0) {
            return $default;
        }

        return max(5, min($max, $minutes));
    }
}
