<?php

declare(strict_types=1);

namespace App\Modules\Branches\Controllers;

use App\Core\Database\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Auth\Context\MembershipContext;
use App\Modules\Branches\Repositories\BranchRepository;
use App\Shared\Http\ApiResponse;
use App\Shared\Support\UuidFactory;
use App\Tenancy\TenantContext;

/**
 * Private JSON API for branches (session-authenticated, D-008).
 * Reads require branches.view; mutations require branches.manage.
 * Branch-scope members only see and edit their assigned branches.
 */
final class BranchesApiController
{
    public function __construct(
        private readonly BranchRepository $branches,
        private readonly Database $db,
        private readonly UuidFactory $uuids,
    ) {}

    /** @param array<string, string> $params */
    public function list(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('branches.view')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to view branches.', 403);
        }

        $result = $this->branches->listForTenant($tenant->tenantId, [
            'q' => trim((string) $request->query('q', '')),
        ]);

        $rows = array_map(fn(array $row): array => $this->row($row), $result['rows']);
        if ($membership->branchIds !== null) {
            $allowed = $this->allowedIds($tenant, $membership);
            $rows = array_values(array_filter($rows, static fn($r): bool => in_array($r['id'], $allowed, true)));
        }

        return ApiResponse::ok(['rows' => $rows, 'total' => count($rows)]);
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('branches.view')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to view branches.', 403);
        }

        $branch = $this->findScoped($tenant, $membership, (string) $params['uuid']);
        if ($branch === null) {
            return ApiResponse::error('NOT_FOUND', 'Branch not found.', 404);
        }

        return ApiResponse::ok(['branch' => $this->detail($tenant->tenantId, $branch)]);
    }

    /** @param array<string, string> $params */
    public function create(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('branches.manage')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to manage branches.', 403);
        }

        [$data, $hours, $fields] = $this->validated($request->body());
        if ($fields !== []) {
            return ApiResponse::error('VALIDATION', 'Fix the highlighted fields.', 422, $fields);
        }

        $uuid = $this->uuids->generate();
        $this->db->transaction(function () use ($tenant, $data, $hours, $uuid): void {
            $id = $this->branches->insert($data + [
                'uuid' => $uuid,
                'tenant_id' => $tenant->tenantId,
            ]);
            $this->branches->syncBusinessHours($tenant->tenantId, $id, $hours);
        });

        $created = $this->branches->findByUuid($tenant->tenantId, $uuid);

        return ApiResponse::ok(['branch' => $created !== null ? $this->detail($tenant->tenantId, $created) : null], 201);
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('branches.manage')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to manage branches.', 403);
        }

        $branch = $this->findScoped($tenant, $membership, (string) $params['uuid']);
        if ($branch === null) {
            return ApiResponse::error('NOT_FOUND', 'Branch not found.', 404);
        }

        [$data, $hours, $fields] = $this->validated($request->body());
        if ($fields !== []) {
            return ApiResponse::error('VALIDATION', 'Fix the highlighted fields.', 422, $fields);
        }

        $this->db->transaction(function () use ($tenant, $branch, $data, $hours): void {
            $this->branches->update($tenant->tenantId, (int) $branch['id'], $data);
            $this->branches->syncBusinessHours($tenant->tenantId, (int) $branch['id'], $hours);
        });

        $updated = $this->branches->findByUuid($tenant->tenantId, (string) $params['uuid']);

        return ApiResponse::ok(['branch' => $updated !== null ? $this->detail($tenant->tenantId, $updated) : null]);
    }

    /** @param array<string, string> $params */
    public function destroy(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('branches.manage')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to manage branches.', 403);
        }

        $branch = $this->findScoped($tenant, $membership, (string) $params['uuid']);
        if ($branch === null) {
            return ApiResponse::error('NOT_FOUND', 'Branch not found.', 404);
        }

        if ($this->branches->referenceCount($tenant->tenantId, (int) $branch['id']) > 0) {
            return ApiResponse::error(
                'BRANCH_IN_USE',
                'This branch has bookings, resources or payment links. Deactivate it instead.',
                409,
            );
        }

        $this->branches->delete($tenant->tenantId, (int) $branch['id']);

        return ApiResponse::ok(['deleted' => true]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: array<string, mixed>,
     *               1: list<array{weekday: int, opens_at: string, closes_at: string}>,
     *               2: array<string, string>}
     */
    private function validated(array $body): array
    {
        $fields = [];

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150) {
            $fields['name'] = 'Branch name is required (max 150 characters).';
        }

        $email = trim((string) ($body['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $fields['email'] = 'Enter a valid email address.';
        }

        $phone = trim((string) ($body['phone'] ?? ''));
        if (mb_strlen($phone) > 30) {
            $fields['phone'] = 'Phone number is too long (max 30 characters).';
        }

        $timezone = trim((string) ($body['timezone'] ?? ''));
        if ($timezone !== '' && !in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            $fields['timezone'] = 'Choose a valid timezone.';
        }

        $hours = [];
        foreach ((array) ($body['hours'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $weekday = (int) ($row['weekday'] ?? 0);
            $open = trim((string) ($row['opens_at'] ?? ''));
            $close = trim((string) ($row['closes_at'] ?? ''));
            if ($weekday < 1 || $weekday > 7) {
                continue;
            }
            if (preg_match('/^\d{2}:\d{2}$/', $open) !== 1 || preg_match('/^\d{2}:\d{2}$/', $close) !== 1 || $open >= $close) {
                $fields['hours'] = 'Every open day needs a valid opening and closing time.';
                continue;
            }
            $hours[] = ['weekday' => $weekday, 'opens_at' => $open . ':00', 'closes_at' => $close . ':00'];
        }
        if ($hours === [] && !isset($fields['hours'])) {
            $fields['hours'] = 'Set opening hours for at least one day.';
        }

        $data = [
            'name' => $name,
            'address_line1' => trim((string) ($body['address_line1'] ?? '')) ?: null,
            'address_line2' => trim((string) ($body['address_line2'] ?? '')) ?: null,
            'county' => trim((string) ($body['county'] ?? '')) ?: null,
            'town' => trim((string) ($body['town'] ?? '')) ?: null,
            'phone' => $phone !== '' ? $phone : null,
            'email' => $email !== '' ? $email : null,
            'timezone' => $timezone !== '' ? $timezone : null,
            'is_active' => !empty($body['is_active']) ? 1 : 0,
        ];

        return [$data, $hours, $fields];
    }

    /** Branch row restricted to the membership's branch scope.
     * @return array<string, mixed>|null
     */
    private function findScoped(TenantContext $tenant, MembershipContext $membership, string $uuid): ?array
    {
        $branch = $this->branches->findByUuid($tenant->tenantId, $uuid);
        if ($branch === null) {
            return null;
        }
        if ($membership->branchIds !== null && !in_array((int) $branch['id'], $this->allowedIds($tenant, $membership), true)) {
            return null;
        }

        return $branch;
    }

    /** @return list<int> */
    private function allowedIds(TenantContext $tenant, MembershipContext $membership): array
    {
        return $membership->branchIds ?? [];
    }

    /** @param array<string, mixed> $branch
     * @return array<string, mixed>
     */
    private function detail(int $tenantId, array $branch): array
    {
        $hours = [];
        foreach ($this->branches->businessHours($tenantId, (int) $branch['id']) as $row) {
            $hours[] = [
                'weekday' => (int) $row['weekday'],
                'opens_at' => substr((string) $row['opens_at'], 0, 5),
                'closes_at' => substr((string) $row['closes_at'], 0, 5),
            ];
        }

        return $this->row($branch) + [
            'address_line1' => $branch['address_line1'],
            'address_line2' => $branch['address_line2'],
            'county' => $branch['county'],
            'town' => $branch['town'],
            'timezone' => $branch['timezone'],
            'hours' => $hours,
            'references' => $this->branches->referenceCount($tenantId, (int) $branch['id']),
        ];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function row(array $row): array
    {
        $address = implode(', ', array_filter([
            $row['address_line1'] ?? null,
            $row['town'] ?? null,
            $row['county'] ?? null,
        ]));

        return [
            'id' => (int) $row['id'],
            'uuid' => $row['uuid'],
            'name' => $row['name'],
            'address' => $address !== '' ? $address : null,
            'phone' => $row['phone'],
            'email' => $row['email'],
            'staff' => (int) ($row['staff_count'] ?? 0),
            'resources' => (int) ($row['resource_count'] ?? 0),
            'is_active' => (bool) $row['is_active'],
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
