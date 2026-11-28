<?php

declare(strict_types=1);

namespace App\Shared\Http;

/**
 * Minimal curl POST client for provider calls (SMS gateways, webhook
 * endpoints). Always bounded by a timeout so a slow provider cannot stall
 * a worker.
 *
 * SSRF guard: tenant-configured URLs (webhooks) must not reach internal
 * addresses — the hostname is resolved first and every answer must be a
 * public IP, then the validated address is pinned via CURLOPT_RESOLVE so a
 * DNS-rebinding change between check and connect cannot redirect it.
 */
final class HttpClient
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    public function post(string $url, array $headers, string $body, int $timeoutSeconds = 15): array
    {
        return $this->request('POST', $url, $headers, $body, $timeoutSeconds);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    public function get(string $url, array $headers, int $timeoutSeconds = 15): array
    {
        return $this->request('GET', $url, $headers, null, $timeoutSeconds);
    }

    /**
     * Every outbound call goes through here, so the SSRF guard and the
     * no-redirect / https-only rules apply to GET and POST alike.
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    public function request(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds = 15): array
    {
        [$host, $port, $address] = $this->publicAddress($url);

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Could not initialise HTTP client.');
        }
        $methodOptions = $method === 'GET'
            ? [CURLOPT_HTTPGET => true]
            : [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_POSTFIELDS => (string) $body];
        curl_setopt_array($ch, $methodOptions + [
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
            CURLOPT_RESOLVE => [str_contains($address, ':')
                ? "{$host}:{$port}:[{$address}]"
                : "{$host}:{$port}:{$address}"],
            // No redirects: a 30x could otherwise point back at an internal target.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => 0,
        ]);

        $result = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($result === false) {
            throw new \RuntimeException('HTTP request failed: ' . ($error !== '' ? $error : 'unknown error'));
        }

        return ['status' => $status, 'body' => (string) $result];
    }

    /**
     * Resolves the URL's host and returns [host, port, a public address].
     * Rejects anything that is not https, does not resolve, or resolves to
     * any private/reserved address (loopback, link-local, RFC1918, CGNAT…).
     * @return array{0: string, 1: int, 2: string}
     */
    private function publicAddress(string $url): array
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        if (!is_string($host) || ($parts['scheme'] ?? '') !== 'https') {
            throw new \RuntimeException('Outbound calls require an https URL.');
        }
        $host = strtolower(trim($host, '[]'));
        $port = (int) ($parts['port'] ?? 443);

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (!self::isPublicIp($host)) {
                throw new \RuntimeException('Outbound calls cannot target private or reserved addresses.');
            }

            return [$host, $port, $host];
        }

        $records = dns_get_record($host, DNS_A + DNS_AAAA) ?: [];
        $addresses = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) {
                $addresses[] = $ip;
            }
        }
        if ($addresses === []) {
            throw new \RuntimeException("Webhook host '{$host}' does not resolve.");
        }
        foreach ($addresses as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new \RuntimeException('Outbound calls cannot target private or reserved addresses.');
            }
        }

        // Prefer IPv4 for CURLOPT_RESOLVE portability.
        $ipv4 = array_values(array_filter($addresses, static fn(string $a): bool => !str_contains($a, ':')));

        return [$host, $port, $ipv4[0] ?? $addresses[0]];
    }

    /**
     * Non-global IPv4 ranges (RFC 6890 "Global: false" plus multicast and
     * broadcast). Checked explicitly on top of FILTER_FLAG_GLOBAL_RANGE
     * because that flag's coverage has varied across PHP releases.
     */
    private const BLOCKED_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8',
        '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
        '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
        '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32',
    ];

    /**
     * True only for globally routable addresses. IPv6 forms that embed an
     * IPv4 address (IPv4-mapped ::ffff:a.b.c.d, IPv4-compatible ::a.b.c.d,
     * NAT64 64:ff9b::/96, 6to4 2002::/16) are judged by that embedded
     * address, so ::ffff:127.0.0.1 or 2002:7f00:1:: cannot reach loopback.
     */
    private static function isPublicIp(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 16) {
            $embedded = self::embeddedIpv4($packed);
            if ($embedded !== null) {
                return self::isPublicIp($embedded);
            }
        } else {
            foreach (self::BLOCKED_V4 as $cidr) {
                if (self::inV4Cidr($packed, $cidr)) {
                    return false;
                }
            }
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE,
        ) !== false;
    }

    /** The IPv4 address carried inside an IPv6 address, if any. */
    private static function embeddedIpv4(string $packed): ?string
    {
        $prefix12 = substr($packed, 0, 12);
        // ::ffff:a.b.c.d (mapped) and ::a.b.c.d (compatible, deprecated);
        // :: and ::1 themselves are left to the global-range filter.
        if ($prefix12 === str_repeat("\0", 10) . "\xff\xff"
            || ($prefix12 === str_repeat("\0", 12) && substr($packed, 12) !== "\0\0\0\0" && substr($packed, 12) !== "\0\0\0\1")) {
            return (string) inet_ntop(substr($packed, 12));
        }
        // NAT64 well-known prefix 64:ff9b::/96.
        if ($prefix12 === "\x00\x64\xff\x9b" . str_repeat("\0", 8)) {
            return (string) inet_ntop(substr($packed, 12));
        }
        // 6to4 2002:AABB:CCDD::/48 carries AA.BB.CC.DD.
        if (substr($packed, 0, 2) === "\x20\x02") {
            return (string) inet_ntop(substr($packed, 2, 4));
        }

        return null;
    }

    private static function inV4Cidr(string $packed, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $mask = $bits === '0' ? 0 : (~0 << (32 - (int) $bits)) & 0xFFFFFFFF;
        $ipU = unpack('N', $packed);
        $baseU = unpack('N', (string) inet_pton($net));
        if ($ipU === false || $baseU === false) {
            return false;
        }

        return ($ipU[1] & $mask) === ($baseU[1] & $mask);
    }
}
