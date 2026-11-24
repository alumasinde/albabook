<?php

declare(strict_types=1);

namespace App\Modules\Appointments\Services;

/**
 * Carries the API error code + HTTP status for a rejected appointment change.
 */
final class TransitionException extends \RuntimeException
{
    /** @param array<string, string|bool|list<string>> $fields */
    public function __construct(
        public readonly string $apiCode,
        string $message,
        public readonly int $status = 400,
        public readonly array $fields = [],
    ) {
        parent::__construct($message);
    }
}
