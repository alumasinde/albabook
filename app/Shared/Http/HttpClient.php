<?php

declare(strict_types=1);

namespace App\Shared\Http;

/**
 * Minimal curl POST client for provider calls (SMS gateways, webhook
 * endpoints). Always bounded by a timeout so a slow provider cannot stall
 * a worker.
 */
final class HttpClient
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    public function post(string $url, array $headers, string $body, int $timeoutSeconds = 15): array
    {
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
}
