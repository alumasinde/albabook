<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use App\Modules\Platform\Support\SupportEntryToken;
use App\Shared\Time\FrozenClock;
use PHPUnit\Framework\TestCase;

final class SupportEntryTokenTest extends TestCase
{
    private const KEY = '0123456789abcdef0123456789abcdef';
    private const UUID = '0192f4c6-7f7b-7c3e-9f8a-2b6d9d0f1a2b';

    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FrozenClock('2026-01-01 09:00:00');
    }

    public function testItRoundTripsTheSupportSession(): void
    {
        $expiresAt = $this->clock->now()->getTimestamp() + 3600;
        $token = self::tokens($this->clock)->issue(self::UUID, $expiresAt, true);

        self::assertStringContainsString('.', $token);
        self::assertSame(
            ['uuid' => self::UUID, 'expiresAt' => $expiresAt, 'write' => true],
            self::tokens($this->clock)->parse($token),
        );
    }

    public function testReadOnlyScopeSurvivesTheRoundTrip(): void
    {
        $expiresAt = $this->clock->now()->getTimestamp() + 60;
        $token = self::tokens($this->clock)->issue(self::UUID, $expiresAt, false);

        $claims = self::tokens($this->clock)->parse($token);

        self::assertNotNull($claims);
        self::assertFalse($claims['write']);
    }

    public function testAnExpiredTokenIsRejected(): void
    {
        $token = self::tokens($this->clock)->issue(self::UUID, $this->clock->now()->getTimestamp(), true);

        self::assertNull(self::tokens($this->clock)->parse($token));
    }

    public function testATamperedTokenIsRejected(): void
    {
        $token = self::tokens($this->clock)->issue(self::UUID, $this->clock->now()->getTimestamp() + 3600, true);
        $last = substr($token, -1);

        self::assertNull(self::tokens($this->clock)->parse(substr($token, 0, -1) . ($last === 'A' ? 'B' : 'A')));
        self::assertNull(self::tokens($this->clock)->parse(explode('.', $token)[0] . '.'));
        self::assertNull(self::tokens($this->clock)->parse(''));
        self::assertNull(self::tokens($this->clock)->parse('no-dot'));
    }

    public function testAnotherApplicationKeyCannotParseTheToken(): void
    {
        $token = self::tokens($this->clock)->issue(self::UUID, $this->clock->now()->getTimestamp() + 3600, true);

        self::assertNull(self::tokens($this->clock, str_repeat('f', 32))->parse($token));
    }

    public function testTheApplicationKeyMustBeExactly32Bytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SupportEntryToken('short', $this->clock);
    }

    private static function tokens(FrozenClock $clock, string $key = self::KEY): SupportEntryToken
    {
        return new SupportEntryToken($key, $clock);
    }
}
