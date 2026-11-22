<?php

declare(strict_types=1);

namespace App\Modules\Api\Middleware;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;
use App\Modules\Api\Repositories\ApiCredentialRepository;

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
    public function __construct(private readonly ApiCredentialRepository $credentials) {}

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

        $this->credentials->touchLastUsed((int) $credential['id']);

        $request = $request
            ->withAttribute('api_tenant_id', (int) $credential['tenant_id'])
            ->withAttribute('api_credential_id', (int) $credential['id'])
            ->withAttribute('api_scopes', $scopes);

        return $next($request);
    }
}
