<?php

declare(strict_types=1);

namespace App\Modules\Api\Middleware;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;
use App\Modules\Api\Repositories\ApiCredentialRepository;
use App\Modules\Billing\Services\FeatureGate;
use App\Tenancy\TenantAccessChecker;
use App\Tenancy\TenantRepository;

/**
 * Private API authentication (decisions.md D-008): every /v1/* route carries
 * this middleware; the required scope is declared in the route's `options`
 * (`['scope' => 'appointments:read']`).
 *
 * Bearer format: "{public_id}.{secret}" — the public id locates the row,
 * the secret verifies against its stored SHA-256 in constant time. Revoked,
 * expired, unknown and malformed credentials all answer 401; a valid
 * credential without the route's scope answers 403. On success the request
 * gains `api_tenant_id`, `api_credential_id` and `api_scopes` attributes.
 */
final class ApiCredentialMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ApiCredentialRepository $credentials,
        private readonly TenantRepository $tenants,
        private readonly TenantAccessChecker $access,
        private readonly FeatureGate $features,
    ) {}

    public function process(Request $request, callable $next): Response
    {
        $header = trim((string) ($request->header('Authorization') ?? ''));
        if (!str_starts_with($header, 'Bearer ')) {
            throw new HttpException(401, 'UNAUTHENTICATED', 'A Bearer API credential is required.');
        }
        $token = substr($header, 7);
        $dot = strpos($token, '.');
        if ($dot === false) {
            throw new HttpException(401, 'UNAUTHENTICATED', 'Malformed API credential.');
        }
        $publicId = substr($token, 0, $dot);
        $secret = substr($token, $dot + 1);

        $credential = strlen($publicId) === 32 && ctype_xdigit($publicId)
            ? $this->credentials->findByPublicId($publicId)
            : null;
        if ($credential === null
            || $credential['revoked_at'] !== null
            || ($credential['expires_at'] !== null && (string) $credential['expires_at'] <= gmdate('Y-m-d H:i:s'))
            || !hash_equals((string) $credential['secret_hash'], hash('sha256', $secret))) {
            throw new HttpException(401, 'UNAUTHENTICATED', 'Invalid or expired API credential.');
        }

        $scopes = json_decode((string) $credential['scopes'], true);
        $scopes = is_array($scopes) ? array_values(array_map('strval', $scopes)) : [];
        $options = $request->attribute('route_options', []);
        $required = is_array($options) ? ($options['scope'] ?? null) : null;
        if (is_string($required) && $required !== '' && !in_array($required, $scopes, true) && !in_array('*', $scopes, true)) {
            throw new HttpException(403, 'FORBIDDEN', 'This API credential does not have that scope.');
        }

        // The key's tenant must still be entitled to use it: the plan must
        // include API access, and the account status must allow the
        // operation (reads need dashboard.view, writes booking.create —
        // the same tenant_status_policies matrix the dashboard honours).
        // Suspended, archived or downgraded businesses lose API access
        // immediately, without anyone revoking keys.
        $tenantId = (int) $credential['tenant_id'];
        $tenant = $this->tenants->findById($tenantId);
        if ($tenant === null) {
            throw new HttpException(401, 'UNAUTHENTICATED', 'Invalid or expired API credential.');
        }
        if (!$this->features->enabled($tenantId, 'api_access')) {
            throw new HttpException(403, 'FEATURE_NOT_AVAILABLE', 'API access is not included in this business’s plan.');
        }
        $operation = $request->isMethodSafe() ? 'dashboard.view' : 'booking.create';
        $allowed = $this->access->allowedOperations((string) $tenant['status']);
        if (!in_array('*', $allowed, true) && !in_array($operation, $allowed, true)) {
            throw new HttpException(403, 'TENANT_INACTIVE', 'This business account cannot use the API in its current status.');
        }

        $this->credentials->touchLastUsed((int) $credential['id']);

        $request = $request
            ->withAttribute('api_tenant_id', (int) $credential['tenant_id'])
            ->withAttribute('api_credential_id', (int) $credential['id'])
            ->withAttribute('api_scopes', $scopes);

        return $next($request);
    }
}
