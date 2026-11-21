<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use App\Core\Http\HttpException;

/**
 * Deliberately indistinguishable from an unknown email (auth.md §8b).
 */
final class InvalidCredentialsException extends HttpException
{
    public function __construct(string $message = 'These credentials do not match our records.')
    {
        parent::__construct(422, 'invalid_credentials', $message);
    }
}
