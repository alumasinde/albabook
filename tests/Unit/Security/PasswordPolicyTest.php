<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Core\Config\Config;
use App\Shared\Security\PasswordPolicy;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    private const BASE_PATH = __DIR__ . '/../../..';

    public function testItAcceptsALongUnpredictablePassword(): void
    {
        self::assertSame([], self::policy()->violations('correct-horse-battery'));
    }

    public function testTheMinimumLengthIsTwelveCharacters(): void
    {
        $violations = self::policy()->violations(str_repeat('x', 11));

        self::assertCount(1, $violations);
        self::assertStringContainsString('at least 12', $violations[0]);
    }

    public function testTheMaximumLengthIs128Characters(): void
    {
        $violations = self::policy()->violations(str_repeat('x', 129));

        self::assertCount(1, $violations);
        self::assertStringContainsString('at most 128', $violations[0]);
    }

    public function testTheDenylistIsCaseInsensitive(): void
    {
        foreach (['unbelievable', 'Unbelievable', 'UNBELIEVABLE'] as $password) {
            $violations = self::policy()->violations($password);

            self::assertCount(1, $violations, $password);
            self::assertStringContainsString('too common', $violations[0]);
        }
    }

    public function testLengthIsCheckedBeforeCommonality(): void
    {
        $violations = self::policy()->violations('password');

        self::assertStringContainsString('at least 12', $violations[0]);
        self::assertStringNotContainsString('too common', $violations[0]);
    }

    public function testAMissingDenylistFileDegradesToLengthChecksOnly(): void
    {
        $policy = new PasswordPolicy(
            new Config(['auth' => ['password' => [
                'min_length' => 12,
                'max_length' => 128,
                'common_passwords_path' => 'does/not/exist.txt',
            ]]]),
            self::BASE_PATH,
        );

        self::assertSame([], $policy->violations('unbelievable'));
    }

    private static function policy(): PasswordPolicy
    {
        return new PasswordPolicy(
            new Config(['auth' => ['password' => [
                'min_length' => 12,
                'max_length' => 128,
                'common_passwords_path' => 'resources/security/common-passwords.txt',
            ]]]),
            self::BASE_PATH,
        );
    }
}
