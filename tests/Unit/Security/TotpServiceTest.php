<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Core\Config\Config;
use App\Shared\Security\TotpService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TotpServiceTest extends TestCase
{
    /** RFC 6238 seed "12345678901234567890", SHA-1. */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    /** @return iterable<string, array{int, string}> */
    public static function rfc6238Vectors(): iterable
    {
        yield 'T = 59' => [59, '94287082'];
        yield 'T = 1111111109' => [1111111109, '07081804'];
        yield 'T = 1111111111' => [1111111111, '14050471'];
        yield 'T = 1234567890' => [1234567890, '89005924'];
        yield 'T = 2000000000' => [2000000000, '69279037'];
        yield 'T = 20000000000' => [20000000000, '65353130'];
    }

    #[DataProvider('rfc6238Vectors')]
    public function testItMatchesTheRfc6238Vectors(int $timestamp, string $code): void
    {
        $service = self::service(digits: 8);

        self::assertSame(intdiv($timestamp, 30), $service->verify(self::RFC_SECRET, $code, $timestamp));
    }

    public function testItAcceptsOneStepEitherSideOfNow(): void
    {
        $service = self::service();
        $now = 1_700_000_000;
        $step = intdiv($now, 30);

        self::assertSame($step - 1, $service->verify(self::SECRET, self::codeAt($now - 30), $now));
        self::assertSame($step, $service->verify(self::SECRET, self::codeAt($now), $now));
        self::assertSame($step + 1, $service->verify(self::SECRET, self::codeAt($now + 30), $now));
        self::assertNull($service->verify(self::SECRET, self::codeAt($now - 60), $now));
        self::assertNull($service->verify(self::SECRET, self::codeAt($now + 60), $now));
    }

    public function testTheLeewayWindowIsConfigurable(): void
    {
        $service = self::service(leeway: 0);
        $now = 1_700_000_000;

        self::assertNotNull($service->verify(self::SECRET, self::codeAt($now), $now));
        self::assertNull($service->verify(self::SECRET, self::codeAt($now + 30), $now));
    }

    public function testACodeCannotBeReplayedWithinItsStep(): void
    {
        $service = self::service();
        $now = 1_700_000_000;
        $code = self::codeAt($now);

        $step = $service->verify(self::SECRET, $code, $now);
        self::assertNotNull($step);
        self::assertNull($service->verify(self::SECRET, $code, $now, $step));
        self::assertNull($service->verify(self::SECRET, $code, $now + 1, $step));
        self::assertNotNull($service->verify(self::SECRET, self::codeAt($now + 30), $now + 30, $step));
    }

    public function testSpacesAreIgnoredButMalformedCodesAreNot(): void
    {
        $service = self::service();
        $now = 1_700_000_000;
        $code = self::codeAt($now);
        $spaced = substr($code, 0, 3) . ' ' . substr($code, 3);

        self::assertNotNull($service->verify(self::SECRET, $spaced, $now));
        self::assertNull($service->verify(self::SECRET, substr($code, 0, 5), $now));
        self::assertNull($service->verify(self::SECRET, $code . '0', $now));
        self::assertNull($service->verify(self::SECRET, 'abcdef', $now));
        self::assertNull($service->verify(self::SECRET, '', $now));
    }

    public function testAnEmptySecretIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::service()->verify('', '123456', 1_700_000_000);
    }

    public function testTheProvisioningUriNamesTheAccountAndIssuer(): void
    {
        $uri = self::service()->provisioningUri(self::SECRET, 'jane@example.com');

        self::assertStringStartsWith('otpauth://totp/', $uri);
        self::assertStringContainsString('jane%40example.com', $uri);
        self::assertStringContainsString('issuer=AlbaBook', $uri);
        self::assertStringContainsString('secret=' . self::SECRET, $uri);
    }

    public function testGeneratedSecretsAreBase32(): void
    {
        $first = self::service()->generateSecret();
        $second = self::service()->generateSecret();

        self::assertNotSame($first, $second);
        self::assertMatchesRegularExpression('/^[A-Z2-7]+$/', $first);
    }

    private static function service(int $digits = 6, int $leeway = 1): TotpService
    {
        return new TotpService(new Config([
            'auth' => ['mfa' => [
                'issuer' => 'AlbaBook',
                'digits' => $digits,
                'period' => 30,
                'leeway_steps' => $leeway,
            ]],
        ]));
    }

    private static function codeAt(int $timestamp): string
    {
        return \OTPHP\TOTP::createFromSecret(self::SECRET)
            ->withPeriod(30)
            ->withDigits(6)
            ->at(max(0, $timestamp));
    }
}
