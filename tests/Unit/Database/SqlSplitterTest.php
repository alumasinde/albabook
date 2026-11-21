<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Core\Database\Database;
use App\Core\Database\Migrations\MigrationException;
use App\Core\Database\Migrations\SqlSplitter;
use PHPUnit\Framework\TestCase;

final class SqlSplitterTest extends TestCase
{
    public function testSplitsOnTopLevelSemicolons(): void
    {
        self::assertSame(['SELECT 1', 'SELECT 2'], SqlSplitter::split("SELECT 1;\nSELECT 2;\n"));
    }

    public function testIgnoresSemicolonsInsideStringsAndIdentifiers(): void
    {
        $sql = "INSERT INTO t (a, `b;c`) VALUES ('x;y', \"p;q\", 'it''s; fine', 'back\\'slash;');";

        self::assertCount(1, SqlSplitter::split($sql));
    }

    public function testStripsCommentsButKeepsHashInsideStrings(): void
    {
        $sql = "-- comment; with semicolon\nCREATE TABLE t (c CHAR(7) DEFAULT '#6D5CE8'); # trailing; comment\n/* block; */ SELECT 1;";
        $statements = SqlSplitter::split($sql);

        self::assertCount(2, $statements);
        self::assertStringContainsString("'#6D5CE8'", $statements[0]);
        self::assertSame('SELECT 1', $statements[1]);
    }

    public function testDoubleDashWithoutSpaceIsNotAComment(): void
    {
        self::assertSame(['SELECT 5--1'], SqlSplitter::split('SELECT 5--1;'));
    }

    public function testSingleStatementTriggerSplitsCorrectly(): void
    {
        $sql = "CREATE TRIGGER t BEFORE UPDATE ON a FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'no; updates';\nSELECT 1;";

        self::assertCount(2, SqlSplitter::split($sql));
    }

    public function testDelimiterIsRefused(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('DELIMITER');
        SqlSplitter::split("DELIMITER $$\nCREATE TRIGGER x BEGIN END$$\nDELIMITER ;");
    }

    public function testUnterminatedStringIsAnError(): void
    {
        $this->expectException(MigrationException::class);
        SqlSplitter::split("SELECT 'oops;");
    }

    public function testEveryRealMigrationSplitsWithoutError(): void
    {
        $files = glob(dirname(__DIR__, 3) . '/database/migrations/*.sql') ?: [];
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            self::assertNotEmpty(SqlSplitter::split((string) file_get_contents($file)), basename($file));
        }
    }

    public function testDeadlockAndLockTimeoutAreRetryable(): void
    {
        foreach ([1213, 1205] as $code) {
            $e = new \PDOException('lock');
            $e->errorInfo = ['40001', $code, 'lock'];
            self::assertTrue(Database::isRetryable($e), (string) $code);
        }

        $duplicate = new \PDOException('dup');
        $duplicate->errorInfo = ['23000', 1062, 'dup'];
        self::assertFalse(Database::isRetryable($duplicate));
        self::assertFalse(Database::isRetryable(new \RuntimeException('x')));
    }
}
