<?php

declare(strict_types=1);

namespace App\Modules\Customers\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Auth\Context\MembershipContext;
use App\Modules\Customers\Repositories\CustomerRepository;
use App\Shared\Http\ApiResponse;
use App\Shared\Support\UuidFactory;
use App\Tenancy\TenantContext;

/**
 * Private JSON API for the customer directory (session-authenticated, D-008).
 * Read endpoints require customers.view; writes use the granular
 * create/update/delete permissions; notes are gated by customers.view_notes.
 */
final class CustomersApiController
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly UuidFactory $uuids,
    ) {}

    /** @param array<string, string> $params */
    public function list(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('customers.view')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to view customers.', 403);
        }

        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => (string) $request->query('status', ''),
        ];

        $page = max(1, (int) $request->query('page', 1));
        $result = $this->customers->listForTenant($tenant->tenantId, $filters, $page, self::PER_PAGE, $membership->branchIds);
        $pages = max(1, (int) ceil($result['total'] / self::PER_PAGE));

        return ApiResponse::ok([
            'rows' => array_map(fn(array $row): array => $this->row($row), $result['rows']),
            'total' => $result['total'],
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    /** Preferred staff/service dropdown options.
     * @param array<string, string> $params
     */
    public function meta(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('customers.view')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to view customers.', 403);
        }

        return ApiResponse::ok([
            'staff' => $this->customers->staffOptions($tenant->tenantId),
            'services' => $this->customers->serviceOptions($tenant->tenantId),
        ]);
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('customers.view')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to view customers.', 403);
        }

        $customer = $this->customers->findByUuid($tenant->tenantId, (string) $params['uuid']);
        if ($customer === null || !$this->customers->visibleAtBranches($tenant->tenantId, (int) $customer['id'], $membership->branchIds)) {
            return ApiResponse::error('NOT_FOUND', 'Customer not found.', 404);
        }

        return ApiResponse::ok(['customer' => $this->detail($tenant->tenantId, $customer, $membership)]);
    }

    /** @param array<string, string> $params */
    public function create(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('customers.create')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to add customers.', 403);
        }

        [$data, $fields] = $this->validated($request->body(), $tenant, $membership);
        if ($fields !== []) {
            return ApiResponse::error('VALIDATION', 'Fix the highlighted fields.', 422, $fields);
        }

        $uuid = $this->uuids->generate();
        $this->customers->insert($data + [
            'uuid' => $uuid,
            'tenant_id' => $tenant->tenantId,
        ]);

        $created = $this->customers->findByUuid($tenant->tenantId, $uuid);

        return ApiResponse::ok(['customer' => $created !== null ? $this->detail($tenant->tenantId, $created, $membership) : null], 201);
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('customers.update')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to edit customers.', 403);
        }

        $customer = $this->customers->findByUuid($tenant->tenantId, (string) $params['uuid']);
        if ($customer === null || !$this->customers->visibleAtBranches($tenant->tenantId, (int) $customer['id'], $membership->branchIds)) {
            return ApiResponse::error('NOT_FOUND', 'Customer not found.', 404);
        }

        [$data, $fields] = $this->validated($request->body(), $tenant, $membership, $customer);
        if ($fields !== []) {
            return ApiResponse::error('VALIDATION', 'Fix the highlighted fields.', 422, $fields);
        }

        $this->customers->update($tenant->tenantId, (int) $customer['id'], $data);
        $updated = $this->customers->findByUuid($tenant->tenantId, (string) $params['uuid']);

        return ApiResponse::ok(['customer' => $updated !== null ? $this->detail($tenant->tenantId, $updated, $membership) : null]);
    }

    /** @param array<string, string> $params */
    public function destroy(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('customers.delete')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to delete customers.', 403);
        }

        $customer = $this->customers->findByUuid($tenant->tenantId, (string) $params['uuid']);
        if ($customer === null || !$this->customers->visibleAtBranches($tenant->tenantId, (int) $customer['id'], $membership->branchIds)) {
            return ApiResponse::error('NOT_FOUND', 'Customer not found.', 404);
        }

        if ($this->customers->referenceCount($tenant->tenantId, (int) $customer['id']) > 0) {
            return ApiResponse::error(
                'CUSTOMER_IN_USE',
                'This customer has booking or payment history and cannot be deleted.',
                409,
            );
        }

        $this->customers->delete($tenant->tenantId, (int) $customer['id']);

        return ApiResponse::ok(['deleted' => true]);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed>|null $existing Existing row on update (for notes
     *     retention when the member lacks customers.view_notes).
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function validated(array $body, TenantContext $tenant, MembershipContext $membership, ?array $existing = null): array
    {
        $fields = [];

        $first = trim((string) ($body['first_name'] ?? ''));
        $last = trim((string) ($body['last_name'] ?? ''));
        if ($first === '' || mb_strlen($first) > 100) {
            $fields['first_name'] = 'First name is required (max 100 characters).';
        }
        if ($last === '' || mb_strlen($last) > 100) {
            $fields['last_name'] = 'Last name is required (max 100 characters).';
        }
        $middle = trim((string) ($body['middle_name'] ?? ''));
        if (mb_strlen($middle) > 100) {
            $fields['middle_name'] = 'Middle name is too long (max 100 characters).';
        }

        $email = trim((string) ($body['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $fields['email'] = 'Enter a valid email address.';
        }
        $phone = trim((string) ($body['phone_e164'] ?? ''));
        if ($phone !== '' && preg_match('/^\+[1-9]\d{6,14}$/', $phone) !== 1) {
            $fields['phone_e164'] = 'Use international format, e.g. +254712345678.';
        }
        if ($phone === '' && $email === '') {
            $fields['phone_e164'] = 'Provide a phone number or an email address.';
        }

        $preferredStaff = (int) ($body['preferred_staff_id'] ?? 0);
        if ($preferredStaff > 0 && !$this->customers->staffValid($tenant->tenantId, $preferredStaff)) {
            $fields['preferred_staff_id'] = 'Choose a valid staff member.';
        }
        $preferredService = (int) ($body['preferred_service_id'] ?? 0);
        if ($preferredService > 0 && !$this->customers->serviceValid($tenant->tenantId, $preferredService)) {
            $fields['preferred_service_id'] = 'Choose a valid service.';
        }

        // Notes are privileged: without customers.view_notes the submitted
        // value is ignored and the stored value is kept on update.
        $canNotes = $membership->can('customers.view_notes');
        $notes = $canNotes
            ? (trim((string) ($body['notes'] ?? '')) ?: null)
            : ($existing !== null ? $existing['notes'] : null);

        $data = [
            'first_name' => $first,
            'middle_name' => $middle !== '' ? $middle : null,
            'last_name' => $last,
            'phone_e164' => $phone !== '' ? $phone : null,
            'email' => $email !== '' ? $email : null,
            'notes' => $notes,
            'preferred_staff_id' => $preferredStaff > 0 ? $preferredStaff : null,
            'preferred_service_id' => $preferredService > 0 ? $preferredService : null,
        ];

        return [$data, $fields];
    }

    /** @param array<string, mixed> $customer
     * @return array<string, mixed>
     */
    private function detail(int $tenantId, array $customer, MembershipContext $membership): array
    {
        $staffName = null;
        if ($customer['preferred_staff_id'] !== null) {
            foreach ($this->customers->staffOptions($tenantId) as $s) {
                if ((int) $s['id'] === (int) $customer['preferred_staff_id']) {
                    $staffName = $s['display_name'];
                    break;
                }
            }
        }
        $serviceName = null;
        if ($customer['preferred_service_id'] !== null) {
            foreach ($this->customers->serviceOptions($tenantId) as $s) {
                if ((int) $s['id'] === (int) $customer['preferred_service_id']) {
                    $serviceName = $s['name'];
                    break;
                }
            }
        }

        return array_merge($this->row($customer), [
            'first_name' => $customer['first_name'],
            'middle_name' => $customer['middle_name'],
            'last_name' => $customer['last_name'],
            'notes' => $membership->can('customers.view_notes') ? $customer['notes'] : null,
            'preferred_staff_id' => $customer['preferred_staff_id'] !== null ? (int) $customer['preferred_staff_id'] : null,
            'preferred_staff_name' => $staffName,
            'preferred_service_id' => $customer['preferred_service_id'] !== null ? (int) $customer['preferred_service_id'] : null,
            'preferred_service_name' => $serviceName,
            'appointments' => $this->customers->appointmentCount($tenantId, (int) $customer['id']),
            'references' => $this->customers->referenceCount($tenantId, (int) $customer['id']),
            'created_at' => $customer['created_at'],
        ]);
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function row(array $row): array
    {
        return [
            'uuid' => $row['uuid'],
            'name' => trim($row['first_name'] . ' ' . $row['last_name']),
            'phone' => $row['phone_e164'],
            'email' => $row['email'],
            'appointments' => (int) ($row['appointment_count'] ?? 0),
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
