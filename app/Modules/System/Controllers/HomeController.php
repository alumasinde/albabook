<?php

declare(strict_types=1);

namespace App\Modules\System\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Auth\Context\AuthContext;
use App\Modules\Platform\Context\PlatformAuthContext;

/**
 * Placeholder landing pages per host until Phase 2 (login), Phase 6 (booking
 * pages) and Phase 9 (API) replace them. Each renders its host's shell so the
 * four layouts can be reviewed.
 */
final class HomeController
{
    public function __construct(
        private readonly View $view,
        private readonly AuthContext $auth,
        private readonly PlatformAuthContext $platformAuth,
    ) {}

    /** @param array<string, string> $params */
    public function tenant(Request $request, array $params): Response
    {
        if ($this->auth->user !== null) {
            return Response::redirect($this->auth->mfaPending ? '/two-factor' : '/dashboard');
        }

        return Response::redirect('/login');
    }

    /** @param array<string, string> $params */
    public function booking(Request $request, array $params): Response
    {
        return $this->view->response('pages/home/booking.twig');
    }

    /** @param array<string, string> $params */
    public function admin(Request $request, array $params): Response
    {
        if ($this->platformAuth->isAuthenticated()) {
            return Response::redirect($this->platformAuth->mfaPending ? '/two-factor' : '/dashboard');
        }

        return Response::redirect('/login');
    }

    /** @param array<string, string> $params */
    public function api(Request $request, array $params): Response
    {
        return Response::json(['name' => 'AlbaBook API', 'versions' => ['v1' => 'coming soon']]);
    }
}
