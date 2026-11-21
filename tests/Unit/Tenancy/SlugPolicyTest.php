<?php

declare(strict_types=1);

namespace Tests\Unit\Tenancy;

use App\Core\Config\Config;
use App\Core\Validation\ValidationException;
use App\Tenancy\SlugPolicy;
use PHPUnit\Framework\TestCase;

final class SlugPolicyTest extends TestCase
{
    public function testItNormalisesCaseAndWhitespace(): void
    {
        $policy = self::policy();

        self::assertSame('my-salon', $policy->normalise('  My-Salon '));
        self::assertTrue($policy->isValid('  My-Salon '));
    }

    public function testItAcceptsLowercaseWordsSeparatedBySingleHyphens(): void
    {
        $policy = self::policy();

        foreach (['abc', 'abc-123', 'a1b2', str_repeat('a', 60)] as $slug) {
            self::assertTrue($policy->isValid($slug), $slug);
        }
    }

    public function testItRejectsMalformedOrOutOfRangeSlugs(): void
    {
        $policy = self::policy();

        foreach (['ab', str_repeat('a', 61), 'a_b', '-abc', 'abc-', 'a--b', 'abc--123', 'café', 'a b'] as $slug) {
            self::assertFalse($policy->isValid($slug), $slug);
        }
    }

    public function testReservedWordsAreRejectedEvenWhenTheFormatIsValid(): void
    {
        $policy = self::policy();

        self::assertTrue($policy->isReserved('admin'));
        self::assertFalse($policy->isValid('admin'));
        self::assertFalse($policy->isValid('Admin '));
        self::assertSame(['admin', 'api', 'book'], $policy->reserved());
    }

    public function testAssertValidReturnsTheNormalisedSlug(): void
    {
        self::assertSame('my-salon', self::policy()->assertValid(' My-Salon '));
    }

    public function testAssertValidExplainsAReservedWord(): void
    {
        try {
            self::policy()->assertValid('api');
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('reserved', $e->errors['slug']);
            self::assertSame('api', $e->old['slug']);
        }
    }

    public function testAssertValidExplainsTheFormat(): void
    {
        try {
            self::policy()->assertValid('-bad-');
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('3–60 lowercase letters', $e->errors['slug']);
            self::assertArrayNotHasKey('slug', array_diff_key($e->errors, ['slug' => true]));
        }
    }

    private static function policy(): SlugPolicy
    {
        return new SlugPolicy(new Config(['tenancy' => [
            'slug' => [
                'min_length' => 3,
                'max_length' => 60,
                'pattern' => '/^[a-z0-9]+(-[a-z0-9]+)*$/',
            ],
            'reserved_slugs' => ['admin', 'api', 'book'],
        ]]));
    }
}
