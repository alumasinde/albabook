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
        [$host, $port, $address] = $this->publicAddress($url);

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Could not initialise HTTP client.');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
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

    private static function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
