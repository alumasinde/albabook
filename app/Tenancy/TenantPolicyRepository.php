<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Core\Database\Database;

final class TenantPolicyRepository
{
    public function __construct(private readonly Database $db) {}

    /** @return list<array{status: string, operation: string, allowed: bool}> */
    public function all(): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT status, operation, allowed FROM tenant_status_policies');
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return array_values(array_map(static fn(array $row): array => [
            'status' => (string) $row['status'],
            'operation' => (string) $row['operation'],
            'allowed' => (bool) $row['allowed'],
        ], $rows));
    }
}
