<?php

declare(strict_types=1);

namespace App\Modules\Services\Controllers;

use App\Core\Database\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Auth\Context\MembershipContext;
use App\Modules\Services\Repositories\ServiceRepository;
use App\Shared\Http\ApiResponse;
use App\Shared\Money\CurrencyFormatter;
use App\Shared\Money\Money;
use App\Shared\Support\UuidFactory;
use App\Tenancy\TenantContext;

/**
 * Private JSON API for the services catalogue (session-authenticated,
 * D-008). View endpoints require services.view; mutations require
 * services.manage. Currency always comes from the tenant, never the client.
 */
final class ServicesApiController
{
    private const PER_PAGE = 20;
    private const PRICE_TYPES = ['fixed', 'from', 'range'];

    public function __construct(
        private readonly ServiceRepository $services,
        private readonly Database $db,
        private readonly CurrencyFormatter $currency,
        private readonly UuidFactory $uuids,
    ) {}

    /** @param array<string, string> $params */
    public function list(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('services.view')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to view services.', 403);
        }

        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => (string) $request->query('status', ''),
            'category' => (int) $request->query('category', 0),
            'branch' => (int) $request->query('branch', 0),
        ];

        $page = max(1, (int) $request->query('page', 1));
        $result = $this->services->listForTenant($tenant->tenantId, $filters, $page, self::PER_PAGE);
        $pages = max(1, (int) ceil($result['total'] / self::PER_PAGE));

        return ApiResponse::ok([
            'rows' => array_map(fn(array $row): array => $this->row($row), $result['rows']),
            'total' => $result['total'],
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    /** Categories + branches for filter dropdowns and the service form.
     * @param array<string, string> $params
     */
    public function meta(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('services.view')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to view services.', 403);
        }

        $branches = $this->services->branches($tenant->tenantId);
        if ($membership->branchIds !== null) {
            $branches = array_values(array_filter(
                $branches,
                static fn($b): bool => in_array((int) $b['id'], $membership->branchIds, true),
            ));
        }

        return ApiResponse::ok([
            'categories' => $this->services->categories($tenant->tenantId),
            'branches' => $branches,
            'resource_types' => $this->services->resourceTypes($tenant->tenantId),
        ]);
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('services.view')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to view services.', 403);
        }

        $service = $this->services->findByUuid($tenant->tenantId, (string) $params['uuid']);
        if ($service === null) {
            return ApiResponse::error('NOT_FOUND', 'Service not found.', 404);
        }

        return ApiResponse::ok([
            'service' => $this->row($service) + [
                'description' => $service['description'],
                'buffer_before_minutes' => (int) $service['buffer_before_minutes'],
                'buffer_after_minutes' => (int) $service['buffer_after_minutes'],
                'price_amount_minor' => $service['price_amount_minor'] !== null ? (int) $service['price_amount_minor'] : null,
                'min_amount_minor' => $service['min_amount_minor'] !== null ? (int) $service['min_amount_minor'] : null,
                'max_amount_minor' => $service['max_amount_minor'] !== null ? (int) $service['max_amount_minor'] : null,
                'category_id' => $service['service_category_id'] !== null ? (int) $service['service_category_id'] : null,
                'branch_ids' => $this->services->branchIds($tenant->tenantId, (int) $service['id']),
                'required_resource_type_id' => $this->services->requiredResourceType($tenant->tenantId, (int) $service['id']),
                'appointments' => $this->services->appointmentCount($tenant->tenantId, (int) $service['id']),
            ],
        ]);
    }

    /** @param array<string, string> $params */
    public function create(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('services.manage')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to manage services.', 403);
        }

        [$data, $branchIds, $fields] = $this->validated($request->body(), $tenant, $membership);
        if ($fields !== []) {
            return ApiResponse::error('VALIDATION', 'Fix the highlighted fields.', 422, $fields);
        }

        $uuid = $this->uuids->generate();
        $resourceTypeId = $data['required_resource_type_id'];
        unset($data['required_resource_type_id']);
        $this->db->transaction(function () use ($tenant, $data, $branchIds, $uuid, $resourceTypeId): void {
            $id = $this->services->insert($data + [
                'uuid' => $uuid,
                'tenant_id' => $tenant->tenantId,
            ]);
            $this->services->syncBranches($tenant->tenantId, $id, $branchIds);
            $this->services->syncResourceRequirement($tenant->tenantId, $id, $resourceTypeId);
        });

        $created = $this->services->findByUuid($tenant->tenantId, $uuid);

        return ApiResponse::ok(['service' => $created !== null ? $this->row($created) : null], 201);
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('services.manage')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to manage services.', 403);
        }

        $service = $this->services->findByUuid($tenant->tenantId, (string) $params['uuid']);
        if ($service === null) {
            return ApiResponse::error('NOT_FOUND', 'Service not found.', 404);
        }

        [$data, $branchIds, $fields] = $this->validated($request->body(), $tenant, $membership);
        if ($fields !== []) {
            return ApiResponse::error('VALIDATION', 'Fix the highlighted fields.', 422, $fields);
        }

        $resourceTypeId = $data['required_resource_type_id'];
        unset($data['required_resource_type_id']);
        $this->db->transaction(function () use ($tenant, $service, $data, $branchIds, $resourceTypeId): void {
            $this->services->update($tenant->tenantId, (int) $service['id'], $data);
            $this->services->syncBranches($tenant->tenantId, (int) $service['id'], $branchIds);
            $this->services->syncResourceRequirement($tenant->tenantId, (int) $service['id'], $resourceTypeId);
        });

        $updated = $this->services->findByUuid($tenant->tenantId, (string) $params['uuid']);

        return ApiResponse::ok(['service' => $updated !== null ? $this->row($updated) : null]);
    }

    /** @param array<string, string> $params */
    public function destroy(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('services.manage')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to manage services.', 403);
        }

        $service = $this->services->findByUuid($tenant->tenantId, (string) $params['uuid']);
        if ($service === null) {
            return ApiResponse::error('NOT_FOUND', 'Service not found.', 404);
        }

        if ($this->services->referenceCount($tenant->tenantId, (int) $service['id']) > 0) {
            return ApiResponse::error(
                'SERVICE_IN_USE',
                'This service has appointments. Deactivate it instead of deleting.',
                409,
            );
        }

        $this->services->delete($tenant->tenantId, (int) $service['id']);

        return ApiResponse::ok(['deleted' => true]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: array<string, mixed>, 1: list<int>, 2: array<string, string>}
     */
    private function validated(array $body, TenantContext $tenant, MembershipContext $membership): array
    {
        $fields = [];

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150) {
            $fields['name'] = 'Give the service a name (max 150 characters).';
        }

        $duration = (int) ($body['duration_minutes'] ?? 0);
        if ($duration < 5 || $duration > 600) {
            $fields['duration_minutes'] = 'Duration must be between 5 and 600 minutes.';
        }

        $bufferBefore = max(0, (int) ($body['buffer_before_minutes'] ?? 0));
        $bufferAfter = max(0, (int) ($body['buffer_after_minutes'] ?? 0));

        $priceType = (string) ($body['price_type'] ?? 'fixed');
        if (!in_array($priceType, self::PRICE_TYPES, true)) {
            $fields['price_type'] = 'Choose a valid price type.';
            $priceType = 'fixed';
        }

        $toMinor = static function (mixed $v): ?int {
            if ($v === null || $v === '') {
                return null;
            }
            $amount = (float) $v;

            return $amount > 0 ? (int) round($amount * 100) : null;
        };
        $price = $toMinor($body['price'] ?? null);
        $min = $toMinor($body['min_price'] ?? null);
        $max = $toMinor($body['max_price'] ?? null);

        if ($priceType === 'fixed' && $price === null) {
            $fields['price'] = 'Set a price for the service.';
        }
        if ($priceType === 'from' && $min === null) {
            $fields['min_price'] = 'Set a starting price.';
        }
        if ($priceType === 'range') {
            if ($min === null || $max === null || $min >= $max) {
                $fields['min_price'] = 'Set a valid price range (min below max).';
            }
        }

        $categoryId = (int) ($body['category_id'] ?? 0);
        if ($categoryId > 0) {
            $categories = array_column($this->services->categories($tenant->tenantId), 'id');
            if (!in_array($categoryId, array_map('intval', $categories), true)) {
                $fields['category_id'] = 'Choose a valid category.';
            }
        }

        $branchIds = array_values(array_unique(array_map('intval', (array) ($body['branch_ids'] ?? []))));
        $allowed = array_map(
            static fn($b): int => (int) $b['id'],
            $this->services->branches($tenant->tenantId),
        );
        if ($membership->branchIds !== null) {
            $allowed = array_values(array_intersect($allowed, $membership->branchIds));
        }
        if ($branchIds === [] || array_diff($branchIds, $allowed) !== []) {
            $fields['branch_ids'] = 'Offer the service at least one branch you manage.';
        }

        $resourceTypeId = (int) ($body['required_resource_type_id'] ?? 0);
        if ($resourceTypeId > 0) {
            $typeIds = array_map(
                static fn($t): int => (int) $t['id'],
                $this->services->resourceTypes($tenant->tenantId),
            );
            if (!in_array($resourceTypeId, $typeIds, true)) {
                $fields['required_resource_type_id'] = 'Choose a valid resource type.';
            }
        }

        $data = [
            'service_category_id' => $categoryId > 0 ? $categoryId : null,
            'name' => $name,
            'description' => trim((string) ($body['description'] ?? '')) ?: null,
            'duration_minutes' => $duration,
            'buffer_before_minutes' => $bufferBefore,
            'buffer_after_minutes' => $bufferAfter,
            'price_type' => $priceType,
            'price_amount_minor' => $priceType === 'fixed' ? $price : null,
            'min_amount_minor' => in_array($priceType, ['from', 'range'], true) ? $min : null,
            'max_amount_minor' => $priceType === 'range' ? $max : null,
            'currency' => $tenant->currency,
            'is_active' => !empty($body['is_active']) ? 1 : 0,
            'is_bookable_online' => !empty($body['is_bookable_online']) ? 1 : 0,
            'sort_position' => max(0, (int) ($body['sort_position'] ?? 0)),
            'required_resource_type_id' => $resourceTypeId > 0 ? $resourceTypeId : null,
        ];

        return [$data, $branchIds, $fields];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function row(array $row): array
    {
        return [
            'uuid' => $row['uuid'],
            'name' => $row['name'],
            'category' => $row['category_name'],
            'duration_minutes' => (int) $row['duration_minutes'],
            'duration_label' => $this->duration((int) $row['duration_minutes']),
            'price_type' => $row['price_type'],
            'price_label' => $this->price($row),
            'is_active' => (bool) $row['is_active'],
            'is_bookable_online' => (bool) $row['is_bookable_online'],
            'sort_position' => (int) $row['sort_position'],
        ];
    }

    /** @param array<string, mixed> $row */
    private function price(array $row): string
    {
        $currency = (string) $row['currency'];

        return match ($row['price_type']) {
            'fixed' => $row['price_amount_minor'] !== null
                ? $this->money((int) $row['price_amount_minor'], $currency)
                : '—',
            'from' => $row['min_amount_minor'] !== null
                ? 'From ' . $this->money((int) $row['min_amount_minor'], $currency)
                : '—',
            'range' => $row['min_amount_minor'] !== null && $row['max_amount_minor'] !== null
                ? $this->money((int) $row['min_amount_minor'], $currency)
                    . ' – ' . $this->money((int) $row['max_amount_minor'], $currency)
                : '—',
            default => '—',
        };
    }

    private function duration(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $h > 0 ? ($m > 0 ? "{$h}h {$m}m" : "{$h}h") : "{$m}m";
    }

    private function money(int $minor, string $currency): string
    {
        return $this->currency->format(new Money($minor, $currency));
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
