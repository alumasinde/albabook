<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database\Database;
use App\Core\Http\Request;
use Tests\Support\DatabaseTestCase;

/** /health against the real test database. */
final class HealthEndpointTest extends DatabaseTestCase
{
    protected bool $wrapInTransaction = false;

    public function testHealthyWhenDatabaseReachableAndMigrated(): void
    {
        $app = (require dirname(__DIR__, 2) . '/bootstrap/app.php')([Database::class => $this->db]);

        foreach (['app', 'book', 'api', 'admin'] as $prefix) {
            $response = $app->handle(Request::create('GET', "http://{$prefix}.albabook.test/health"));
            $body = json_decode($response->body(), true);

            self::assertSame(200, $response->status(), $prefix);
            self::assertSame(['database' => 'ok', 'migrations' => 'ok', 'storage' => 'ok'], $body['checks']);
            self::assertSame('no-store', $response->header('Cache-Control'));
        }
    }

    public function testDegradedWhenAMigrationIsPending(): void
    {
        $this->pdo->exec("DELETE FROM schema_migrations WHERE version = '010_audit'");
        try {
            $app = (require dirname(__DIR__, 2) . '/bootstrap/app.php')([Database::class => $this->db]);
            $response = $app->handle(Request::create('GET', 'http://app.albabook.test/health'));

            self::assertSame(503, $response->status());
            self::assertSame('fail', json_decode($response->body(), true)['checks']['migrations']);
        } finally {
            self::markSchemaDirty();
        }
    }
}
