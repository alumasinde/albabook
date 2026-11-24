<?php

declare(strict_types=1);

namespace App\Modules\System\Repositories;

use App\Core\Database\Database;
use PDOException;

/**
 * Key-value store for platform ops signals written outside web requests —
 * the cron scheduler heartbeat and the backup success timestamp — so
 * /health can report freshness without touching storage volumes.
 */
final class PlatformStateRepository
{
    public const SCHEDULER_LAST_RUN = 'scheduler.last_run_at';
    public const BACKUP_LAST_OK = 'backup.last_ok_at';

    public function __construct(private readonly Database $db) {}

    public function get(string $name): ?string
    {
        try {
            $stmt = $this->db->pdo()->prepare('SELECT value FROM platform_state WHERE name = ?');
            $stmt->execute([$name]);
        } catch (PDOException) {
            // Table may not exist yet on a pre-migration deploy.
            return null;
        }

        $value = $stmt->fetchColumn();

        return is_string($value) ? $value : null;
    }

    /** @return array<string, string> name => value for every key under $prefix. */
    public function allLike(string $prefix): array
    {
        try {
            $stmt = $this->db->pdo()->prepare('SELECT name, value FROM platform_state WHERE name LIKE ? ORDER BY name');
            $stmt->execute([$prefix . '%']);
        } catch (PDOException) {
            return [];
        }

        /** @var array<string, string> */
        return array_column($stmt->fetchAll(), 'value', 'name');
    }

    /** Records the current UTC time for the named signal. */
    public function touch(string $name): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO platform_state (name, value) VALUES (?, UTC_TIMESTAMP(6))
             ON DUPLICATE KEY UPDATE value = VALUES(value)',
        )->execute([$name]);
    }
}
