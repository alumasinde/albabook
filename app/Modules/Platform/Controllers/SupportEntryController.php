<?php

declare(strict_types=1);

namespace App\Modules\Platform\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Platform\Services\SupportSessionService;
use App\Modules\Platform\Support\SupportEntryToken;
use App\Shared\Http\AppUrl;
use App\Shared\Security\AuthCookieFactory;

/**
 * Tenant-host entry and exit for support sessions. Entry is a POST exchange:
 * the admin console hands over a single-use, short-lived code (never the
 * cookie credential, never in a URL) which is consumed atomically; a freshly
 * minted, distinct cookie token is issued in its place (multi-tenancy.md §7).
 */
final class SupportEntryController
{
    public function __construct(
        private readonly SupportSessionService $sessions,
        private readonly SupportEntryToken $tokens,
        private readonly AuthCookieFactory $cookies,
        private readonly AppUrl $urls,
    ) {}

    /**
     * Support entry is POST-only: GETs with a credential in the query string
     * would leak it into access logs, history and Referer headers.
     * @param array<string, string> $params
     */
    public function enter(Request $request, array $params): Response
    {
        throw new HttpException(403, 'SUPPORT_ACCESS_DENIED', 'Support sessions are entered from the admin console.');
    }

    /** @param array<string, string> $params */
    public function exchange(Request $request, array $params): Response
    {
        $code = (string) $request->input('code', '');
        $claims = $code === '' ? null : $this->sessions->exchangeEntryCode($code);
        if ($claims === null) {
            throw new HttpException(403, 'SUPPORT_ACCESS_DENIED', 'This support link is invalid or has expired.');
        }

        $token = $this->tokens->issue($claims['uuid'], $claims['expires_at'], $claims['write_allowed']);

        return Response::redirect('/dashboard')
            ->withCookie($this->cookies->support($request, $token, $claims['expires_at']));
    }

    /** @param array<string, string> $params */
    public function exit(Request $request, array $params): Response
    {
        return Response::redirect($this->urls->admin('/dashboard', $request))
            ->withCookie($this->cookies->clearSupport($request));
    }
}
