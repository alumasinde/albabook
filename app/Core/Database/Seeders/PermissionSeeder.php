<?php

declare(strict_types=1);

namespace App\Core\Database\Seeders;

use App\Auth\PlatformPermission;
use App\Auth\TenantPermission;
use App\Core\Database\Database;

/**
 * Writes the canonical permission catalogue (plan.md §14.2). Identifiers come
 * from the enums; nothing else may define them.
 */
final class PermissionSeeder
{
    public function __construct(private readonly Database $db) {}

    public function run(): int
    {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO permissions (scope, identifier, name, description)
             VALUES (:scope, :identifier, :name, NULL)
             ON DUPLICATE KEY UPDATE name = VALUES(name)',
        );

        $count = 0;
        foreach (TenantPermission::cases() as $permission) {
            $statement->execute([
                'scope' => 'tenant',
                'identifier' => $permission->value,
                'name' => self::humanize($permission->value),
            ]);
            $count++;
        }
        foreach (PlatformPermission::cases() as $permission) {
            $statement->execute([
                'scope' => 'platform',
                'identifier' => $permission->value,
                'name' => self::humanize($permission->value),
            ]);
            $count++;
        }

        return $count;
    }

    private static function humanize(string $identifier): string
    {
        return ucfirst(str_replace('.', ' ', $identifier));
    }
}
