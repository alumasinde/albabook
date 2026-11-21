<?php

declare(strict_types=1);

namespace App\Tenancy\Exceptions;

use App\Core\Http\HttpException;

class TenantAccessDeniedException extends HttpException
{
    public function __construct(string $message = 'You do not have access to this business.')
    {
        parent::__construct(403, 'TENANT_ACCESS_DENIED', $message);
    }
}
