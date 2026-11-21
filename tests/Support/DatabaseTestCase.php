<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Database\Database;
use App\Core\Database\Migrations\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base for tests that need real MySQL 8 (the test database from phpunit.xml).
 *
 * The schema is rebuilt from the migrations once per test run. Each test then
 * runs inside a transaction that is rolled back, so tests do not see each
 * other's rows. Tests that must commit (migration runner, transaction retry)
 * use their own scratch tables or rebuild.
 *
 * Without a reachable database the tests are skipped locally, and fail in CI
 * (TEST_DB_REQUIRED=1), so a broken CI database can never pass silently.
 */
abstract class DatabaseTestCase extends TestCase
{
    private static bool $migrated = false;
    private static ?Database $database = null;

    protected Database $db;
    protected PDO $pdo;
    protected bool $wrapInTransaction = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = self::database();
        $this->pdo = $this->db->pdo();

        if (!self::$migrated) {
            $runner = self::runner($this->db);
            $runner->dropAll();
            $runner->migrate();
            self::$migrated = true;
        }

        if ($this->wrapInTransaction) {
            $this->pdo->beginTransaction();
        }
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        parent::tearDown();
    }

    /** Force the next test to rebuild the schema (after a test that altered it). */
    protected static function markSchemaDirty(): void
    {
        self::$migrated = false;
    }

    protected static function runner(Database $db, ?string $directory = null): MigrationRunner
    {
        return new MigrationRunner($db, $directory ?? dirname(__DIR__, 2) . '/database/migrations');
    }

    public static function databaseConfig(): array
    {
        $config = require dirname(__DIR__, 2) . '/config/database.php';
        // .env is not loaded in unit/integration tests; fall back to the docker defaults.
        $config['host'] = (string) (env('DB_HOST') ?? 'db');
        $config['username'] = (string) (env('DB_USERNAME') ?? 'albabook');
        $config['password'] = (string) (env('DB_PASSWORD') ?? 'albabook');
        $config['database'] = (string) (env('DB_DATABASE') ?? 'albabook_test');

        return $config;
    }

    private static function database(): Database
    {
        if (self::$database !== null) {
            return self::$database;
        }

        $db = new Database(self::databaseConfig());
        try {
            $db->pdo();
        } catch (\PDOException $e) {
            if ((string) env('TEST_DB_REQUIRED', '0') === '1' || env('TEST_DB_REQUIRED') === true) {
                throw $e;
            }
            self::markTestSkipped('MySQL test database not reachable: ' . $e->getMessage());
        }

        return self::$database = $db;
    }

    /** @param array<string, mixed> $row */
    protected function insert(string $table, array $row): int
    {
        $columns = array_keys($row);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columns)),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)),
        );
        $this->pdo->prepare($sql)->execute($row);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Assert that $sql fails with a MySQL constraint error. Uses a savepoint so
     * the surrounding test transaction stays usable.
     *
     * @param array<string, mixed> $params
     */
    protected function assertRejected(string $sql, array $params = [], string $expectedFragment = ''): void
    {
        $this->pdo->exec('SAVEPOINT constraint_check');
        try {
            $this->pdo->prepare($sql)->execute($params);
            $this->pdo->exec('RELEASE SAVEPOINT constraint_check');
            self::fail("Expected the database to reject:\n{$sql}");
        } catch (\PDOException $e) {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT constraint_check');
            if ($expectedFragment !== '') {
                self::assertStringContainsString($expectedFragment, $e->getMessage());
            }
            $this->addToAssertionCount(1);
        }
    }
}
