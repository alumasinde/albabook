<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Shared\Security\Encrypter;
use PHPUnit\Framework\TestCase;

final class EncrypterTest extends TestCase
{
    private const KEY = '0123456789abcdef0123456789abcdef';

    public function testItRoundTripsArbitrarySecrets(): void
    {
        $encrypter = new Encrypter(self::KEY);

        foreach (['JBSWY3DPEHPK3PXP', 'sk_live_•é中🔐', ''] as $plaintext) {
            self::assertSame($plaintext, $encrypter->decrypt($encrypter->encrypt($plaintext)));
        }
    }

    public function testTheSamePlaintextEncryptsDifferentlyEachTime(): void
    {
        $encrypter = new Encrypter(self::KEY);

        self::assertNotSame($encrypter->encrypt('same'), $encrypter->encrypt('same'));
    }

    public function testATamperedPayloadIsRejected(): void
    {
        $encrypter = new Encrypter(self::KEY);
        $raw = base64_decode($encrypter->encrypt('totp-seed'), true);
        self::assertIsString($raw);
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 0x01);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('could not be verified');

        $encrypter->decrypt(base64_encode($raw));
    }

    public function testAnotherApplicationKeyCannotDecrypt(): void
    {
        $ciphertext = (new Encrypter(self::KEY))->encrypt('totp-seed');

        $this->expectException(\RuntimeException::class);

        (new Encrypter(str_repeat('f', 32)))->decrypt($ciphertext);
    }

    public function testMalformedPayloadsAreRejected(): void
    {
        $encrypter = new Encrypter(self::KEY);

        foreach (['not base64!', base64_encode('too short'), ''] as $payload) {
            try {
                $encrypter->decrypt($payload);
                self::fail("Expected a RuntimeException for \"{$payload}\".");
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('malformed', $e->getMessage());
            }
        }
    }

    public function testTheApplicationKeyMustBeExactly32Bytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Encrypter('short');
    }
}
