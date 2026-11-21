<?php

declare(strict_types=1);

namespace App\Modules\Resources\Controllers;

use App\Core\Database\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Auth\Context\MembershipContext;
use App\Modules\Resources\Repositories\ResourceRepository;
use App\Shared\Http\ApiResponse;
use App\Shared\Support\UuidFactory;
use App\Tenancy\TenantContext;

/**
 * Private JSON API for bookable resources (session-authenticated, D-008).
 * Reads require resources.view; mutations require resources.manage.
 * Branch assignment is restricted to the member's accessible branches.
 */
final class ResourcesApiController
{
    private const PER_PAGE = 20;
    private const STATUSES = ['active', 'maintenance', 'inactive'];

    public function __construct(
        private readonly ResourceRepository $resources,
        private readonly Database $db,
        private readonly UuidFactory $uuids,
    ) {}

    /** @param array<string, string> $params */
    public function list(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('resources.view')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to view resources.', 403);
        }

        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => (string) $request->query('status', ''),
            'branch' => (int) $request->query('branch', 0),
        ];

        $page = max(1, (int) $request->query('page', 1));
        $result = $this->resources->listForTenant($tenant->tenantId, $filters, $page, self::PER_PAGE);
        $pages = max(1, (int) ceil($result['total'] / self::PER_PAGE));

        $rows = array_map(fn(array $row): array => $this->row($row), $result['rows']);
        if ($membership->branchIds !== null) {
            $rows = array_values(array_filter(
                $rows,
                static fn($r): bool => in_array((int) $r['branch_id'], $membership->branchIds, true),
            ));
        }

        return ApiResponse::ok([
            'rows' => $rows,
            'total' => count($rows),
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    /** Branches and resource types for the form.
     * @param array<string, string> $params
     */
    public function meta(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('resources.view')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to view resources.', 403);
        }

        $branches = $this->resources->branches($tenant->tenantId);
        if ($membership->branchIds !== null) {
            $branches = array_values(array_filter(
                $branches,
                static fn($b): bool => in_array((int) $b['id'], $membership->branchIds, true),
            ));
        }

        return ApiResponse::ok([
            'branches' => $branches,
            'types' => $this->resources->types($tenant->tenantId),
        ]);
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('resources.view')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to view resources.', 403);
        }

        $resource = $this->findScoped($tenant, $membership, (string) $params['uuid']);
        if ($resource === null) {
            return ApiResponse::error('NOT_FOUND', 'Resource not found.', 404);
        }

        return ApiResponse::ok(['resource' => $this->detail($tenant->tenantId, $resource)]);
    }

    /** @param array<string, string> $params */
    public function create(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('resources.manage')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to manage resources.', 403);
        }

        [$data, $schedule, $fields, $newType] = $this->validated($request->body(), $tenant, $membership);
        if ($fields !== []) {
            return ApiResponse::error('VALIDATION', 'Fix the highlighted fields.', 422, $fields);
        }

        $uuid = $this->uuids->generate();
        $this->db->transaction(function () use ($tenant, $data, $schedule, $uuid, $newType): void {
            if ($newType !== null) {
                $data['resource_type_id'] = $this->resources->insertType(
                    $tenant->tenantId,
                    $this->uuids->generate(),
                    $newType,
                );
            }
            $id = $this->resources->insert($data + [
                'uuid' => $uuid,
                'tenant_id' => $tenant->tenantId,
            ]);
            $this->resources->syncSchedules($tenant->tenantId, $id, $schedule);
        });

        $created = $this->resources->findByUuid($tenant->tenantId, $uuid);

        return ApiResponse::ok(['resource' => $created !== null ? $this->detail($tenant->tenantId, $created) : null], 201);
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('resources.manage')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to manage resources.', 403);
        }

        $resource = $this->findScoped($tenant, $membership, (string) $params['uuid']);
        if ($resource === null) {
            return ApiResponse::error('NOT_FOUND', 'Resource not found.', 404);
        }

        [$data, $schedule, $fields, $newType] = $this->validated($request->body(), $tenant, $membership);
        if ($fields !== []) {
            return ApiResponse::error('VALIDATION', 'Fix the highlighted fields.', 422, $fields);
        }

        $this->db->transaction(function () use ($tenant, $resource, $data, $schedule, $newType): void {
            if ($newType !== null) {
                $data['resource_type_id'] = $this->resources->insertType(
                    $tenant->tenantId,
                    $this->uuids->generate(),
                    $newType,
                );
            }
            $this->resources->update($tenant->tenantId, (int) $resource['id'], $data);
            $this->resources->syncSchedules($tenant->tenantId, (int) $resource['id'], $schedule);
        });

        $updated = $this->resources->findByUuid($tenant->tenantId, (string) $params['uuid']);

        return ApiResponse::ok(['resource' => $updated !== null ? $this->detail($tenant->tenantId, $updated) : null]);
    }

    /** @param array<string, string> $params */
    public function destroy(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('resources.manage')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to manage resources.', 403);
        }

        $resource = $this->findScoped($tenant, $membership, (string) $params['uuid']);
        if ($resource === null) {
            return ApiResponse::error('NOT_FOUND', 'Resource not found.', 404);
        }

        if ($this->resources->referenceCount($tenant->tenantId, (int) $resource['id']) > 0) {
            return ApiResponse::error(
                'RESOURCE_IN_USE',
                'This resource is linked to bookings. Mark it inactive or in maintenance instead.',
                409,
            );
        }

        $this->resources->delete($tenant->tenantId, (int) $resource['id']);

        return ApiResponse::ok(['deleted' => true]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: array<string, mixed>,
     *               1: list<array{weekday: int, starts_at: string, ends_at: string}>,
     *               2: array<string, string>,
     *               3: string|null}
     */
    private function validated(array $body, TenantContext $tenant, MembershipContext $membership): array
    {
        $fields = [];

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            $fields['name'] = 'Resource name is required (max 120 characters).';
        }

        $branchId = (int) ($body['branch_id'] ?? 0);
        $branchOk = $branchId > 0
            && $this->resources->branchValid($tenant->tenantId, $branchId)
            && ($membership->branchIds === null || in_array($branchId, $membership->branchIds, true));
        if (!$branchOk) {
            $fields['branch_id'] = 'Choose a branch you manage.';
        }

        $typeId = (int) ($body['resource_type_id'] ?? 0);
        $newType = trim((string) ($body['new_type'] ?? ''));
        if ($typeId > 0) {
            if (!$this->resources->typeValid($tenant->tenantId, $typeId)) {
                $fields['resource_type_id'] = 'Choose a valid resource type.';
            }
            $newType = null;
        } elseif ($newType === '' || mb_strlen($newType) > 100) {
            $fields['resource_type_id'] = 'Pick a type or enter a new one (max 100 characters).';
            $newType = null;
        }

        $capacity = (int) ($body['capacity'] ?? 0);
        if ($capacity < 1 || $capacity > 1000) {
            $fields['capacity'] = 'Capacity must be between 1 and 1000.';
        }

        $status = (string) ($body['status'] ?? 'active');
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'active';
        }

        $schedule = [];
        foreach ((array) ($body['schedule'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $weekday = (int) ($row['weekday'] ?? 0);
            $start = trim((string) ($row['starts_at'] ?? ''));
            $end = trim((string) ($row['ends_at'] ?? ''));
            if ($weekday < 1 || $weekday > 7) {
                continue;
            }
            if (preg_match('/^\d{2}:\d{2}$/', $start) !== 1 || preg_match('/^\d{2}:\d{2}$/', $end) !== 1 || $start >= $end) {
                $fields['schedule'] = 'Every available day needs a valid start and end time.';
                continue;
            }
            $schedule[] = ['weekday' => $weekday, 'starts_at' => $start . ':00', 'ends_at' => $end . ':00'];
        }

        $data = [
            'name' => $name,
            'branch_id' => $branchId,
            'resource_type_id' => $typeId > 0 ? $typeId : 0, // replaced when a new type is provided
            'capacity' => $capacity,
            'status' => $status,
        ];

        return [$data, $schedule, $fields, $newType];
    }

    /** Resource restricted to the member's branch scope.
     * @return array<string, mixed>|null
     */
    private function findScoped(TenantContext $tenant, MembershipContext $membership, string $uuid): ?array
    {
        $resource = $this->resources->findByUuid($tenant->tenantId, $uuid);
        if ($resource === null) {
            return null;
        }
        if ($membership->branchIds !== null && !in_array((int) $resource['branch_id'], $membership->branchIds, true)) {
            return null;
        }

        return $resource;
    }

    /** @param array<string, mixed> $resource
     * @return array<string, mixed>
     */
    private function detail(int $tenantId, array $resource): array
    {
        $schedule = [];
        foreach ($this->resources->schedules($tenantId, (int) $resource['id']) as $row) {
            $schedule[] = [
                'weekday' => (int) $row['weekday'],
                'starts_at' => substr((string) $row['starts_at'], 0, 5),
                'ends_at' => substr((string) $row['ends_at'], 0, 5),
            ];
        }

        $typeName = null;
        foreach ($this->resources->types($tenantId) as $type) {
            if ((int) $type['id'] === (int) $resource['resource_type_id']) {
                $typeName = $type['name'];
                break;
            }
        }
        $branchName = null;
        foreach ($this->resources->branches($tenantId) as $branch) {
            if ((int) $branch['id'] === (int) $resource['branch_id']) {
                $branchName = $branch['name'];
                break;
            }
        }

        return $this->row($resource) + [
            'branch_id' => (int) $resource['branch_id'],
            'resource_type_id' => (int) $resource['resource_type_id'],
            'type_name' => $typeName,
            'branch_name' => $branchName,
            'schedule' => $schedule,
            'references' => $this->resources->referenceCount($tenantId, (int) $resource['id']),
        ];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function row(array $row): array
    {
        return [
            'uuid' => $row['uuid'],
            'name' => $row['name'],
            'type' => $row['type_name'] ?? null,
            'branch' => $row['branch_name'] ?? null,
            'branch_id' => (int) $row['branch_id'],
            'capacity' => (int) $row['capacity'],
            'status' => $row['status'],
        ];
    }

    /** @return array{0: TenantContext, 1: MembershipContext} */
    private function context(Request $request): array
    {
        $tenant = $request->attribute('tenant');
        $membership = $request->attribute('membership');
        if (!$tenant instanceof TenantContext || !$membership instanceof MembershipContext) {
            throw new \LogicException('Tenant context required.');
        }

        return [$tenant, $membership];
    }
}
