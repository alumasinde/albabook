<?php

declare(strict_types=1);

namespace App\Modules\Api\Services;

use App\Core\Http\Request;
use App\Modules\Api\Repositories\ApiCredentialRepository;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditLogger;

/**
 * Revokes credentials a user minted when that identity changes its password
 * (change or reset), mirroring session and remember-token revocation.
 */
final class ApiCredentialRevoker
{
    public function __construct(
        private readonly ApiCredentialRepository $credentials,
        private readonly AuditLogger $audit,
    ) {}

    public function revokeForUser(int $userId, Request $request): int
    {
        $revoked = $this->credentials->revokeAllForUser($userId);
        if ($revoked === 0) {
            return 0;
        }

        $this->audit->record(new AuditEntry(
            action: AuditAction::API_CREDENTIALS_REVOKED,
            actorType: 'tenant_user',
            actorId: $userId,
            targetType: 'user',
            targetId: (string) $userId,
            after: ['revoked_credentials' => $revoked],
            request: $request,
        ));

        return $revoked;
    }
}
