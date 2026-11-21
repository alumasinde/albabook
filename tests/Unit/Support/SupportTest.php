<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Core\Http\RequestContext;
use App\Shared\Enums\AppointmentStatus;
use App\Shared\Enums\TenantStatus;
use App\Shared\I18n\Translator;
use App\Shared\Support\TokenGenerator;
use App\Shared\Support\TrustedProxies;
use App\Shared\Support\UuidFactory;
use PHPUnit\Framework\TestCase;

final class SupportTest extends TestCase
{
    public function testRandomTokensAreUrlSafeAndUnique(): void
    {
        $tokens = new TokenGenerator();
        $a = $tokens->randomToken();
        $b = $tokens->randomToken();

        self::assertNotSame($a, $b);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $a);
        self::assertSame(64, strlen($tokens->hashToken($a)));
    }

    public function testTooLittleEntropyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new TokenGenerator())->randomToken(8);
    }

    public function testBookingReferencesUseCrockfordAlphabet(): void
    {
        $tokens = new TokenGenerator();
        for ($i = 0; $i < 200; $i++) {
            self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{8}$/', $tokens->bookingReference());
        }
    }

    public function testTypedReferencesAreNormalised(): void
    {
        self::assertSame('7K3Q9XPM', TokenGenerator::normaliseReference('ab-7k3q-9xpm'));
        self::assertSame('10000011', TokenGenerator::normaliseReference('IO00 00L1'));
    }

    public function testNumericCodeIsZeroPadded(): void
    {
        self::assertMatchesRegularExpression('/^\d{6}$/', (new TokenGenerator())->numericCode());
    }

    public function testUuidsAreVersion7(): void
    {
        $uuid = (new UuidFactory())->generate();

        self::assertTrue(UuidFactory::isValid($uuid));
        self::assertSame('7', $uuid[14]);
    }

    public function testTrustedProxyCidrMatching(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8', '192.168.1.10', '2001:db8::/32']);

        self::assertTrue($proxies->isTrusted('10.20.30.40'));
        self::assertTrue($proxies->isTrusted('192.168.1.10'));
        self::assertTrue($proxies->isTrusted('2001:db8::1'));
        self::assertFalse($proxies->isTrusted('11.0.0.1'));
        self::assertFalse($proxies->isTrusted('192.168.1.11'));
        self::assertFalse($proxies->isTrusted('not-an-ip'));
        self::assertTrue(TrustedProxies::isValidEntry('172.16.0.0/12'));
        self::assertFalse(TrustedProxies::isValidEntry('10.0.0.0/33'));
    }

    public function testTranslatorFallsBackToEnglishThenKey(): void
    {
        $context = new RequestContext();
        $context->locale = 'sw';
        $translator = new Translator(dirname(__DIR__, 3) . '/lang', 'en', $context);

        self::assertSame('Funga', $translator->trans('common.close'));
        self::assertSame('Show password', $translator->trans('forms.show_password'), 'missing in sw → en');
        self::assertSame('missing.key', $translator->trans('missing.key'));
        self::assertSame('Page 2 of 5', $translator->trans('common.page_of', ['page' => 2, 'pages' => 5], 'en'));
        self::assertSame('Imethibitishwa', $translator->trans('lookup.appointment_status.confirmed'));
    }

    public function testTranslatorRejectsPathTraversal(): void
    {
        $translator = new Translator(dirname(__DIR__, 3) . '/lang', 'en', new RequestContext());

        self::assertSame('../../config/app.key', $translator->trans('../../config/app.key'));
    }

    public function testTenantLifecycleTransitions(): void
    {
        self::assertTrue(TenantStatus::Trial->canTransitionTo(TenantStatus::Active));
        self::assertFalse(TenantStatus::Archived->canTransitionTo(TenantStatus::Active));
        self::assertFalse(TenantStatus::Pending->canTransitionTo(TenantStatus::Suspended));
    }

    public function testOnlyPendingConfirmedAndCheckedInBlockTime(): void
    {
        self::assertSame(['pending', 'confirmed', 'checked_in'], AppointmentStatus::timeBlockingValues());
        self::assertFalse(AppointmentStatus::Cancelled->blocksTime());
    }
}
