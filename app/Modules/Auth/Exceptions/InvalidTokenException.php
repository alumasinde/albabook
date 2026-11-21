<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use App\Core\Http\HttpException;

final class InvalidTokenException extends HttpException
{
    public function __construct(string $message = 'This link is invalid or has expired.')
    {
        parent::__construct(422, 'invalid_token', $message);
    }
}
