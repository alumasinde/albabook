<?php

declare(strict_types=1);

namespace App\Shared\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Masks personal data and secrets before any log handler sees them
 * (decisions.md D-012, plan.md §30 rule 29).
 *
 * - Emails      → "j***@***"
 * - Phone-like  → "***123" (E.164, 254…, Kenyan 07…/01…)
 * - Long tokens → "[redacted]" (≥32 URL-safe chars: hashes, API secrets, reset tokens)
 * - Values under sensitive keys (password, token, secret, authorization, …) → "[redacted]"
 *
 * Masking is a safety net, not permission: code must still avoid logging
 * personal data in the first place.
 */
final class PiiMaskingProcessor implements ProcessorInterface
{
    private const SENSITIVE_KEYS = [
        'password', 'password_confirmation', 'current_password', 'new_password',
        'token', 'access_token', 'refresh_token', 'secret', 'client_secret', 'api_key',
        'authorization', 'cookie', 'set-cookie', 'credentials', 'otp', 'passkey',
        'card_number', 'cvv', 'pin', 'kra_pin',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: self::maskString($record->message),
            context: self::maskArray($record->context),
            extra: self::maskArray($record->extra),
        );
    }

    public static function maskString(string $value): string
    {
        $value = (string) preg_replace_callback(
            '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',
            static fn (array $m): string => mb_substr($m[0], 0, 1) . '***@***',
            $value,
        );

        // Tokens before phones, so digits inside a token are not half-masked.
        $value = (string) preg_replace('/(?<![A-Za-z0-9_-])[A-Za-z0-9_-]{32,}(?![A-Za-z0-9_-])/', '[redacted]', $value);

        // Deliberately narrow so dates, times, amounts and ids are left alone:
        // E.164 (+ and 9–15 digits), and Kenyan mobile/landline forms with
        // optional single spaces or hyphens (07xx…, 01xx…, 2547…, +254 7…).
        $patterns = [
            '/(?<![\w+])\+\d{9,15}(?!\d)/',
            '/(?<![\w+])\+?254[\s-]?[17]\d{2}[\s-]?\d{3}[\s-]?\d{3}(?!\d)/',
            '/(?<![\w+])0[17]\d{2}[\s-]?\d{3}[\s-]?\d{3}(?!\d)/',
        ];

        return (string) preg_replace_callback(
            $patterns,
            static fn (array $m): string => '***' . substr(preg_replace('/\D/', '', $m[0]) ?? '', -3),
            $value,
        );
    }

    /** @return array<string, mixed> */
    public static function maskThrowable(\Throwable $e, int $depth = 0): array
    {
        $data = [
            'class' => $e::class,
            'message' => self::maskString($e->getMessage()),
            'code' => $e->getCode(),
            'file' => $e->getFile() . ':' . $e->getLine(),
            'trace' => self::maskString($e->getTraceAsString()),
        ];
        if ($e->getPrevious() !== null && $depth < 3) {
            $data['previous'] = self::maskThrowable($e->getPrevious(), $depth + 1);
        }

        return $data;
    }

    /**
     * @param array<mixed> $values
     * @return array<mixed>
     */
    public static function maskArray(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                $values[$key] = '[redacted]';
            } elseif (is_string($value)) {
                $values[$key] = self::maskString($value);
            } elseif (is_array($value)) {
                $values[$key] = self::maskArray($value);
            } elseif ($value instanceof \Throwable) {
                // Exception messages can echo user input (e.g. a duplicate-key
                // error quoting an email), so they are masked like any string.
                $values[$key] = self::maskThrowable($value);
            }
        }

        return $values;
    }
}
