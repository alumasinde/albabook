<?php

declare(strict_types=1);

namespace App\Modules\Auth\Repositories;

use App\Core\Database\Database;
use App\Shared\Support\UuidFactory;

/**
 * Invitations are tenant-scoped and bind email + roles at issue time
 * (auth.md "Invitations"). Roles are copied into tenant_invitation_roles so a
 * later role rename cannot widen an outstanding invitation.
 */
final class InvitationRepository
{
    public function __construct(
        private readonly Database $db,
        private readonly UuidFactory $uuids,
    ) {}

    /**
     * @param list<int> $roleIds
     * @return array{id: int, uuid: string}
     */
    public function create(
        int $tenantId,
        string $email,
        int $invitedByUserId,
        string $tokenHash,
        string $expiresAt,
        array $roleIds,
    ): array {
        return $this->db->transaction(function (\PDO $pdo) use ($tenantId, $email, $invitedByUserId, $tokenHash, $expiresAt, $roleIds): array {
            $uuid = $this->uuids->generate();
            $pdo->prepare(
                'INSERT INTO tenant_invitations
                    (uuid, tenant_id, email, invited_by_user_id, token_hash, expires_at)
                 VALUES (:uuid, :tenant_id, :email, :inviter, :hash, :expires)',
            )->execute([
                'uuid' => $uuid,
                'tenant_id' => $tenantId,
                'email' => $email,
                'inviter' => $invitedByUserId,
                'hash' => $tokenHash,
                'expires' => $expiresAt,
            ]);
            $invitationId = (int) $pdo->lastInsertId();

            $insertRole = $pdo->prepare(
                'INSERT INTO tenant_invitation_roles (tenant_id, invitation_id, role_id)
                 VALUES (:tenant_id, :invitation_id, :role_id)',
            );
            foreach ($roleIds as $roleId) {
                $insertRole->execute([
                    'tenant_id' => $tenantId,
                    'invitation_id' => $invitationId,
                    'role_id' => $roleId,
                ]);
            }

            return ['id' => $invitationId, 'uuid' => $uuid];
        });
    }

    /** @return array<string, mixed>|null */
    public function findValidByHash(string $tokenHash): ?array
    {
        $sql = 'SELECT i.id, i.uuid, i.tenant_id, i.email, i.invited_by_user_id, i.expires_at,
                       t.name AS tenant_name, t.slug AS tenant_slug, t.status AS tenant_status,
                       inviter.first_name AS inviter_first_name, inviter.last_name AS inviter_last_name
                FROM tenant_invitations i
                INNER JOIN tenants t ON t.id = i.tenant_id
                INNER JOIN users inviter ON inviter.id = i.invited_by_user_id
                WHERE i.token_hash = :hash
                  AND i.accepted_at IS NULL
                  AND i.revoked_at IS NULL
                  AND i.expires_at > UTC_TIMESTAMP()
                LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['hash' => $tokenHash]);

        return $stmt->fetch() ?: null;
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, uuid, tenant_id, email, invited_by_user_id, expires_at, accepted_at, revoked_at
             FROM tenant_invitations WHERE id = :id LIMIT 1',
        );
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    /** @return list<array{role_id: int, slug: string, name: string}> */
    public function rolesForInvitation(int $tenantId, int $invitationId): array
    {
        $sql = 'SELECT r.id AS role_id, r.slug, r.name
                FROM tenant_invitation_roles ir
                INNER JOIN roles r ON r.id = ir.role_id AND r.tenant_id = ir.tenant_id
                WHERE ir.tenant_id = :tenant_id AND ir.invitation_id = :invitation_id
                ORDER BY r.name';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['tenant_id' => $tenantId, 'invitation_id' => $invitationId]);

        return array_values($stmt->fetchAll());
    }

    public function isPendingForEmail(int $tenantId, string $email): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM tenant_invitations
             WHERE tenant_id = :tenant_id AND email = :email
               AND accepted_at IS NULL AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()
             LIMIT 1',
        );
        $stmt->execute(['tenant_id' => $tenantId, 'email' => $email]);

        return $stmt->fetchColumn() !== false;
    }

    public function revokePendingForEmail(int $tenantId, string $email, string $at): void
    {
        $this->db->pdo()->prepare(
            'UPDATE tenant_invitations SET revoked_at = :at
             WHERE tenant_id = :tenant_id AND email = :email
               AND accepted_at IS NULL AND revoked_at IS NULL',
        )->execute(['at' => $at, 'tenant_id' => $tenantId, 'email' => $email]);
    }

    /**
     * Claim an invitation atomically: exactly one concurrent acceptance can
     * flip accepted_at from NULL, so a double submit cannot create two
     * memberships from one link.
     */
    public function accept(int $id, string $at): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE tenant_invitations SET accepted_at = :at
             WHERE id = :id AND accepted_at IS NULL AND revoked_at IS NULL',
        );
        $stmt->execute(['at' => $at, 'id' => $id]);

        return $stmt->rowCount() === 1;
    }

    public function revoke(int $id, string $at): void
    {
        $this->db->pdo()->prepare(
            'UPDATE tenant_invitations SET revoked_at = COALESCE(revoked_at, :at)
             WHERE id = :id AND accepted_at IS NULL',
        )->execute(['at' => $at, 'id' => $id]);
    }

    /**
     * Pending invitations for the team screen.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingForTenant(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, uuid, email, expires_at, created_at
             FROM tenant_invitations
             WHERE tenant_id = :tenant_id AND accepted_at IS NULL AND revoked_at IS NULL
               AND expires_at > UTC_TIMESTAMP()
             ORDER BY created_at DESC',
        );
        $stmt->execute(['tenant_id' => $tenantId]);

        return array_values($stmt->fetchAll());
    }
}
