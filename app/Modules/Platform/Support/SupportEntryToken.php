<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use App\Shared\Time\Clock;

/**
 * HMAC-signed token that carries a support session into the tenant host. The
 * same value is the emailed/URL entry token and the tenant-host cookie, so a
 * copied link cannot be reused after expiry or after the admin ends the
 * session (both are re-checked against support_access_sessions).
 */
final class SupportEntryToken
{
    private readonly string $key;

    public function __construct(string $appKey, private readonly Clock $clock)
    {
        if (strlen($appKey) !== 32) {
            throw new \InvalidArgumentException('Support tokens need the 32-byte application key.');
        }
        $this->key = hash('sha256', 'albabook-support|' . $appKey, true);
    }

    public function issue(string $uuid, int $expiresAt, bool $write): string
    {
        $payload = self::encode((string) json_encode([
            'u' => $uuid,
            'e' => $expiresAt,
            'w' => $write,
        ], JSON_THROW_ON_ERROR));

        return $payload . '.' . $this->sign($payload);
    }

    /** @return array{uuid: string, expiresAt: int, write: bool}|null */
    public function parse(string $token): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }
        [$payload, $signature] = $parts;
        if (!hash_equals($this->sign($payload), $signature)) {
            return null;
        }

        $raw = base64_decode(strtr($payload, '-_', '+/'), true);
        if (!is_string($raw)) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !is_string($data['u'] ?? null) || !is_int($data['e'] ?? null) || !is_bool($data['w'] ?? null)) {
            return null;
        }
        if ($data['e'] <= $this->clock->now()->getTimestamp()) {
            return null;
        }

        return ['uuid' => $data['u'], 'expiresAt' => $data['e'], 'write' => $data['w']];
    }

    private function sign(string $payload): string
    {
        return self::encode(hash_hmac('sha256', $payload, $this->key, true));
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
