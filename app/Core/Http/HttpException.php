<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * An error that maps to an HTTP status and a stable, public error code
 * (booking-engine.md §18 error contract). The message is shown to users,
 * so it must never contain internal details.
 */
class HttpException extends \RuntimeException
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $publicMessage,
        public readonly array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($publicMessage, 0, $previous);
    }

    public static function badRequest(string $code = 'BAD_REQUEST', string $message = 'The request could not be understood.'): self
    {
        return new self(400, $code, $message);
    }

    public static function notFound(string $message = 'The page you are looking for does not exist.'): self
    {
        return new self(404, 'NOT_FOUND', $message);
    }

    /** @param list<string> $allowed */
    public static function methodNotAllowed(array $allowed): self
    {
        return new self(405, 'METHOD_NOT_ALLOWED', 'This action is not supported here.', ['Allow' => implode(', ', $allowed)]);
    }

    public static function csrfMismatch(): self
    {
        return new self(419, 'CSRF_TOKEN_MISMATCH', 'Your session expired or the form was out of date. Please try again.');
    }

    public static function serviceUnavailable(int $retryAfterSeconds = 300): self
    {
        return new self(503, 'MAINTENANCE', 'AlbaBook is briefly unavailable for maintenance. Please try again shortly.', [
            'Retry-After' => (string) $retryAfterSeconds,
        ]);
    }
}
