<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use App\Core\Http\HttpException;

final class MfaFailedException extends HttpException
{
    public function __construct(string $message = 'That code is not correct. Try again.')
    {
        parent::__construct(422, 'mfa_failed', $message);
    }
}
