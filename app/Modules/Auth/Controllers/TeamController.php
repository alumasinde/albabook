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
use App\Modules\Auth\Services\TeamMemberService;
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
        private readonly TeamMemberService $team,
    ) {}

    /** Edit one member: roles, branch scope, deactivate / reactivate.
     * @param array<string, string> $params
     */
    public function member(Request $request, array $params): Response
    {
        return $this->memberPage($request, (int) ($params['member'] ?? 0), [], [], 200);
    }

    /** @param array<string, string> $params */
    public function updateMember(Request $request, array $params): Response
    {
        [$tenant, $actor] = $this->context($request);
        $id = (int) ($params['member'] ?? 0);
        try {
            $this->team->update($tenant, $actor, $id, $request->body(), $request);
        } catch (ValidationException $e) {
            return $this->memberPage($request, $id, $e->errors, $request->body(), 422);
        }

        return Response::redirect('/team?status=member_updated');
    }

    /** @param array<string, string> $params */
    public function deactivateMember(Request $request, array $params): Response
    {
        [$tenant, $actor] = $this->context($request);
        $id = (int) ($params['member'] ?? 0);
        try {
            $this->team->deactivate($tenant, $actor, $id, $request);
        } catch (ValidationException $e) {
            return $this->memberPage($request, $id, $e->errors, [], 422);
        }

        return Response::redirect('/team?status=member_deactivated');
    }

    /** @param array<string, string> $params */
    public function reactivateMember(Request $request, array $params): Response
    {
        [$tenant, $actor] = $this->context($request);
        $id = (int) ($params['member'] ?? 0);
        try {
            $this->team->reactivate($tenant, $actor, $id, $request);
        } catch (ValidationException $e) {
            return $this->memberPage($request, $id, $e->errors, [], 422);
        }

        return Response::redirect('/team?status=member_reactivated');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed>  $old
     */
    private function memberPage(Request $request, int $id, array $errors, array $old, int $status): Response
    {
        [$tenant, $actor] = $this->context($request);
        $member = $this->team->member($tenant->tenantId, $id);
        if ($member === null) {
            throw new HttpException(404, 'NOT_FOUND', 'That team member was not found.');
        }
        $roleIds = is_array($old['role_ids'] ?? null) ? array_map('intval', $old['role_ids']) : $member['role_ids'];
        $branchIds = is_array($old['branch_ids'] ?? null) ? array_map('intval', $old['branch_ids']) : $member['branch_ids'];
        $scope = isset($old['branch_scope']) ? (string) $old['branch_scope'] : ($member['branch_ids'] === [] ? 'all' : 'some');

        return $this->view->response('team/member.twig', [
            'tenant' => $tenant,
            'member' => $member,
            'is_self' => $id === $actor->tenantUserId,
            'roles' => $this->roles->forTenant($tenant->tenantId),
            'branches' => $this->team->branches($tenant->tenantId),
            'selected_roles' => $roleIds,
            'selected_branches' => $branchIds,
            'branch_scope' => $scope,
            'errors' => $errors,
        ], $status);
    }

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
