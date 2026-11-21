<?php

declare(strict_types=1);

namespace App\Shared\Security;

/**
 * Opaque bearer values for sessions, remember tokens, verification/reset links
 * and invitations. Only the SHA-256 hash is stored; the plain value exists in
 * the cookie, URL or email exactly once.
 */
final class SecureToken
{
    public static function generate(int $bytes = 32): string
    {
        return bin2hex(random_bytes(max(1, $bytes)));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** A human-transferable recovery code; stored hashed after normalisation. */
    public static function recoveryCode(): string
    {
        $hex = bin2hex(random_bytes(6));

        return implode('-', str_split($hex, 4));
    }

    public static function normaliseRecoveryCode(string $code): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    public static function hashRecoveryCode(string $code): string
    {
        return hash('sha256', 'recovery|' . self::normaliseRecoveryCode($code));
    }
}
