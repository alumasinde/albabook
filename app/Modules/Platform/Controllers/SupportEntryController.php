<?php

declare(strict_types=1);

namespace App\Modules\Platform\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Shared\Http\AppUrl;
use App\Shared\Security\AuthCookieFactory;
use App\Tenancy\Support\SupportSessionLocator;

/**
 * Tenant-host entry and exit for support sessions. The entry token is HMAC
 * signed and re-checked against support_access_sessions before the cookie is
 * set, so revoking the session kills every outstanding link.
 */
final class SupportEntryController
{
    public function __construct(
        private readonly SupportSessionLocator $sessions,
        private readonly AuthCookieFactory $cookies,
        private readonly AppUrl $urls,
    ) {}

    /** @param array<string, string> $params */
    public function enter(Request $request, array $params): Response
    {
        $token = (string) $request->query('token', '');
        $access = $token === '' ? null : $this->sessions->locateToken($token);
        if ($access === null) {
            throw new HttpException(403, 'SUPPORT_ACCESS_DENIED', 'This support link is invalid or has expired.');
        }

        return Response::redirect('/dashboard')
            ->withCookie($this->cookies->support($request, $token, $access->expiresAt->getTimestamp()));
    }

    /** @param array<string, string> $params */
    public function exit(Request $request, array $params): Response
    {
        return Response::redirect($this->urls->admin('/dashboard', $request))
            ->withCookie($this->cookies->clearSupport($request));
    }
}
