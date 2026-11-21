<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use App\Core\Http\HttpException;

final class TooManyAttemptsException extends HttpException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct(429, 'too_many_attempts', 'Too many attempts. Please wait a moment and try again.');
    }
}
