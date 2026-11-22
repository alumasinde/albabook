<?php

declare(strict_types=1);

namespace App\Modules\Api\Repositories;

use App\Core\Database\Database;

/**
 * api_credentials (decisions.md D-008, plan.md §12.4): tenant-scoped private
 * API keys. The plaintext secret is shown once at creation; only its
 * SHA-256 hash is stored. Bearer tokens arrive as "{public_id}.{secret}".
 */
final class ApiCredentialRepository
{
    public function __construct(private readonly Database $db) {}

    /**
     * Credential looked up by the public half of a bearer token, then
     * verified against the secret hash by the caller (timing-safe
     * hash_equals) and checked for revocation/expiry.
     * @return array<string, mixed>|null
     */
    public function findByPublicId(string $publicId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, uuid, tenant_id, public_id, secret_hash, scopes, expires_at, revoked_at
             FROM api_credentials WHERE public_id = :p',
        );
        $stmt->execute(['p' => $publicId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Tenant-scoped lookup by uuid — for management operations.
     * @return array<string, mixed>|null
     */
    public function findByUuid(int $tenantId, string $uuid): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, uuid, tenant_id, public_id, scopes, expires_at, revoked_at
             FROM api_credentials WHERE tenant_id = :t AND uuid = :u',
        );
        $stmt->execute(['t' => $tenantId, 'u' => $uuid]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** Key rotation: replace public_id + secret_hash in place (uuid preserved). */
    public function updateSecret(int $tenantId, int $credentialId, string $publicId, string $secretHash): void
    {
        $this->db->pdo()->prepare(
            'UPDATE api_credentials SET public_id = :p, secret_hash = :h, last_used_at = NULL
             WHERE tenant_id = :t AND id = :id',
        )->execute(['p' => $publicId, 'h' => $secretHash, 't' => $tenantId, 'id' => $credentialId]);
    }

    /** @param array<string, mixed> $row */
    public function insert(array $row): int
    {
        $columns = array_keys($row);
        $sql = 'INSERT INTO api_credentials (' . implode(',', $columns) . ') VALUES (' . implode(',', array_map(static fn($c): string => ':' . $c, $columns)) . ')';
        $this->db->pdo()->prepare($sql)->execute($row);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    public function listForTenant(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT uuid, public_id, scopes, expires_at, revoked_at, last_used_at, created_at
             FROM api_credentials WHERE tenant_id = :t ORDER BY id DESC',
        );
        $stmt->execute(['t' => $tenantId]);

        return array_values($stmt->fetchAll());
    }

    public function revoke(int $tenantId, string $uuid): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE api_credentials SET revoked_at = UTC_TIMESTAMP()
             WHERE tenant_id = :t AND uuid = :u AND revoked_at IS NULL',
        );
        $stmt->execute(['t' => $tenantId, 'u' => $uuid]);

        return $stmt->rowCount() > 0;
    }

    /** Cheap last-used stamp — only written once per minute per credential. */
    public function touchLastUsed(int $credentialId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE api_credentials SET last_used_at = UTC_TIMESTAMP()
             WHERE id = :id AND (last_used_at IS NULL OR last_used_at < UTC_TIMESTAMP() - INTERVAL 1 MINUTE)',
        )->execute(['id' => $credentialId]);
    }
}
