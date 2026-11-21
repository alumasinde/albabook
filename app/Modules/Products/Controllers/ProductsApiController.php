<?php

declare(strict_types=1);

namespace App\Modules\Products\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Auth\Context\MembershipContext;
use App\Modules\Products\Repositories\ProductRepository;
use App\Shared\Http\ApiResponse;
use App\Shared\Support\UuidFactory;
use App\Tenancy\TenantContext;

/**
 * Private JSON API for retail products (session-authenticated, D-008).
 * Reads require services.view; mutations require services.manage —
 * products share the catalogue permission family.
 */
final class ProductsApiController
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly UuidFactory $uuids,
    ) {}

    /** @param array<string, string> $params */
    public function list(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('services.view')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to view products.', 403);
        }

        $rows = $this->products->listForTenant($tenant->tenantId, [
            'q' => trim((string) $request->query('q', '')),
            'status' => trim((string) $request->query('status', '')),
        ]);

        return ApiResponse::ok(['rows' => $rows, 'total' => count($rows)]);
    }

    /** @param array<string, string> $params */
    public function create(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('services.manage')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to manage products.', 403);
        }

        [$data, $fields] = $this->validated($request->body());
        if ($fields !== []) {
            return ApiResponse::error('VALIDATION', 'Fix the highlighted fields.', 422, $fields);
        }

        $data['uuid'] = $this->uuids->generate();
        try {
            $this->products->insert($tenant->tenantId, $data);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'uq_products_sku')) {
                return ApiResponse::error('VALIDATION', 'Fix the highlighted fields.', 422, [
                    'sku' => 'That SKU is already used by another product.',
                ]);
            }
            throw $e;
        }

        return $this->reload($tenant->tenantId, 201);
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('services.manage')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to manage products.', 403);
        }

        $product = $this->products->findByUuid($tenant->tenantId, (string) $params['uuid']);
        if ($product === null) {
            return ApiResponse::error('NOT_FOUND', 'Product not found.', 404);
        }

        [$data, $fields] = $this->validated($request->body());
        if ($fields !== []) {
            return ApiResponse::error('VALIDATION', 'Fix the highlighted fields.', 422, $fields);
        }

        try {
            $this->products->update($tenant->tenantId, (int) $product['id'], $data);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'uq_products_sku')) {
                return ApiResponse::error('VALIDATION', 'Fix the highlighted fields.', 422, [
                    'sku' => 'That SKU is already used by another product.',
                ]);
            }
            throw $e;
        }

        return $this->reload($tenant->tenantId);
    }

    /** @param array<string, string> $params */
    public function destroy(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('services.manage')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to manage products.', 403);
        }

        $product = $this->products->findByUuid($tenant->tenantId, (string) $params['uuid']);
        if ($product === null) {
            return ApiResponse::error('NOT_FOUND', 'Product not found.', 404);
        }
        if ($this->products->saleItemCount($tenant->tenantId, (int) $product['id']) > 0) {
            return ApiResponse::error(
                'PRODUCT_IN_USE',
                'This product appears on a sale ticket. Deactivate it instead.',
                409,
            );
        }

        $this->products->delete($tenant->tenantId, (int) $product['id']);

        return $this->reload($tenant->tenantId);
    }

    /* ------------------------------------------------------------------ */

    private function reload(int $tenantId, int $status = 200): Response
    {
        $rows = $this->products->listForTenant($tenantId, []);

        return ApiResponse::ok(['rows' => $rows, 'total' => count($rows)], $status);
    }

    /** @return array{TenantContext, MembershipContext} */
    private function context(Request $request): array
    {
        return [$request->attribute('tenant'), $request->attribute('membership')];
    }

    /** @param array<string, mixed> $body
     * @return array{array<string, mixed>, array<string, string>}
     */
    private function validated(array $body): array
    {
        $fields = [];

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150) {
            $fields['name'] = 'Name is required (max 150 characters).';
        }

        $sku = trim((string) ($body['sku'] ?? ''));
        if (mb_strlen($sku) > 60) {
            $fields['sku'] = 'SKU is too long (max 60 characters).';
        }

        $price = $body['price_minor'] ?? null;
        if (!is_numeric($price) || (int) $price < 0) {
            $fields['price_minor'] = 'Price must be zero or more.';
        }

        $tax = $body['tax_rate_bp'] ?? 0;
        if (!is_numeric($tax) || (int) $tax < 0 || (int) $tax > 10000) {
            $fields['tax_rate_bp'] = 'Tax rate must be between 0% and 100%.';
        }

        $currency = strtoupper(trim((string) ($body['currency'] ?? 'KES')));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $fields['currency'] = 'Use a 3-letter currency code, e.g. KES.';
        }

        $trackStock = array_key_exists('stock_quantity', $body) && $body['stock_quantity'] !== null && $body['stock_quantity'] !== '';
        $stock = null;
        if ($trackStock) {
            if (!is_numeric($body['stock_quantity'])) {
                $fields['stock_quantity'] = 'Stock must be a whole number.';
            } else {
                $stock = (int) $body['stock_quantity'];
            }
        }

        return [
            [
                'name' => $name,
                'sku' => $sku !== '' ? $sku : null,
                'price_minor' => is_numeric($price) ? (int) $price : 0,
                'tax_rate_bp' => is_numeric($tax) ? (int) $tax : 0,
                'currency' => $currency,
                'stock_quantity' => $stock,
                'is_active' => !empty($body['is_active']) ? 1 : 0,
            ],
            $fields,
        ];
    }
}
