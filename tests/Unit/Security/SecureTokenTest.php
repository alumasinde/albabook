<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Shared\Security\SecureToken;
use PHPUnit\Framework\TestCase;

final class SecureTokenTest extends TestCase
{
    public function testTokensAreRandomHexOfTheRequestedSize(): void
    {
        $first = SecureToken::generate();
        $second = SecureToken::generate();

        self::assertNotSame($first, $second);
        self::assertSame(64, strlen($first));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
        self::assertSame(16, strlen(SecureToken::generate(8)));
        self::assertSame(2, strlen(SecureToken::generate(0)), 'zero bytes still yields one byte');
    }

    public function testHashingIsStableSha256(): void
    {
        self::assertSame(hash('sha256', 'token'), SecureToken::hash('token'));
        self::assertSame(64, strlen(SecureToken::hash('token')));
        self::assertNotSame(SecureToken::hash('token'), SecureToken::hash('Token'));
    }

    public function testRecoveryCodesAreGroupedAndRandom(): void
    {
        $codes = [];

        for ($i = 0; $i < 50; $i++) {
            $code = SecureToken::recoveryCode();
            self::assertMatchesRegularExpression('/^[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}$/', $code);
            $codes[$code] = true;
        }

        self::assertCount(50, $codes);
    }

    public function testRecoveryCodeNormalisationIgnoresFormattingAndCase(): void
    {
        self::assertSame('ab12cd34ef56', SecureToken::normaliseRecoveryCode('AB12-CD34-EF56'));
        self::assertSame('ab12cd34ef56', SecureToken::normaliseRecoveryCode(' ab12 cd34 ef56 '));
        self::assertSame(
            SecureToken::hashRecoveryCode('ab12-cd34-ef56'),
            SecureToken::hashRecoveryCode('AB12CD34EF56'),
        );
        self::assertNotSame(
            SecureToken::hashRecoveryCode('ab12-cd34-ef56'),
            SecureToken::hash('ab12cd34ef56'),
            'recovery hashes are domain-separated from plain token hashes',
        );
    }
}
