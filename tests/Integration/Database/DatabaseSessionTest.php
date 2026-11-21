<?php

declare(strict_types=1);

namespace Tests\Integration\Database;

use App\Core\Database\Database;
use App\Core\Database\IsolationLevel;
use Tests\Support\DatabaseTestCase;

final class DatabaseSessionTest extends DatabaseTestCase
{
    protected bool $wrapInTransaction = false;

    public function testServerIsMySql8NotMariaDb(): void
    {
        $version = (string) $this->pdo->query('SELECT VERSION()')->fetchColumn();

        if (str_contains(strtolower($version), 'mariadb') && (string) env('ALLOW_MARIADB', '0') === '1') {
            self::markTestSkipped('MariaDB is allowed for Phase 1 local development (ALLOW_MARIADB=1); Docker and CI must run MySQL 8 (D-009).');
        }

        self::assertStringNotContainsStringIgnoringCase('mariadb', $version, 'The booking engine relies on MySQL 8 behaviour (D-009).');
        self::assertMatchesRegularExpression('/^(8|9)\./', $version);
    }

    public function testSessionIsUtcStrictAndUtf8mb4(): void
    {
        $row = $this->pdo->query('SELECT @@session.time_zone AS tz, @@session.sql_mode AS mode, @@character_set_connection AS cs, @@collation_connection AS coll')->fetch();

        self::assertSame('+00:00', $row['tz']);
        self::assertStringContainsString('STRICT_ALL_TABLES', (string) $row['mode']);
        self::assertStringContainsString('ONLY_FULL_GROUP_BY', (string) $row['mode']);
        self::assertSame('utf8mb4', $row['cs']);
        self::assertSame('utf8mb4_unicode_ci', $row['coll']);
    }

    public function testCurrentTimestampDefaultsAreUtc(): void
    {
        $dbNow = new \DateTimeImmutable((string) $this->pdo->query('SELECT NOW()')->fetchColumn(), new \DateTimeZone('UTC'));

        self::assertLessThan(5, abs($dbNow->getTimestamp() - time()));
    }

    public function testStrictModeRejectsTruncation(): void
    {
        $this->expectException(\PDOException::class);
        $this->pdo->exec("INSERT INTO currencies (code, name, symbol, minor_unit_exponent) VALUES ('ABC', '" . str_repeat('x', 200) . "', '\$', 2)");
    }

    public function testTransactionCanRunAtReadCommittedAndRestoresLockTimeout(): void
    {
        $before = (int) $this->pdo->query('SELECT @@SESSION.innodb_lock_wait_timeout')->fetchColumn();

        $level = $this->db->transaction(
            static fn(\PDO $pdo): string => (string) $pdo->query('SELECT @@transaction_isolation')->fetchColumn(),
            IsolationLevel::ReadCommitted,
            lockWaitTimeoutSeconds: 3,
        );

        self::assertSame('READ-COMMITTED', $level);
        self::assertSame($before, (int) $this->pdo->query('SELECT @@SESSION.innodb_lock_wait_timeout')->fetchColumn());
    }

    public function testDeadlockIsRetriedAndTheWorkCommitsOnce(): void
    {
        $this->pdo->exec('CREATE TEMPORARY TABLE retry_probe (id INT PRIMARY KEY)');
        $attempts = 0;

        $this->db->transaction(function (\PDO $pdo) use (&$attempts): void {
            $attempts++;
            $pdo->exec('INSERT INTO retry_probe (id) VALUES (1)');
            if ($attempts === 1) {
                $e = new \PDOException('Deadlock found when trying to get lock');
                $e->errorInfo = ['40001', 1213, 'Deadlock'];
                throw $e;
            }
        }, IsolationLevel::ReadCommitted);

        self::assertSame(2, $attempts);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM retry_probe')->fetchColumn());
    }

    public function testNonRetryableErrorsAreNotRetried(): void
    {
        $attempts = 0;
        try {
            $this->db->transaction(function () use (&$attempts): void {
                $attempts++;
                throw new \RuntimeException('business rule');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame(1, $attempts);
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testNestedTransactionsAreRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->db->transaction(fn() => $this->db->transaction(static fn() => null));
    }

    public function testDatabaseClassIsTheOnlyConnectionPath(): void
    {
        self::assertInstanceOf(Database::class, $this->db);
        self::assertTrue($this->db->isConnected());
    }
}
