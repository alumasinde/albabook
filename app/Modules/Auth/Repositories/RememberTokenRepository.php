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
                WHERE r.token_hash = :hash AND r.used_at IS NULL
                  AND r.revoked_at IS NULL AND r.expires_at > UTC_TIMESTAMP()
                LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['hash' => $tokenHash]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Any state, by hash — lets the caller tell a replayed (already spent)
     * cookie from random garbage.
     * @return array<string, mixed>|null
     */
    public function findByHash(string $tokenHash): ?array
    {
        $sql = 'SELECT r.id AS remember_id, r.user_id, r.expires_at, r.used_at, r.revoked_at,
                       u.status AS user_status
                FROM remember_tokens r
                INNER JOIN users u ON u.id = r.user_id
                WHERE r.token_hash = :hash
                LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['hash' => $tokenHash]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Rotate a chain: stamp the presented row used and append its successor
     * in one transaction. Returns false when another request already claimed
     * the same token.
     */
    public function rotateChain(int $id, int $userId, string $successorHash, string $expiresAt, string $at): bool
    {
        return $this->db->transaction(function (\PDO $pdo) use ($id, $userId, $successorHash, $expiresAt, $at): bool {
            $claim = $pdo->prepare(
                'UPDATE remember_tokens SET used_at = :at
                 WHERE id = :id AND used_at IS NULL AND revoked_at IS NULL',
            );
            $claim->execute(['at' => $at, 'id' => $id]);
            if ($claim->rowCount() !== 1) {
                return false;
            }

            $pdo->prepare(
                'INSERT INTO remember_tokens (user_id, token_hash, expires_at)
                 VALUES (:user_id, :hash, :expires)',
            )->execute(['user_id' => $userId, 'hash' => $successorHash, 'expires' => $expiresAt]);

            return true;
        });
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
