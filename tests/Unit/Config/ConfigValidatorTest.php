<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Core\Config\Config;
use App\Core\Config\ConfigurationException;
use App\Core\Config\ConfigValidator;
use PHPUnit\Framework\TestCase;

final class ConfigValidatorTest extends TestCase
{
    private const KEY = 'base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';

    public function testValidLocalConfigurationPasses(): void
    {
        self::assertSame([], (new ConfigValidator())->validate(self::config()));
    }

    public function testMissingOrShortAppKeyIsRejected(): void
    {
        foreach (['', 'not-base64', 'base64:' . base64_encode('too short')] as $key) {
            $errors = (new ConfigValidator())->validate(self::config(['app' => ['key' => $key]]));
            self::assertNotEmpty(array_filter($errors, static fn(string $e): bool => str_contains($e, 'APP_KEY')), "key: {$key}");
        }
    }

    public function testProductionRequiresDebugOffSecureCookiesAndDbPassword(): void
    {
        $errors = (new ConfigValidator())->validate(self::config([
            'app' => ['env' => 'production', 'debug' => true],
            'security' => ['secure_cookies' => false],
            'database' => ['password' => ''],
        ]));

        $joined = implode("\n", $errors);
        self::assertStringContainsString('APP_DEBUG', $joined);
        self::assertStringContainsString('SESSION_SECURE_COOKIE', $joined);
        self::assertStringContainsString('DB_PASSWORD', $joined);
    }

    public function testHostsMustBeValidAndDistinct(): void
    {
        $errors = (new ConfigValidator())->validate(self::config(['app' => ['hosts' => [
            'app' => 'https://app.example.com',
            'book' => 'same.example.com',
            'api' => 'same.example.com',
            'admin' => 'Admin.Example.com',
        ]]]));

        $joined = implode("\n", $errors);
        self::assertStringContainsString('APP_HOST_TENANT', $joined);
        self::assertStringContainsString('APP_HOST_ADMIN', $joined);
        self::assertStringContainsString('must all differ', $joined);
    }

    public function testInvalidTimezoneCurrencyAndProxyAreRejected(): void
    {
        $errors = (new ConfigValidator())->validate(self::config([
            'app' => ['default_tenant_timezone' => 'Nairobi', 'default_tenant_currency' => 'kes'],
            'security' => ['trusted_proxies' => ['10.0.0.0/99']],
        ]));

        $joined = implode("\n", $errors);
        self::assertStringContainsString('DEFAULT_TENANT_TIMEZONE', $joined);
        self::assertStringContainsString('DEFAULT_TENANT_CURRENCY', $joined);
        self::assertStringContainsString('TRUSTED_PROXIES', $joined);
    }

    public function testAssertValidListsEveryProblemWithoutValues(): void
    {
        $secret = 'super-secret-password';
        try {
            (new ConfigValidator())->assertValid(self::config(['app' => ['key' => ''], 'database' => ['host' => '', 'password' => $secret]]));
            self::fail('Expected ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertGreaterThanOrEqual(2, count($e->errors));
            self::assertStringNotContainsString($secret, $e->getMessage());
        }
    }

    /** @param array<string, array<string, mixed>> $overrides */
    public static function config(array $overrides = []): Config
    {
        $base = [
            'app' => [
                'env' => 'local',
                'debug' => true,
                'key' => self::KEY,
                'hosts' => ['app' => 'app.albabook.test', 'book' => 'book.albabook.test', 'api' => 'api.albabook.test', 'admin' => 'admin.albabook.test'],
                'default_tenant_timezone' => 'Africa/Nairobi',
                'default_tenant_currency' => 'KES',
            ],
            'database' => ['host' => 'db', 'port' => 3306, 'database' => 'albabook', 'username' => 'albabook', 'password' => ''],
            'security' => [
                'secure_cookies' => false,
                'trusted_proxies' => [],
                'session' => ['idle_timeout_minutes' => 120, 'absolute_timeout_minutes' => 720, 'cookie_secure' => '__Host-albabook_app'],
                'remember' => ['cookie_secure' => '__Host-albabook_remember'],
                'support' => ['cookie_secure' => '__Host-albabook_support'],
                'platform' => ['cookie_secure' => '__Host-albabook_admin', 'ip_allowlist' => []],
            ],
            'auth' => [
                'password' => ['min_length' => 12, 'max_length' => 128, 'common_passwords_path' => 'resources/security/common-passwords.txt'],
                'mfa' => ['leeway_steps' => 1],
            ],
            'tenancy' => [
                'slug' => ['pattern' => '/^[a-z0-9]+(-[a-z0-9]+)*$/'],
                'support' => ['default_minutes' => 60, 'max_minutes' => 240],
            ],
            'mail' => ['transport' => 'log', 'from_address' => 'no-reply@albabook.test'],
            'logging' => ['level' => 'debug', 'channel' => 'file', 'format' => 'line'],
        ];

        return new Config(array_replace_recursive($base, $overrides));
    }
}
