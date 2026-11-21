<?php

declare(strict_types=1);

namespace App\Modules\Platform\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Platform\Context\PlatformAuthContext;
use App\Modules\Platform\Services\SupportSessionService;
use App\Shared\Http\AppUrl;

/**
 * Starts and ends audited support sessions from the admin app and redirects the
 * administrator to the signed tenant-host entry (multi-tenancy.md §7).
 */
final class SupportSessionController
{
    public function __construct(
        private readonly PlatformAuthContext $auth,
        private readonly SupportSessionService $support,
        private readonly AppUrl $urls,
    ) {}

    /** @param array<string, string> $params */
    public function start(Request $request, array $params): Response
    {
        $tenantId = (int) $request->input('tenant_id', 0);
        if ($tenantId <= 0) {
            return Response::redirect('/dashboard?status=support_invalid');
        }

        try {
            $session = $this->support->start(
                $this->auth,
                $tenantId,
                (string) $request->input('reason', ''),
                (int) $request->input('minutes', 60),
                in_array($request->input('write'), ['1', 'on', 'yes'], true),
                $request,
            );
        } catch (HttpException) {
            return Response::redirect('/dashboard?status=support_denied');
        }

        return Response::redirect($this->urls->app(
            '/support/enter?token=' . urlencode($session['token']),
            $request,
        ));
    }

    /** @param array<string, string> $params */
    public function end(Request $request, array $params): Response
    {
        $this->support->end($this->auth, (int) ($params['session'] ?? 0), $request);

        return Response::redirect('/dashboard?status=support_ended');
    }
}
