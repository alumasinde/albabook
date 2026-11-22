<?php

declare(strict_types=1);

namespace App\Modules\Platform\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Marketing\Controllers\MarketingController;
use App\Modules\Marketing\Repositories\MarketingRepository;
use App\Modules\Platform\Context\PlatformAuthContext;

/** Demo requests from the marketing site (platform.tenants.manage). */
final class LeadsController
{
    private const STATUSES = ['new', 'contacted', 'closed'];

    public function __construct(
        private readonly View $view,
        private readonly PlatformAuthContext $auth,
        private readonly MarketingRepository $marketing,
    ) {}

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $status = (string) $request->query('status', 'new');
        $filter = in_array($status, self::STATUSES, true) ? $status : null;

        return $this->view->response('admin/leads.twig', [
            'rows' => $this->marketing->demoRequests($filter),
            'counts' => $this->marketing->demoRequestCounts(),
            'filter' => $filter ?? 'all',
            'statuses' => self::STATUSES,
            'business_types' => MarketingController::BUSINESS_TYPES,
            'branch_counts' => MarketingController::BRANCH_COUNTS,
            'status' => (string) $request->query('saved', ''),
            'active_nav' => 'leads',
            'page_title' => 'Demo requests',
            'admin_email' => $this->auth->user?->email,
        ]);
    }

    /** @param array<string, string> $params */
    public function updateStatus(Request $request, array $params): Response
    {
        $status = (string) $request->input('status', '');
        $back = (string) $request->input('back', 'new');
        $back = in_array($back, [...self::STATUSES, 'all'], true) ? $back : 'new';
        if (in_array($status, self::STATUSES, true)) {
            $this->marketing->setDemoRequestStatus((string) $params['uuid'], $status);
        }

        return Response::redirect('/leads?status=' . $back . '&saved=1', 303);
    }
}
