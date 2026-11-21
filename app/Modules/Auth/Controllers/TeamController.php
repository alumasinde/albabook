<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Context\MembershipContext;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Auth\Repositories\InvitationRepository;
use App\Modules\Auth\Services\InvitationService;
use App\Tenancy\RoleRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantMembershipRepository;

/**
 * Team roster and invitations. Only members with users.manage reach this
 * controller (route permission, enforced by PermissionMiddleware).
 */
final class TeamController
{
    public function __construct(
        private readonly View $view,
        private readonly TenantMembershipRepository $memberships,
        private readonly InvitationRepository $invitations,
        private readonly RoleRepository $roles,
        private readonly InvitationService $service,
    ) {}

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        return $this->page($request, [], [], 200);
    }

    /** @param array<string, string> $params */
    public function invite(Request $request, array $params): Response
    {
        [$tenant, $actor] = $this->context($request);

        try {
            $this->service->invite($tenant, $actor, $request->body(), $request);
        } catch (ValidationException $e) {
            return $this->page($request, $e->errors, $e->old, 422);
        }

        return Response::redirect('/team?status=invited');
    }

    /** @param array<string, string> $params */
    public function revoke(Request $request, array $params): Response
    {
        [$tenant, $actor] = $this->context($request);
        $this->service->revoke($tenant, (int) ($params['invitation'] ?? 0), $actor->userId, $request);

        return Response::redirect('/team?status=revoked');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed>  $old
     */
    private function page(Request $request, array $errors, array $old, int $status): Response
    {
        [$tenant] = $this->context($request);

        return $this->view->response('team/index.twig', [
            'tenant' => $tenant,
            'members' => $this->memberships->listForTenant($tenant->tenantId),
            'invitations' => $this->invitations->pendingForTenant($tenant->tenantId),
            'roles' => $this->roles->forTenant($tenant->tenantId),
            'errors' => $errors,
            'old' => $old,
            'status' => $request->query('status', ''),
        ], $status);
    }

    /** @return array{0: TenantContext, 1: MembershipContext} */
    private function context(Request $request): array
    {
        $tenant = $request->attribute('tenant');
        $membership = $request->attribute('membership');
        if (!$tenant instanceof TenantContext || !$membership instanceof MembershipContext) {
            throw new HttpException(403, 'FORBIDDEN', 'You need an active workspace to manage the team.');
        }

        return [$tenant, $membership];
    }
}
