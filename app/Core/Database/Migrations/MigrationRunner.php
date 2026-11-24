<?php

declare(strict_types=1);

namespace App\Core\Database\Migrations;

use App\Core\Database\Database;
use PDO;

/**
 * Applies database/migrations/NNN_name.sql in order and records each in
 * schema_migrations with a SHA-256 checksum.
 *
 * Guarantees:
 * - Refuses to run when an applied file has changed or disappeared. Applied
 *   migrations are immutable; fix forward with a new file.
 * - Holds a MySQL named lock so two deploys cannot migrate at once.
 * - MySQL commits each DDL statement implicitly, so a failure mid-file leaves
 *   that file partially applied. The runner stops immediately and reports the
 *   file, statement number and statement start, and does NOT record the file.
 */
final class MigrationRunner
{
    private const LOCK_NAME = 'albabook:migrations';
    private const FILE_PATTERN = '/^(\d{3,})_[a-z0-9_]+\.sql$/';

    public function __construct(
        private readonly Database $db,
        private readonly string $directory,
    ) {}

    /**
     * @param callable(string): void|null $onApplied called with each version as it completes
     * @return list<string> versions applied in this run
     */
    public function migrate(?callable $onApplied = null): array
    {
        $pdo = $this->db->pdo();
        $this->ensureTable($pdo);
        $this->lock($pdo);

        try {
            $this->assertNoDrift();
            $applied = $this->appliedVersions($pdo);
            $done = [];

            foreach ($this->files() as $version => $path) {
                if (isset($applied[$version])) {
                    continue;
                }
                $this->applyFile($pdo, $version, $path);
                $done[] = $version;
                if ($onApplied !== null) {
                    $onApplied($version);
                }
            }

            return $done;
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')");
        }
    }

    /** @return list<MigrationStatus> */
    public function status(): array
    {
        $pdo = $this->db->pdo();
        // Read-only: /health calls this as the DML-only runtime user, and
        // MySQL checks the CREATE privilege even for CREATE TABLE IF NOT
        // EXISTS. No table yet simply means every migration is pending.
        $applied = $this->tableExists($pdo) ? $this->appliedVersions($pdo) : [];
        $files = $this->files();
        $result = [];

        foreach ($files as $version => $path) {
            if (!isset($applied[$version])) {
                $result[] = new MigrationStatus($version, MigrationStatus::PENDING);
                continue;
            }
            $state = hash_equals($applied[$version]['checksum'], self::checksum($path)) ? MigrationStatus::APPLIED : MigrationStatus::MODIFIED;
            $result[] = new MigrationStatus($version, $state, $applied[$version]['applied_at']);
        }
        foreach ($applied as $version => $row) {
            if (!isset($files[$version])) {
                $result[] = new MigrationStatus($version, MigrationStatus::MISSING, $row['applied_at']);
            }
        }

        usort($result, static fn(MigrationStatus $a, MigrationStatus $b): int => strcmp($a->version, $b->version));

        return $result;
    }

    public function pendingCount(): int
    {
        return count(array_filter($this->status(), static fn(MigrationStatus $s): bool => $s->state === MigrationStatus::PENDING));
    }

    /**
     * Drop every table, view and trigger in the database. Local/testing only;
     * the console command enforces the environment check.
     */
    public function dropAll(): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $rows = $pdo->query('SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll();
            foreach ($rows as $row) {
                $name = str_replace('`', '``', (string) $row['TABLE_NAME']);
                $pdo->exec(($row['TABLE_TYPE'] === 'VIEW' ? 'DROP VIEW' : 'DROP TABLE') . " IF EXISTS `{$name}`");
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /** @return array<string, string> version => absolute path, sorted */
    public function files(): array
    {
        $files = [];
        foreach (scandir($this->directory) ?: [] as $file) {
            if (preg_match(self::FILE_PATTERN, $file) === 1) {
                $files[pathinfo($file, PATHINFO_FILENAME)] = $this->directory . DIRECTORY_SEPARATOR . $file;
            } elseif (str_ends_with($file, '.sql')) {
                throw new MigrationException("Migration file name \"{$file}\" must look like 011_add_something.sql.");
            }
        }
        ksort($files, SORT_STRING);

        $numbers = [];
        foreach (array_keys($files) as $version) {
            $number = explode('_', $version, 2)[0];
            if (isset($numbers[$number])) {
                throw new MigrationException("Two migrations share the number {$number}.");
            }
            $numbers[$number] = true;
        }

        return $files;
    }

    /** Checksum ignores line-ending differences so a Windows checkout does not look "modified". */
    public static function checksum(string $path): string
    {
        return hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents($path)));
    }

    private function applyFile(PDO $pdo, string $version, string $path): void
    {
        $statements = SqlSplitter::split((string) file_get_contents($path));
        $started = hrtime(true);

        foreach ($statements as $index => $statement) {
            try {
                $pdo->exec($statement);
            } catch (\PDOException $e) {
                $preview = mb_substr(preg_replace('/\s+/', ' ', $statement) ?? '', 0, 160);
                throw new MigrationException(sprintf(
                    "%s failed at statement %d of %d: %s\nStatement: %s\nMySQL commits DDL implicitly, so earlier statements in this file are already applied. Fix the database by hand or drop and rebuild it (local only), then re-run.",
                    $version,
                    $index + 1,
                    count($statements),
                    $e->getMessage(),
                    $preview,
                ), 0, $e);
            }
        }

        $insert = $pdo->prepare('INSERT INTO schema_migrations (version, checksum, execution_ms) VALUES (:version, :checksum, :ms)');
        $insert->execute([
            'version' => $version,
            'checksum' => self::checksum($path),
            'ms' => intdiv(hrtime(true) - $started, 1_000_000),
        ]);
    }

    private function assertNoDrift(): void
    {
        $problems = [];
        foreach ($this->status() as $status) {
            if ($status->state === MigrationStatus::MODIFIED) {
                $problems[] = "{$status->version} was changed after it was applied";
            } elseif ($status->state === MigrationStatus::MISSING) {
                $problems[] = "{$status->version} is recorded as applied but its file is missing";
            }
        }
        if ($problems !== []) {
            throw new MigrationException("Refusing to migrate:\n - " . implode("\n - ", $problems) . "\nApplied migrations are immutable; add a new migration instead.");
        }
    }

    /** @return array<string, array{checksum: string, applied_at: string}> */
    private function appliedVersions(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT version, checksum, applied_at FROM schema_migrations')->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['version']] = ['checksum' => (string) $row['checksum'], 'applied_at' => (string) $row['applied_at']];
        }

        return $result;
    }

    private function tableExists(PDO $pdo): bool
    {
        $stmt = $pdo->query(
            "SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'schema_migrations' LIMIT 1",
        );

        return $stmt !== false && $stmt->fetchColumn() !== false;
    }

    private function ensureTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version      VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                checksum     CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                execution_ms INT UNSIGNED NOT NULL DEFAULT 0,
                applied_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    private function lock(PDO $pdo): void
    {
        $acquired = (int) $pdo->query("SELECT GET_LOCK('" . self::LOCK_NAME . "', 30)")->fetchColumn();
        if ($acquired !== 1) {
            throw new MigrationException('Another migration run holds the lock. Try again when it finishes.');
        }
    }
}
