<?php

declare(strict_types=1);

namespace App\Modules\Auth\Repositories;

use App\Core\Database\Database;

final class PasswordResetTokenRepository
{
    public function __construct(private readonly Database $db) {}

    public function create(int $userId, string $tokenHash, string $expiresAt): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (:user_id, :hash, :expires)')
            ->execute(['user_id' => $userId, 'hash' => $tokenHash, 'expires' => $expiresAt]);

        return (int) $pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findValid(string $tokenHash): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, user_id, expires_at FROM password_reset_tokens
             WHERE token_hash = :hash AND used_at IS NULL AND expires_at > UTC_TIMESTAMP() LIMIT 1',
        );
        $stmt->execute(['hash' => $tokenHash]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Single-use guard: true only for the one caller that actually flips the
     * token from unused to used. A concurrent second reset with the same
     * token gets false and must abort.
     */
    public function consume(int $id, string $at): bool
    {
        $stmt = $this->db->pdo()->prepare('UPDATE password_reset_tokens SET used_at = :at WHERE id = :id AND used_at IS NULL');
        $stmt->execute(['at' => $at, 'id' => $id]);

        return $stmt->rowCount() === 1;
    }

    public function invalidateForUser(int $userId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE password_reset_tokens SET used_at = UTC_TIMESTAMP() WHERE user_id = :user_id AND used_at IS NULL',
        )->execute(['user_id' => $userId]);
    }
}
