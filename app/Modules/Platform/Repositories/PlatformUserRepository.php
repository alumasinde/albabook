<?php

declare(strict_types=1);

namespace App\Modules\Platform\Repositories;

use App\Core\Database\Database;

/**
 * Platform administrators are a separate identity table from tenant users
 * (auth.md §10). Nothing here touches users or tenant memberships.
 */
final class PlatformUserRepository
{
    private const COLUMNS = 'id, uuid, first_name, middle_name, last_name, email, password_hash,
        status, mfa_enrolled_at, last_login_at';

    public function __construct(private readonly Database $db) {}

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM platform_users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM platform_users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => strtolower(trim($email))]);

        return $stmt->fetch() ?: null;
    }

    /**
     * @param array{first_name: string, middle_name: ?string, last_name: string,
     *              email: string, password_hash: string} $data
     */
    public function create(array $data, string $uuid): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO platform_users (uuid, first_name, middle_name, last_name, email, password_hash)
             VALUES (:uuid, :first_name, :middle_name, :last_name, :email, :password_hash)',
        )->execute(['uuid' => $uuid] + $data);

        return (int) $pdo->lastInsertId();
    }

    /** @return list<string> */
    public function permissionIdentifiers(int $platformUserId): array
    {
        $sql = 'SELECT p.identifier
                FROM platform_user_permissions pup
                INNER JOIN permissions p ON p.id = pup.permission_id AND p.scope = \'platform\'
                WHERE pup.platform_user_id = :platform_user_id
                ORDER BY p.identifier';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['platform_user_id' => $platformUserId]);

        return array_values(array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }

    public function grantPermission(int $platformUserId, string $identifier, ?int $grantedBy): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO platform_user_permissions (platform_user_id, permission_id, granted_by_platform_user_id)
             SELECT :platform_user_id, id, :granted_by FROM permissions
             WHERE scope = \'platform\' AND identifier = :identifier
             ON DUPLICATE KEY UPDATE permission_id = permission_id',
        )->execute([
            'platform_user_id' => $platformUserId,
            'granted_by' => $grantedBy,
            'identifier' => $identifier,
        ]);
    }

    public function markMfaEnrolled(int $platformUserId, string $at): void
    {
        $this->db->pdo()->prepare(
            'UPDATE platform_users SET mfa_enrolled_at = COALESCE(mfa_enrolled_at, :at) WHERE id = :id',
        )->execute(['at' => $at, 'id' => $platformUserId]);
    }

    public function updatePasswordHash(int $platformUserId, string $hash): void
    {
        $this->db->pdo()->prepare('UPDATE platform_users SET password_hash = :hash WHERE id = :id')
            ->execute(['hash' => $hash, 'id' => $platformUserId]);
    }

    public function touchLastLogin(int $platformUserId, string $at): void
    {
        $this->db->pdo()->prepare('UPDATE platform_users SET last_login_at = :at WHERE id = :id')
            ->execute(['at' => $at, 'id' => $platformUserId]);
    }
}
