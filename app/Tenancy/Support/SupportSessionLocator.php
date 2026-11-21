<?php

declare(strict_types=1);

namespace App\Tenancy\Support;

use App\Core\Http\Request;

/**
 * Resolves the support cookie on the tenant host. Implemented by the platform
 * module; the tenancy layer depends only on this contract.
 */
interface SupportSessionLocator
{
    public function locate(Request $request): ?SupportAccess;

    /** Validates a signed entry token without requiring the cookie. */
    public function locateToken(string $token): ?SupportAccess;
}
