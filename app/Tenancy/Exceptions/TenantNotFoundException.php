<?php

declare(strict_types=1);

namespace App\Tenancy\Exceptions;

use App\Core\Http\HttpException;

final class TenantNotFoundException extends HttpException
{
    public function __construct(string $message = 'This business could not be found.')
    {
        parent::__construct(404, 'TENANT_NOT_FOUND', $message);
    }
}
