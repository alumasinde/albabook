<?php

declare(strict_types=1);

namespace App\Modules\Platform\Repositories;

use App\Core\Database\Database;

final class PlatformSessionRepository
{
    public function __construct(private readonly Database $db) {}

    public function create(
        int $platformUserId,
        string $tokenHash,
        string $ipAddress,
        string $userAgent,
        ?string $mfaVerifiedAt,
    ): int {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO platform_sessions (session_token_hash, platform_user_id, ip_address, user_agent, mfa_verified_at)
             VALUES (:hash, :platform_user_id, :ip, :ua, :mfa_at)',
        )->execute([
            'hash' => $tokenHash,
            'platform_user_id' => $platformUserId,
            'ip' => $ipAddress === '' ? null : $ipAddress,
            'ua' => mb_substr($userAgent, 0, 255),
            'mfa_at' => $mfaVerifiedAt,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findActiveByHash(string $tokenHash): ?array
    {
        $sql = 'SELECT s.id AS session_id, s.session_token_hash, s.mfa_verified_at,
                       s.created_at AS session_created_at, s.last_seen_at,
                       p.id AS platform_user_id, p.uuid AS platform_user_uuid, p.email,
                       p.first_name, p.last_name, p.status AS user_status, p.mfa_enrolled_at
                FROM platform_sessions s
                INNER JOIN platform_users p ON p.id = s.platform_user_id
                WHERE s.session_token_hash = :hash AND s.revoked_at IS NULL
                LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['hash' => $tokenHash]);

        return $stmt->fetch() ?: null;
    }

    public function touch(int $sessionId, string $at): void
    {
        $this->db->pdo()->prepare('UPDATE platform_sessions SET last_seen_at = :at WHERE id = :id')
            ->execute(['at' => $at, 'id' => $sessionId]);
    }

    public function rotate(int $sessionId, string $newTokenHash): void
    {
        $this->db->pdo()->prepare('UPDATE platform_sessions SET session_token_hash = :hash WHERE id = :id')
            ->execute(['hash' => $newTokenHash, 'id' => $sessionId]);
    }

    public function markMfaVerified(int $sessionId, string $at): void
    {
        $this->db->pdo()->prepare('UPDATE platform_sessions SET mfa_verified_at = :at WHERE id = :id')
            ->execute(['at' => $at, 'id' => $sessionId]);
    }

    /** Increment the failed-MFA counter and return the new value. */
    public function incrementMfaAttempts(int $sessionId): int
    {
        $this->db->pdo()->prepare('UPDATE platform_sessions SET mfa_attempts = mfa_attempts + 1 WHERE id = :id')
            ->execute(['id' => $sessionId]);
        $stmt = $this->db->pdo()->prepare('SELECT mfa_attempts FROM platform_sessions WHERE id = :id');
        $stmt->execute(['id' => $sessionId]);

        return (int) $stmt->fetchColumn();
    }

    public function revoke(int $sessionId, string $at): void
    {
        $this->db->pdo()->prepare('UPDATE platform_sessions SET revoked_at = COALESCE(revoked_at, :at) WHERE id = :id')
            ->execute(['at' => $at, 'id' => $sessionId]);
    }
}
