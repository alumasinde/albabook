<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Http\Request;
use App\Core\Http\Response;
use Tests\Support\AppTestCase;

/**
 * The full stack, no web server: hosts, shells, headers, errors, CSRF, locale,
 * maintenance (Phase 1 "done when" criteria).
 */
final class HttpKernelTest extends AppTestCase
{
    public function testEachHostServesItsOwnShell(): void
    {
        $tenant = $this->get(self::TENANT, '/');
        self::assertSame(200, $tenant->status());
        self::assertStringContainsString('id="sidebar"', $tenant->body());

        $booking = $this->get(self::BOOKING, '/');
        self::assertSame(200, $booking->status());
        self::assertStringContainsString('booking-page', $booking->body());
        self::assertStringContainsString('/_/assets/css/app.css', $booking->body(), 'booking host serves assets under /_/ (D-005)');

        $admin = $this->get(self::ADMIN, '/');
        self::assertSame(200, $admin->status());
        self::assertStringContainsString('Platform', $admin->body());

        $api = $this->get(self::API, '/');
        self::assertSame(200, $api->status());
        self::assertStringStartsWith('application/json', (string) $api->header('Content-Type'));
    }

    public function testUnknownHostIsRejected(): void
    {
        $response = $this->get('evil.example.com', '/');

        self::assertSame(400, $response->status());
        self::assertStringContainsString('Unknown host', $response->body());
    }

    public function testEveryResponseCarriesSecurityHeadersAndRequestId(): void
    {
        foreach ([$this->get(self::TENANT, '/'), $this->get(self::TENANT, '/does-not-exist'), $this->get('evil.example.com', '/')] as $response) {
            $csp = (string) $response->header('Content-Security-Policy');
            self::assertStringContainsString("script-src 'self'", $csp);
            self::assertStringContainsString("frame-ancestors 'none'", $csp);
            self::assertStringNotContainsString('unsafe-inline', $csp);
            self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
            self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $response->header('X-Request-Id'));
        }
    }

    public function testNotFoundPageIsSafeAndShowsTheRequestId(): void
    {
        $response = $this->get(self::TENANT, '/nope');
        $id = (string) $response->header('X-Request-Id');

        self::assertSame(404, $response->status());
        self::assertStringContainsString('Page not found', $response->body());
        self::assertStringContainsString($id, $response->body());
    }

    public function testApiErrorsUseTheJsonContract(): void
    {
        $response = $this->get(self::API, '/nope');
        $body = json_decode($response->body(), true);

        self::assertSame(404, $response->status());
        self::assertSame('NOT_FOUND', $body['error']['code']);
        self::assertSame($response->header('X-Request-Id'), $body['error']['request_id']);
        self::assertArrayNotHasKey('debug', $body['error']);
    }

    public function testServerErrorHidesInternalsAndLogsMaskedDetailWithSameRequestId(): void
    {
        $this->app->container()->get(\App\Core\Routing\Router::class)->group(
            \App\Core\Http\HostGroup::Tenant,
            static fn ($r) => $r->get('/_test/explode', [ExplodingController::class, 'explode']),
        );

        $response = $this->get(self::TENANT, '/_test/explode');
        $id = (string) $response->header('X-Request-Id');

        self::assertSame(500, $response->status());
        self::assertStringContainsString('Something went wrong', $response->body());
        self::assertStringNotContainsString('ExplodingController', $response->body());
        self::assertStringNotContainsString('wanjiru@example.com', $response->body());
        self::assertStringContainsString($id, $response->body());

        $log = $this->latestLogContaining($id);
        self::assertStringContainsString('Database password leaked for', $log);
        self::assertStringNotContainsString('wanjiru@example.com', $log, 'PII must be masked in logs (D-012)');
    }

    public function testCsrfBlocksFormPostsWithoutAValidToken(): void
    {
        $response = $this->request('POST', self::TENANT, '/_system/components/form', ['full_name' => 'Jane']);

        self::assertSame(419, $response->status());
    }

    public function testCsrfAcceptsTheTokenIssuedOnGet(): void
    {
        $page = $this->get(self::TENANT, '/_system/components');
        $token = self::cookieValue($page, 'albabook_csrf');
        self::assertNotNull($token);
        self::assertStringContainsString('name="_csrf" value="' . $token . '"', $page->body());

        $invalid = $this->request('POST', self::TENANT, '/_system/components/form', ['_csrf' => $token, 'full_name' => '', 'email' => 'bad'], cookies: ['albabook_csrf' => $token]);
        self::assertSame(422, $invalid->status(), 'validation errors render');
        self::assertStringContainsString('Enter your full name.', $invalid->body());
        self::assertStringContainsString('aria-invalid="true"', $invalid->body());

        $valid = $this->request('POST', self::TENANT, '/_system/components/form', ['_csrf' => $token, 'full_name' => 'Jane W', 'email' => 'jane@example.com'], cookies: ['albabook_csrf' => $token]);
        self::assertSame(200, $valid->status());
        self::assertStringContainsString('CSRF and validation passed', $valid->body());
    }

    public function testCsrfRejectsCrossOriginEvenWithAToken(): void
    {
        $token = (string) self::cookieValue($this->get(self::TENANT, '/_system/components'), 'albabook_csrf');

        $response = $this->request('POST', self::TENANT, '/_system/components/form', ['_csrf' => $token, 'full_name' => 'x', 'email' => 'x@y.co'], ['Origin' => 'https://evil.example.com'], ['albabook_csrf' => $token]);

        self::assertSame(419, $response->status());
    }

    public function testApiHostIsCsrfExemptAndSetsNoCsrfCookie(): void
    {
        $response = $this->get(self::API, '/');

        self::assertNull(self::cookieValue($response, 'albabook_csrf'));
    }

    public function testComponentCatalogueRendersOnEveryHtmlHost(): void
    {
        foreach ([self::TENANT, self::BOOKING, self::ADMIN] as $host) {
            $response = $this->get($host, '/_system/components');
            self::assertSame(200, $response->status(), $host);
            self::assertStringContainsString('Component catalogue', $response->body());
            self::assertStringContainsString('KSh 38,500', $response->body(), 'money filter formats integer minor units');
        }
    }

    public function testBookingHostSwitchesToSwahiliAndRemembersIt(): void
    {
        $response = $this->get(self::BOOKING, '/?lang=sw');

        self::assertStringContainsString('Weka miadi yako ijayo', $response->body());
        self::assertStringContainsString('lang="sw"', $response->body());
        self::assertSame('sw', self::cookieValue($response, 'albabook_lang'));

        $again = $this->get(self::BOOKING, '/', cookies: ['albabook_lang' => 'sw']);
        self::assertStringContainsString('Weka miadi yako ijayo', $again->body());
    }

    public function testDashboardStaysEnglishEvenIfSwahiliRequested(): void
    {
        $response = $this->get(self::TENANT, '/?lang=sw', cookies: ['albabook_lang' => 'sw']);

        self::assertStringContainsString('<html lang="en"', $response->body());
    }

    public function testMaintenanceModeReturns503WithRetryAfter(): void
    {
        $maintenance = $this->app->container()->get(\App\Shared\Support\MaintenanceMode::class);
        $maintenance->enable(120);
        try {
            $response = $this->get(self::BOOKING, '/');
            self::assertSame(503, $response->status());
            self::assertSame('120', $response->header('Retry-After'));
            self::assertStringContainsString('Back shortly', $response->body());
        } finally {
            $maintenance->disable();
        }
    }

    public function testHeadRequestsAreAnswered(): void
    {
        $response = $this->app->handle(Request::create('HEAD', 'http://' . self::TENANT . '/'));

        self::assertSame(200, $response->status());
    }

    public function testComponentCatalogueIsHiddenInProduction(): void
    {
        $this->withEnv(['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'SESSION_SECURE_COOKIE' => 'true', 'DB_PASSWORD' => 'a-real-password']);
        $this->app = $this->boot();

        self::assertSame(404, $this->get(self::TENANT, '/_system/components')->status());
    }

    public function testInvalidConfigurationRefusesToBoot(): void
    {
        $this->withEnv(['APP_KEY' => 'base64:short']);

        $this->expectException(\App\Core\Config\ConfigurationException::class);
        $this->expectExceptionMessage('APP_KEY');
        $this->boot();
    }

    private function latestLogContaining(string $needle): string
    {
        $files = glob(dirname(__DIR__, 2) . '/storage/logs/albabook*.log') ?: [];
        rsort($files);
        foreach ($files as $file) {
            foreach (file($file) ?: [] as $line) {
                if (str_contains($line, $needle)) {
                    return $line;
                }
            }
        }
        self::fail("No log line contains {$needle}");
    }
}

final class ExplodingController
{
    /** @param array<string, string> $params */
    public function explode(Request $request, array $params): Response
    {
        throw new \RuntimeException('Database password leaked for wanjiru@example.com');
    }
}
