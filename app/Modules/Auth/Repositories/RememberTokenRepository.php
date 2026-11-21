<?php

declare(strict_types=1);

namespace App\Modules\Auth\Repositories;

use App\Core\Database\Database;

final class RememberTokenRepository
{
    public function __construct(private readonly Database $db) {}

    public function create(int $userId, string $tokenHash, string $expiresAt): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO remember_tokens (user_id, token_hash, expires_at) VALUES (:user_id, :hash, :expires)')
            ->execute(['user_id' => $userId, 'hash' => $tokenHash, 'expires' => $expiresAt]);

        return (int) $pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findActiveByHash(string $tokenHash): ?array
    {
        $sql = 'SELECT r.id AS remember_id, r.expires_at,
                       u.id AS user_id, u.uuid AS user_uuid, u.email, u.first_name, u.last_name,
                       u.email_verified_at, u.locale, u.status AS user_status
                FROM remember_tokens r
                INNER JOIN users u ON u.id = r.user_id
                WHERE r.token_hash = :hash AND r.revoked_at IS NULL AND r.expires_at > UTC_TIMESTAMP()
                LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['hash' => $tokenHash]);

        return $stmt->fetch() ?: null;
    }

    public function rotate(int $id, string $tokenHash, string $expiresAt): void
    {
        $this->db->pdo()->prepare('UPDATE remember_tokens SET token_hash = :hash, expires_at = :expires WHERE id = :id')
            ->execute(['hash' => $tokenHash, 'expires' => $expiresAt, 'id' => $id]);
    }

    public function revoke(int $id, string $at): void
    {
        $this->db->pdo()->prepare('UPDATE remember_tokens SET revoked_at = COALESCE(revoked_at, :at) WHERE id = :id')
            ->execute(['at' => $at, 'id' => $id]);
    }

    public function revokeAllForUser(int $userId, string $at): void
    {
        $this->db->pdo()->prepare(
            'UPDATE remember_tokens SET revoked_at = :at WHERE user_id = :user_id AND revoked_at IS NULL',
        )->execute(['at' => $at, 'user_id' => $userId]);
    }
}
