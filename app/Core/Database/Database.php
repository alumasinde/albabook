<?php

declare(strict_types=1);

namespace App\Core\Database;

use PDO;

/**
 * Lazy PDO connection with AlbaBook's non-negotiable session settings:
 *
 * - time_zone '+00:00'  → CURRENT_TIMESTAMP defaults and NOW() are UTC (plan.md §13).
 * - strict sql_mode     → invalid data is an error, never silently truncated.
 * - utf8mb4             → full Unicode, including emoji in customer names.
 * - real prepared statements (no emulation) and exceptions on every error.
 */
final class Database
{
    private ?PDO $pdo = null;

    /** @param array<string, mixed> $config config/database.php */
    public function __construct(private readonly array $config) {}

    public function pdo(): PDO
    {
        return $this->pdo ??= $this->connect();
    }

    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }

    public function disconnect(): void
    {
        $this->pdo = null;
    }

    /**
     * Run $work inside a transaction and return its result.
     *
     * With an isolation level, the level is set before BEGIN. Booking writes use
     * READ COMMITTED with lock anchors taken first (decisions.md D-009).
     * Deadlocks (1213), lock-wait timeouts (1205) and serialisation failures
     * (40001) roll back and retry the whole closure with jittered back-off, so
     * $work must be safe to repeat and must not call external services.
     *
     * @template T
     * @param callable(PDO): T $work
     * @return T
     */
    public function transaction(callable $work, ?IsolationLevel $isolation = null, ?int $maxAttempts = null, ?int $lockWaitTimeoutSeconds = null): mixed
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Nested transactions are not supported; pass the PDO down instead.');
        }

        $maxAttempts ??= (int) ($this->config['transaction']['max_attempts'] ?? 3);
        $baseDelayMs = (int) ($this->config['transaction']['retry_base_delay_ms'] ?? 25);
        $previousTimeout = null;
        $previousIsolation = null;

        try {
            if ($isolation !== null) {
                // Session scope works identically on MySQL 8 and MariaDB; the
                // next-transaction form (SET TRANSACTION …) is ignored on MariaDB.
                $previousIsolation = (string) $pdo->query('SELECT @@SESSION.transaction_isolation')->fetchColumn();
                $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL ' . $isolation->value);
            }

            if ($lockWaitTimeoutSeconds !== null) {
                $previousTimeout = (int) $pdo->query('SELECT @@SESSION.innodb_lock_wait_timeout')->fetchColumn();
                $pdo->exec('SET SESSION innodb_lock_wait_timeout = ' . max(1, $lockWaitTimeoutSeconds));
            }

            for ($attempt = 1; ; $attempt++) {
                $pdo->beginTransaction();
                try {
                    $result = $work($pdo);
                    $pdo->commit();

                    return $result;
                } catch (\Throwable $e) {
                    // The closure may have committed before throwing.
                    // @phpstan-ignore-next-line
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if ($attempt >= $maxAttempts || !self::isRetryable($e)) {
                        throw $e;
                    }
                    usleep(($baseDelayMs * (2 ** ($attempt - 1)) + random_int(0, $baseDelayMs)) * 1000);
                }
            }
        } finally {
            if ($previousIsolation !== null && preg_match('/^[A-Z-]+$/', $previousIsolation) === 1) {
                $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL ' . str_replace('-', ' ', $previousIsolation));
            }
            if ($previousTimeout !== null) {
                $pdo->exec('SET SESSION innodb_lock_wait_timeout = ' . $previousTimeout);
            }
        }
    }

    public static function isRetryable(\Throwable $e): bool
    {
        if (!$e instanceof \PDOException) {
            return false;
        }
        $driverCode = is_array($e->errorInfo) ? (int) ($e->errorInfo[1] ?? 0) : 0;

        return in_array($driverCode, [1213, 1205], true) || $e->getCode() === '40001';
    }

    private function connect(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string) $this->config['host'],
            (int) $this->config['port'],
            (string) $this->config['database'],
            (string) ($this->config['charset'] ?? 'utf8mb4'),
        );

        $pdo = new PDO($dsn, (string) $this->config['username'], (string) $this->config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::MYSQL_ATTR_FOUND_ROWS => true,
            // Connect timeout: without this a silently-dropping server hangs
            // every request for default_socket_timeout (60s) before erroring.
            PDO::ATTR_TIMEOUT => (int) ($this->config['connect_timeout'] ?? 5),
        ]);

        $collation = (string) ($this->config['collation'] ?? 'utf8mb4_unicode_ci');
        $sqlMode = (string) ($this->config['sql_mode'] ?? 'STRICT_ALL_TABLES');
        if (preg_match('/^[A-Za-z0-9_]+$/', $collation) !== 1 || preg_match('/^[A-Z_,]+$/', $sqlMode) !== 1) {
            throw new \InvalidArgumentException('Invalid collation or sql_mode in config/database.php.');
        }

        $pdo->exec("SET NAMES utf8mb4 COLLATE {$collation}");
        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec("SET SESSION sql_mode = '{$sqlMode}'");

        return $pdo;
    }
}
