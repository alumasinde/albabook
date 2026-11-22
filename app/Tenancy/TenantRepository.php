<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Core\Database\Database;

/**
 * Tenant records only. Status policy, membership and settings live in their
 * own repositories (multi-tenancy.md §10).
 */
final class TenantRepository
{
    private const COLUMNS = 'id, uuid, name, slug, status, timezone, currency, default_locale';

    public function __construct(private readonly Database $db) {}

    /** @return array<string, mixed>|null */
    public function findById(int $tenantId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM tenants WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $tenantId]);

        return $stmt->fetch() ?: null;
    }

    /** @return array<string, mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM tenants WHERE uuid = :uuid LIMIT 1');
        $stmt->execute(['uuid' => $uuid]);

        return $stmt->fetch() ?: null;
    }

    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM tenants WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);

        return $stmt->fetch() ?: null;
    }

    /** @return array<string, mixed>|null */
    public function findByDomain(string $normalisedDomain): ?array
    {
        $sql = 'SELECT t.id, t.uuid, t.name, t.slug, t.status, t.timezone, t.currency, t.default_locale
                FROM tenants t
                INNER JOIN tenant_domains d ON d.tenant_id = t.id
                WHERE d.domain = :domain AND d.status = \'active\'
                LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['domain' => $normalisedDomain]);

        return $stmt->fetch() ?: null;
    }

    /**
     * @param array{uuid: string, name: string, slug: string, status: string, timezone: string, currency: string, default_locale: string} $data
     */
    public function create(array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO tenants (uuid, name, slug, status, timezone, currency, default_locale)
             VALUES (:uuid, :name, :slug, :status, :timezone, :currency, :default_locale)',
        )->execute($data);

        return (int) $pdo->lastInsertId();
    }

    public function setStatus(int $tenantId, string $status): void
    {
        $this->db->pdo()->prepare('UPDATE tenants SET status = :s WHERE id = :t')
            ->execute(['s' => $status, 't' => $tenantId]);
    }

    public function updateSlug(int $tenantId, string $slug): void
    {
        $this->db->pdo()->prepare('UPDATE tenants SET slug = :slug WHERE id = :id')
            ->execute(['slug' => $slug, 'id' => $tenantId]);
    }

    /**
     * A slug is unavailable if a tenant currently uses it or if it appears in
     * history (old slugs stay unclaimable; multi-tenancy.md §4).
     */
    public function slugTaken(string $slug, ?int $ignoreTenantId = null): bool
    {
        $sql = 'SELECT 1 FROM tenants WHERE slug = :slug'
            . ($ignoreTenantId === null ? '' : ' AND id <> :tenant_id')
            . ' UNION ALL SELECT 1 FROM tenant_slug_history WHERE slug = :history_slug LIMIT 1';
        $params = ['slug' => $slug, 'history_slug' => $slug];
        if ($ignoreTenantId !== null) {
            $params['tenant_id'] = $ignoreTenantId;
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn() !== false;
    }

    public function recordSlugHistory(int $tenantId, string $slug): void
    {
        $this->db->pdo()->prepare('INSERT INTO tenant_slug_history (tenant_id, slug) VALUES (:tenant_id, :slug)')
            ->execute(['tenant_id' => $tenantId, 'slug' => $slug]);
    }

    /** @return array<string, mixed>|null tenant that used this slug before */
    public function findHistoricTenant(string $slug): ?array
    {
        $sql = 'SELECT t.id, t.uuid, t.name, t.slug, t.status, t.timezone, t.currency, t.default_locale
                FROM tenant_slug_history h
                INNER JOIN tenants t ON t.id = h.tenant_id
                WHERE h.slug = :slug
                LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['slug' => $slug]);

        return $stmt->fetch() ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function listAll(int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ', created_at FROM tenants ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset',
        );
        $stmt->bindValue('limit', max(1, $limit), \PDO::PARAM_INT);
        $stmt->bindValue('offset', max(0, $offset), \PDO::PARAM_INT);
        $stmt->execute();

        return array_values($stmt->fetchAll());
    }

    /** Admin directory with the tenant's live plan (admin host only).
     * @return list<array<string, mixed>>
     */
    public function listAllWithSubscription(int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT t.id, t.uuid, t.name, t.slug, t.status, t.timezone, t.currency, t.default_locale, t.created_at,
                    s.status AS subscription_status, p.code AS plan_code, p.name AS plan_name
             FROM tenants t
             LEFT JOIN tenant_subscriptions s
               ON s.tenant_id = t.id AND s.status IN ('trialing','active','past_due')
             LEFT JOIN plans p ON p.id = s.plan_id
             ORDER BY t.created_at DESC, t.id DESC LIMIT :limit OFFSET :offset",
        );
        $stmt->bindValue('limit', max(1, $limit), \PDO::PARAM_INT);
        $stmt->bindValue('offset', max(0, $offset), \PDO::PARAM_INT);
        $stmt->execute();

        return array_values($stmt->fetchAll());
    }

    /** @return array<string, int> status => count */
    public function countsByStatus(): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT status, COUNT(*) AS total FROM tenants GROUP BY status');
        $stmt->execute();
        $rows = $stmt->fetchAll();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }
}
