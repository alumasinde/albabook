<?php

declare(strict_types=1);

namespace App\Modules\Platform\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Platform\Context\PlatformAuthContext;
use App\Modules\Platform\Repositories\AuditLogRepository;

/** Platform audit-log viewer (platform.audit.view). Read-only. */
final class AuditLogsController
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly View $view,
        private readonly PlatformAuthContext $auth,
        private readonly AuditLogRepository $logs,
    ) {}

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $filters = [
            'action' => trim((string) $request->query('action', '')),
            'actor_type' => (string) $request->query('actor_type', ''),
            'tenant' => trim((string) $request->query('tenant', '')),
            'from' => trim((string) $request->query('from', '')),
            'to' => trim((string) $request->query('to', '')),
        ];
        $page = max(1, (int) $request->query('page', 1));
        $result = $this->logs->list($filters, $page, self::PER_PAGE);

        return $this->view->response('admin/audit-logs.twig', [
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
            'filters' => $filters,
            'active_nav' => 'audit_logs',
            'page_title' => 'Audit logs',
            'admin_email' => $this->auth->user?->email,
        ]);
    }
}
