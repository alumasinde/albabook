<?php

declare(strict_types=1);

namespace App\Shared\Support;

/**
 * Cryptographically secure tokens and their storage hashes.
 *
 * Rule used across AlbaBook: hand the plaintext token to the user once, store
 * only hashToken() of it (reset links, invitations, hold tokens, booking
 * management links — booking-engine.md §15).
 */
final class TokenGenerator
{
    /** Crockford Base32 without I, L, O, U (booking-engine.md §15). */
    public const REFERENCE_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** URL-safe random token with $bytes of entropy (default 32 bytes = 256 bits). */
    public function randomToken(int $bytes = 32): string
    {
        if ($bytes < 16) {
            throw new \InvalidArgumentException('Tokens need at least 16 bytes of entropy.');
        }

        return self::base64Url(random_bytes($bytes));
    }

    /** SHA-256 hex digest for storage; compare with hash_equals(). */
    public function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** 8-character booking reference, e.g. "7K3Q9XPM" (display as AB-7K3Q-9XPM). */
    public function bookingReference(int $length = 8): string
    {
        $alphabet = self::REFERENCE_ALPHABET;
        $max = strlen($alphabet) - 1;
        $reference = '';
        for ($i = 0; $i < $length; $i++) {
            $reference .= $alphabet[random_int(0, $max)];
        }

        return $reference;
    }

    /** Normalise customer-typed references: case-insensitive, I/L→1, O→0, separators dropped. */
    public static function normaliseReference(string $input): string
    {
        $value = strtoupper(preg_replace('/[\s-]+/', '', $input) ?? '');
        if (str_starts_with($value, 'AB') && strlen($value) === 10) {
            $value = substr($value, 2);
        }

        return strtr($value, ['I' => '1', 'L' => '1', 'O' => '0']);
    }

    /** Six-digit numeric OTP (auth.md §11). */
    public function numericCode(int $digits = 6): string
    {
        return str_pad((string) random_int(0, 10 ** $digits - 1), $digits, '0', STR_PAD_LEFT);
    }

    public static function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    /** "base64:..." application key for .env. */
    public static function generateAppKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }
}
