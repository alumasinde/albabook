<?php

declare(strict_types=1);

namespace App\Modules\Products\Repositories;

use App\Core\Database\Database;

/** Tenant-scoped retail products sold at the till. */
final class ProductRepository
{
    public function __construct(private readonly Database $db) {}

    /** @param array{q?: string, status?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function listForTenant(int $tenantId, array $filters): array
    {
        $where = ['tenant_id = :tid'];
        $params = ['tid' => $tenantId];
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(name LIKE :q1 OR sku LIKE :q2)';
            $params['q1'] = '%' . $q . '%';
            $params['q2'] = '%' . $q . '%';
        }
        $status = trim((string) ($filters['status'] ?? ''));
        if ($status === 'active' || $status === 'inactive') {
            $where[] = 'is_active = :active';
            $params['active'] = $status === 'active' ? 1 : 0;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT uuid, name, sku, price_minor, tax_rate_bp, currency,
                    stock_quantity, is_active
             FROM products WHERE ' . implode(' AND ', $where) . '
             ORDER BY name',
        );
        $stmt->execute($params);

        /** @var list<array<string, mixed>> */
        return array_values($stmt->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function findByUuid(int $tenantId, string $uuid): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, uuid, name, sku, price_minor, tax_rate_bp, currency,
                    stock_quantity, is_active
             FROM products WHERE tenant_id = ? AND uuid = ?',
        );
        $stmt->execute([$tenantId, $uuid]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $data */
    public function insert(int $tenantId, array $data): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO products
                (uuid, tenant_id, name, sku, price_minor, tax_rate_bp, currency, stock_quantity, is_active)
             VALUES
                (:uuid, :tid, :name, :sku, :price_minor, :tax_rate_bp, :currency, :stock_quantity, :is_active)',
        )->execute($data + ['tid' => $tenantId]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $tenantId, int $id, array $data): void
    {
        $this->db->pdo()->prepare(
            'UPDATE products SET name = :name, sku = :sku, price_minor = :price_minor,
                tax_rate_bp = :tax_rate_bp, currency = :currency,
                stock_quantity = :stock_quantity, is_active = :is_active
             WHERE tenant_id = :tid AND id = :id',
        )->execute($data + ['tid' => $tenantId, 'id' => $id]);
    }

    public function delete(int $tenantId, int $id): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM products WHERE tenant_id = ? AND id = ?',
        )->execute([$tenantId, $id]);
    }

    /** Sale lines referencing the product — blocks delete when > 0. */
    public function saleItemCount(int $tenantId, int $productId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM appointment_sale_items
             WHERE tenant_id = ? AND product_id = ?',
        );
        $stmt->execute([$tenantId, $productId]);

        return (int) $stmt->fetchColumn();
    }
}
