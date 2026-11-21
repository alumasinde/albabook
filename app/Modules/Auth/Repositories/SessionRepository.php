<?php

declare(strict_types=1);

namespace App\Modules\Auth\Repositories;

use App\Core\Database\Database;

final class SessionRepository
{
    public function __construct(private readonly Database $db) {}

    public function create(
        int $userId,
        string $tokenHash,
        ?int $activeTenantId,
        string $ipAddress,
        string $userAgent,
        ?string $mfaVerifiedAt,
    ): int {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO user_sessions (session_token_hash, user_id, active_tenant_id, ip_address, user_agent, mfa_verified_at)
             VALUES (:hash, :user_id, :tenant_id, :ip, :ua, :mfa_at)',
        )->execute([
            'hash' => $tokenHash,
            'user_id' => $userId,
            'tenant_id' => $activeTenantId,
            'ip' => $ipAddress === '' ? null : $ipAddress,
            'ua' => mb_substr($userAgent, 0, 255),
            'mfa_at' => $mfaVerifiedAt,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findActiveByHash(string $tokenHash): ?array
    {
        $sql = 'SELECT s.id AS session_id, s.session_token_hash, s.active_tenant_id, s.mfa_verified_at,
                       s.created_at AS session_created_at, s.last_seen_at, s.ip_address, s.user_agent,
                       u.id AS user_id, u.uuid AS user_uuid, u.email, u.first_name, u.last_name,
                       u.email_verified_at, u.locale, u.status AS user_status
                FROM user_sessions s
                INNER JOIN users u ON u.id = s.user_id
                WHERE s.session_token_hash = :hash AND s.revoked_at IS NULL
                LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['hash' => $tokenHash]);

        return $stmt->fetch() ?: null;
    }

    public function touch(int $sessionId, string $at): void
    {
        $this->db->pdo()->prepare('UPDATE user_sessions SET last_seen_at = :at WHERE id = :id')
            ->execute(['at' => $at, 'id' => $sessionId]);
    }

    public function rotate(int $sessionId, string $newTokenHash): void
    {
        $this->db->pdo()->prepare('UPDATE user_sessions SET session_token_hash = :hash WHERE id = :id')
            ->execute(['hash' => $newTokenHash, 'id' => $sessionId]);
    }

    public function setActiveTenant(int $sessionId, ?int $tenantId): void
    {
        $this->db->pdo()->prepare('UPDATE user_sessions SET active_tenant_id = :tenant_id WHERE id = :id')
            ->execute(['tenant_id' => $tenantId, 'id' => $sessionId]);
    }

    public function markMfaVerified(int $sessionId, string $at): void
    {
        $this->db->pdo()->prepare('UPDATE user_sessions SET mfa_verified_at = :at WHERE id = :id')
            ->execute(['at' => $at, 'id' => $sessionId]);
    }

    public function revoke(int $sessionId, string $at): void
    {
        $this->db->pdo()->prepare('UPDATE user_sessions SET revoked_at = COALESCE(revoked_at, :at) WHERE id = :id')
            ->execute(['at' => $at, 'id' => $sessionId]);
    }

    public function revokeAllForUser(int $userId, string $at, ?int $exceptSessionId = null): void
    {
        $sql = 'UPDATE user_sessions SET revoked_at = :at WHERE user_id = :user_id AND revoked_at IS NULL'
            . ($exceptSessionId === null ? '' : ' AND id <> :except_id');
        $params = ['at' => $at, 'user_id' => $userId];
        if ($exceptSessionId !== null) {
            $params['except_id'] = $exceptSessionId;
        }
        $this->db->pdo()->prepare($sql)->execute($params);
    }

    /** @return array<string, mixed>|null session owned by this user */
    public function findOwned(int $sessionId, int $userId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, ip_address, user_agent, created_at, last_seen_at, revoked_at
             FROM user_sessions WHERE id = :id AND user_id = :user_id LIMIT 1',
        );
        $stmt->execute(['id' => $sessionId, 'user_id' => $userId]);

        return $stmt->fetch() ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function listActiveForUser(int $userId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.id, s.ip_address, s.user_agent, s.created_at, s.last_seen_at, s.mfa_verified_at
             FROM user_sessions s
             WHERE s.user_id = :user_id AND s.revoked_at IS NULL
             ORDER BY s.last_seen_at DESC',
        );
        $stmt->execute(['user_id' => $userId]);

        return array_values($stmt->fetchAll());
    }
}
