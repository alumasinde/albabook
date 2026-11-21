<?php

declare(strict_types=1);

namespace Tests\Integration\Database;

use App\Core\Database\Migrations\MigrationException;
use App\Core\Database\Migrations\MigrationStatus;
use Tests\Support\DatabaseTestCase;

/**
 * Exercises the runner against real MySQL. These tests commit DDL, so they do
 * not use the per-test transaction and mark the schema for a rebuild.
 */
final class MigrationRunnerTest extends DatabaseTestCase
{
    protected bool $wrapInTransaction = false;

    private string $scratch = '';

    protected function tearDown(): void
    {
        if ($this->scratch !== '' && is_dir($this->scratch)) {
            array_map('unlink', glob($this->scratch . '/*') ?: []);
            rmdir($this->scratch);
        }
        self::markSchemaDirty();
        parent::tearDown();
    }

    public function testAllMigrationsApplyFromScratchAndReportClean(): void
    {
        $runner = self::runner($this->db);
        $runner->dropAll();

        $applied = $runner->migrate();

        self::assertSame(array_keys($runner->files()), $applied);
        self::assertSame(0, $runner->pendingCount());
        foreach ($runner->status() as $status) {
            self::assertSame(MigrationStatus::APPLIED, $status->state, $status->version);
        }
        self::assertSame([], $runner->migrate(), 'second run is a no-op');
    }

    public function testRefusesWhenAnAppliedFileChanged(): void
    {
        $this->scratch = $this->scratchDir([
            '001_a.sql' => 'CREATE TABLE scratch_a (id INT PRIMARY KEY);',
        ]);
        $runner = self::runner($this->db, $this->scratch);
        $runner->dropAll();
        $runner->migrate();

        file_put_contents($this->scratch . '/001_a.sql', 'CREATE TABLE scratch_a (id INT PRIMARY KEY, x INT);');

        self::assertSame(MigrationStatus::MODIFIED, $runner->status()[0]->state);
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('changed after it was applied');
        $runner->migrate();
    }

    public function testLineEndingChangesAreNotDrift(): void
    {
        $this->scratch = $this->scratchDir(['001_a.sql' => "CREATE TABLE scratch_a (\n id INT PRIMARY KEY\n);\n"]);
        $runner = self::runner($this->db, $this->scratch);
        $runner->dropAll();
        $runner->migrate();

        file_put_contents($this->scratch . '/001_a.sql', "CREATE TABLE scratch_a (\r\n id INT PRIMARY KEY\r\n);\r\n");

        self::assertSame(MigrationStatus::APPLIED, $runner->status()[0]->state);
    }

    public function testFailureReportsFileAndStatementAndIsNotRecorded(): void
    {
        $this->scratch = $this->scratchDir([
            '001_ok.sql' => 'CREATE TABLE scratch_ok (id INT PRIMARY KEY);',
            '002_bad.sql' => "CREATE TABLE scratch_b (id INT PRIMARY KEY);\nCREATE TABLE scratch_b (id INT);",
        ]);
        $runner = self::runner($this->db, $this->scratch);
        $runner->dropAll();

        try {
            $runner->migrate();
            self::fail('Expected failure');
        } catch (MigrationException $e) {
            self::assertStringContainsString('002_bad failed at statement 2 of 2', $e->getMessage());
            self::assertStringContainsString('commits DDL implicitly', $e->getMessage());
        }

        $states = array_map(static fn(MigrationStatus $s): string => $s->version . ':' . $s->state, $runner->status());
        self::assertSame(['001_ok:applied', '002_bad:pending'], $states);
    }

    public function testBadFileNamesAndDuplicateNumbersAreRejected(): void
    {
        $this->scratch = $this->scratchDir(['001_a.sql' => 'SELECT 1;', '001_b.sql' => 'SELECT 1;']);

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('share the number 001');
        self::runner($this->db, $this->scratch)->files();
    }

    /** @param array<string, string> $files */
    private function scratchDir(array $files): string
    {
        $dir = sys_get_temp_dir() . '/albabook-migrations-' . bin2hex(random_bytes(4));
        mkdir($dir);
        foreach ($files as $name => $sql) {
            file_put_contents($dir . '/' . $name, $sql);
        }

        return $dir;
    }
}
