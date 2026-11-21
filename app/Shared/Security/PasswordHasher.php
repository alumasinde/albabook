<?php

declare(strict_types=1);

namespace App\Shared\Security;

/**
 * One place that chooses the password algorithm so hashes stay consistent
 * across registration, login rehash checks and resets (auth.md §8b).
 */
final class PasswordHasher
{
    public function hash(string $password): string
    {
        return password_hash($password, $this->algorithm());
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm());
    }

    /**
     * A real hash used to keep unknown-email logins as slow as wrong-password
     * logins. Not a secret.
     */
    public function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= $this->hash(bin2hex(random_bytes(16)));
    }

    private function algorithm(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }
}
