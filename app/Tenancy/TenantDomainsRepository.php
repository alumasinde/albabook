<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Core\Database\Database;

/**
 * Tenant custom-domain rows (tenant_domains). Domains are unique platform-wide
 * (uq_tenant_domain) and only 'active' rows resolve a host to a tenant —
 * TenantRepository::findByDomain() is the resolution path.
 */
final class TenantDomainsRepository
{
    public const TXT_PREFIX = '_albabook-verify.';

    public function __construct(private readonly Database $db) {}

    /** @return list<array<string, mixed>> */
    public function listForTenant(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, domain, status, verified_at, last_checked_at, tls_status, is_primary, created_at
             FROM tenant_domains WHERE tenant_id = :t ORDER BY id',
        );
        $stmt->execute(['t' => $tenantId]);

        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function find(int $tenantId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, tenant_id, domain, status, verification_token_hash, verified_at,
                    last_checked_at, tls_status, is_primary
             FROM tenant_domains WHERE tenant_id = :t AND id = :id LIMIT 1',
        );
        $stmt->execute(['t' => $tenantId, 'id' => $id]);

        return $stmt->fetch() ?: null;
    }

    /** @return array<string, mixed>|null */
    public function findByDomain(string $normalisedDomain): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, tenant_id, domain, status, is_primary FROM tenant_domains WHERE domain = :d LIMIT 1',
        );
        $stmt->execute(['d' => $normalisedDomain]);

        return $stmt->fetch() ?: null;
    }

    /** @return int Row id. */
    public function insert(int $tenantId, string $domain, string $tokenHash): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO tenant_domains (tenant_id, domain, status, verification_token_hash)
             VALUES (:t, :d, \'pending_verification\', :h)',
        )->execute(['t' => $tenantId, 'd' => $domain, 'h' => $tokenHash]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function rotateToken(int $tenantId, int $id, string $tokenHash): void
    {
        $this->db->pdo()->prepare(
            'UPDATE tenant_domains SET verification_token_hash = :h
             WHERE tenant_id = :t AND id = :id',
        )->execute(['h' => $tokenHash, 't' => $tenantId, 'id' => $id]);
    }

    public function markVerified(int $tenantId, int $id): void
    {
        $this->db->pdo()->prepare(
            "UPDATE tenant_domains SET status = 'verified', verified_at = NOW(), last_checked_at = NOW()
             WHERE tenant_id = :t AND id = :id AND status = 'pending_verification'",
        )->execute(['t' => $tenantId, 'id' => $id]);
    }

    public function markCheckFailed(int $tenantId, int $id): void
    {
        $this->db->pdo()->prepare(
            'UPDATE tenant_domains SET last_checked_at = NOW() WHERE tenant_id = :t AND id = :id',
        )->execute(['t' => $tenantId, 'id' => $id]);
    }

    public function activate(int $tenantId, int $id): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "UPDATE tenant_domains SET status = 'active' WHERE tenant_id = :t AND id = :id AND status = 'verified'",
        )->execute(['t' => $tenantId, 'id' => $id]);
        // First activated domain becomes primary automatically.
        $pdo->prepare(
            'UPDATE tenant_domains d SET is_primary = TRUE WHERE d.tenant_id = :t AND d.id = :id
             AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM tenant_domains WHERE tenant_id = :t2 AND is_primary = TRUE) p)',
        )->execute(['t' => $tenantId, 'id' => $id, 't2' => $tenantId]);
        $pdo->prepare(
            "UPDATE tenant_domains SET tls_status = 'pending' WHERE tenant_id = :t AND id = :id AND tls_status = 'none'",
        )->execute(['t' => $tenantId, 'id' => $id]);
    }

    public function disable(int $tenantId, int $id): void
    {
        $this->db->pdo()->prepare(
            "UPDATE tenant_domains SET status = 'disabled' WHERE tenant_id = :t AND id = :id AND status IN ('verified', 'active')",
        )->execute(['t' => $tenantId, 'id' => $id]);
    }

    public function setPrimary(int $tenantId, int $id): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE tenant_domains SET is_primary = FALSE WHERE tenant_id = :t')->execute(['t' => $tenantId]);
        $pdo->prepare(
            "UPDATE tenant_domains SET is_primary = TRUE WHERE tenant_id = :t AND id = :id AND status = 'active'",
        )->execute(['t' => $tenantId, 'id' => $id]);
    }

    public function delete(int $tenantId, int $id): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'DELETE FROM tenant_domains WHERE tenant_id = :t AND id = :id',
        )->execute(['t' => $tenantId, 'id' => $id]);
        // If the deleted row was primary, promote the oldest remaining active one.
        $pdo->prepare(
            "UPDATE tenant_domains d SET is_primary = TRUE WHERE d.tenant_id = :t
             AND d.status = 'active'
             AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM tenant_domains WHERE tenant_id = :t2 AND is_primary = TRUE) p)
             ORDER BY d.id LIMIT 1",
        )->execute(['t' => $tenantId, 't2' => $tenantId]);
    }
}
