<?php

declare(strict_types=1);

namespace App\Modules\Till\Support;

use App\Core\Config\Config;

/**
 * Public receipt verification codes: "{reference}-{signature}", e.g.
 * "2T3FBSBA-K7Q2M9XD".
 *
 * The reference alone is 8 characters and unique per business only, so it
 * can be neither trusted nor guessed safely. The signature is an HMAC over
 * (tenant, appointment, reference) under APP_KEY with a purpose label, so a
 * code is unforgeable, binds to exactly one receipt across all tenants, and
 * cannot be enumerated (40 bits per guess on top of a rate limit).
 */
final class ReceiptVerifier
{
    private const PURPOSE = 'albabook:receipt-verify:v1|';
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'; // Crockford base32 (no I L O U)

    public function __construct(private readonly Config $config) {}

    public function code(int $tenantId, int $appointmentId, string $reference): string
    {
        return $reference . '-' . $this->signature($tenantId, $appointmentId, $reference);
    }

    /** @return array{0: string, 1: string}|null [reference, signature] */
    public static function parse(string $code): ?array
    {
        if (preg_match('/^([A-Z0-9]{8})-([0-9A-HJKMNP-TV-Z]{8})$/', strtoupper(trim($code)), $m) !== 1) {
            return null;
        }

        return [$m[1], $m[2]];
    }

    public function matches(int $tenantId, int $appointmentId, string $reference, string $signature): bool
    {
        return hash_equals($this->signature($tenantId, $appointmentId, $reference), $signature);
    }

    private function signature(int $tenantId, int $appointmentId, string $reference): string
    {
        $mac = hash_hmac('sha256', self::PURPOSE . $tenantId . '|' . $appointmentId . '|' . $reference, $this->config->appKey(), true);

        // First 40 bits → 8 Crockford base32 characters.
        $bits = '';
        foreach (str_split(substr($mac, 0, 5)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec($chunk)];
        }

        return $out;
    }
}
