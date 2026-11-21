<?php

declare(strict_types=1);

namespace App\Shared\Support;

/**
 * Decides whether X-Forwarded-* headers may be believed: only when the
 * direct peer (REMOTE_ADDR) is a configured proxy (multi-tenancy.md §7).
 */
final class TrustedProxies
{
    /** @param list<string> $entries IPs or CIDR ranges */
    public function __construct(private readonly array $entries) {}

    public function isTrusted(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        foreach ($this->entries as $entry) {
            if (self::matches($ip, $entry)) {
                return true;
            }
        }

        return false;
    }

    public static function isValidEntry(string $entry): bool
    {
        if (filter_var($entry, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        if (!str_contains($entry, '/')) {
            return false;
        }
        [$network, $bits] = explode('/', $entry, 2);
        $max = filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 32 : 128;

        return filter_var($network, FILTER_VALIDATE_IP) !== false
            && ctype_digit($bits)
            && (int) $bits <= $max;
    }

    private static function matches(string $ip, string $entry): bool
    {
        if (!str_contains($entry, '/')) {
            return inet_pton($ip) === inet_pton($entry);
        }

        [$network, $bits] = explode('/', $entry, 2);
        $ipBin = inet_pton($ip);
        $netBin = inet_pton($network);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;
        if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        if ($remainder === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }
}
