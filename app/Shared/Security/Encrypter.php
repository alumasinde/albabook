<?php

declare(strict_types=1);

namespace App\Shared\Security;

/**
 * AES-256-GCM for secrets that must be read back (TOTP seeds, API secrets).
 * The key is derived from APP_KEY, so rotating APP_KEY invalidates them —
 * intentional, because APP_KEY must never change silently.
 */
final class Encrypter
{
    private const CIPHER = 'aes-256-gcm';
    private const NONCE_BYTES = 12;
    private const TAG_BYTES = 16;

    private readonly string $key;

    public function __construct(string $appKey)
    {
        if (strlen($appKey) !== 32) {
            throw new \InvalidArgumentException('Encrypter needs the 32-byte application key.');
        }
        $this->key = hash('sha256', 'albabook-encryption|' . $appKey, true);
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $nonce, $tag, '', self::TAG_BYTES);
        if ($ciphertext === false || strlen($tag) !== self::TAG_BYTES) {
            throw new \RuntimeException('Encryption failed.');
        }

        return base64_encode($nonce . $tag . $ciphertext);
    }

    public function decrypt(string $payload): string
    {
        $raw = base64_decode($payload, true);
        if (!is_string($raw) || strlen($raw) < self::NONCE_BYTES + self::TAG_BYTES) {
            throw new \RuntimeException('Encrypted payload is malformed.');
        }

        $nonce = substr($raw, 0, self::NONCE_BYTES);
        $tag = substr($raw, self::NONCE_BYTES, self::TAG_BYTES);
        $ciphertext = substr($raw, self::NONCE_BYTES + self::TAG_BYTES);
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($plaintext === false) {
            throw new \RuntimeException('Encrypted payload could not be verified.');
        }

        return $plaintext;
    }
}
