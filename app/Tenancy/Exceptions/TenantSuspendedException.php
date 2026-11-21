<?php

declare(strict_types=1);

namespace App\Tenancy\Exceptions;

final class TenantSuspendedException extends TenantAccessDeniedException
{
    public function __construct(string $message = 'This business account is unavailable. Contact support if you believe this is a mistake.')
    {
        parent::__construct($message);
    }
}
