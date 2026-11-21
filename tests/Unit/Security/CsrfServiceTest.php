<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Shared\Security\CsrfService;
use App\Shared\Support\TokenGenerator;
use PHPUnit\Framework\TestCase;

final class CsrfServiceTest extends TestCase
{
    private CsrfService $csrf;

    protected function setUp(): void
    {
        $this->csrf = new CsrfService(str_repeat('k', 32), new TokenGenerator());
    }

    public function testIssuedTokenVerifiesAgainstItself(): void
    {
        $token = $this->csrf->issue();

        self::assertTrue($this->csrf->verify($token, $token));
    }

    public function testMismatchedSubmissionFails(): void
    {
        self::assertFalse($this->csrf->verify($this->csrf->issue(), $this->csrf->issue()));
    }

    public function testPlantedUnsignedCookieFails(): void
    {
        // An attacker who can set a cookie but not sign it (no APP_KEY).
        $forged = 'attacker-chosen-random.' . TokenGenerator::base64Url(random_bytes(32));

        self::assertFalse($this->csrf->verify($forged, $forged));
    }

    public function testTokenSignedWithAnotherKeyFails(): void
    {
        $other = new CsrfService(str_repeat('x', 32), new TokenGenerator());
        $token = $other->issue();

        self::assertFalse($this->csrf->verify($token, $token));
    }

    public function testBindingIsPartOfTheSignature(): void
    {
        $token = $this->csrf->issue('session-a');

        self::assertTrue($this->csrf->verify($token, $token, 'session-a'));
        self::assertFalse($this->csrf->verify($token, $token, 'session-b'));
    }

    public function testMissingValuesFail(): void
    {
        self::assertFalse($this->csrf->verify(null, 'x'));
        self::assertFalse($this->csrf->verify('x', null));
        self::assertFalse($this->csrf->verify('', ''));
    }
}
