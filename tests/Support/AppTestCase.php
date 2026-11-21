<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Application;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Shared\Money\ArrayCurrencyCatalog;
use App\Shared\Money\Currency;
use App\Shared\Money\CurrencyCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Boots the real application (bootstrap/app.php) and sends requests through
 * the full middleware stack, without a web server.
 *
 * Hosts come from phpunit.xml (*.albabook.test). No database is needed unless
 * a test calls a DB-backed route; the currency catalog is in-memory here.
 */
abstract class AppTestCase extends TestCase
{
    protected const TENANT = 'app.albabook.test';
    protected const BOOKING = 'book.albabook.test';
    protected const API = 'api.albabook.test';
    protected const ADMIN = 'admin.albabook.test';

    protected Application $app;

    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->boot();
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            if ($value === false) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $_SERVER[$key] = $value;
                putenv("{$key}={$value}");
            }
        }
        $this->envBackup = [];
        parent::tearDown();
    }

    /** @param array<string, mixed> $overrides */
    protected function boot(array $overrides = []): Application
    {
        $boot = require dirname(__DIR__, 2) . '/bootstrap/app.php';

        return $boot($overrides + [
            CurrencyCatalog::class => new ArrayCurrencyCatalog([new Currency('KES', 'KSh', 2), new Currency('UGX', 'USh', 0)]),
        ]);
    }

    /** Temporarily set environment variables for the next boot(); restored in tearDown. */
    protected function withEnv(array $values): void
    {
        foreach ($values as $key => $value) {
            if (!array_key_exists($key, $this->envBackup)) {
                $this->envBackup[$key] = $_ENV[$key] ?? getenv($key);
            }
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     * @param array<string, mixed>  $server
     */
    protected function request(string $method, string $host, string $path, array $body = [], array $headers = [], array $cookies = [], array $server = []): Response
    {
        return $this->app->handle(Request::create($method, "http://{$host}{$path}", $body, $headers, $cookies, $server));
    }

    protected function get(string $host, string $path, array $headers = [], array $cookies = []): Response
    {
        return $this->request('GET', $host, $path, [], $headers, $cookies);
    }

    /** Value of a Set-Cookie on the response, or null. */
    protected static function cookieValue(Response $response, string $name): ?string
    {
        foreach ($response->cookies() as $cookie) {
            if ($cookie->name === $name) {
                return $cookie->value;
            }
        }

        return null;
    }
}
