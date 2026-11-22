<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Api\Repositories\ApiCredentialRepository;
use App\Modules\Auth\Context\MembershipContext;
use App\Modules\Integrations\Repositories\IntegrationRepository;
use App\Shared\Http\ApiResponse;
use App\Shared\Security\Encrypter;
use App\Shared\Support\UuidFactory;
use App\Tenancy\TenantContext;

/**
 * Private JSON API for webhook integrations (session-authenticated,
 * D-008). Reads require integrations.view; mutations require
 * integrations.manage. Signing secrets are generated server-side,
 * stored encrypted and returned exactly once on creation.
 */
final class IntegrationsApiController
{
    /** Scopes a private API credential can carry (D-008, plan.md §12.4). */
    private const CREDENTIAL_SCOPES = [
        'appointments:read',
        'appointments:write',
        'services:read',
        'staff:read',
        'customers:read',
        'reports:read',
    ];

    /** Domain events a webhook can subscribe to. */
    private const EVENT_TYPES = [
        'appointment.created',
        'appointment.confirmed',
        'appointment.cancelled',
        'appointment.completed',
        'payment.received',
        'payment.refunded',
        'customer.created',
        'booking.received',
    ];

    public function __construct(
        private readonly IntegrationRepository $integrations,
        private readonly ApiCredentialRepository $credentials,
        private readonly UuidFactory $uuids,
        private readonly Encrypter $encrypter,
    ) {}

    /** @param array<string, string> $params */
    public function list(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('integrations.view')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to view integrations.', 403);
        }

        return ApiResponse::ok([
            'endpoints' => array_map(
                fn(array $row): array => $this->shape($row),
                $this->integrations->endpoints($tenant->tenantId),
            ),
            'deliveries' => $this->integrations->deliveries($tenant->tenantId),
            'event_types' => self::EVENT_TYPES,
            'credentials' => array_map(
                fn(array $row): array => $this->credentialShape($row),
                $this->credentials->listForTenant($tenant->tenantId),
            ),
            'credential_scopes' => self::CREDENTIAL_SCOPES,
        ]);
    }

    /** @param array<string, string> $params */
    public function create(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('integrations.manage')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to manage integrations.', 403);
        }

        $body = $request->body();
        $fields = [];

        $url = trim((string) ($body['url'] ?? ''));
        if (filter_var($url, FILTER_VALIDATE_URL) === false || !str_starts_with($url, 'https://') || strlen($url) > 500) {
            $fields['url'] = 'Enter a valid https:// endpoint URL.';
        }

        [$events, $eventError] = $this->validEvents($body['event_types'] ?? null);
        if ($eventError !== null) {
            $fields['event_types'] = $eventError;
        }

        if ($fields !== []) {
            return ApiResponse::error('VALIDATION', 'Fix the highlighted fields.', 422, $fields);
        }

        $secret = bin2hex(random_bytes(24));
        $this->integrations->insertEndpoint($tenant->tenantId, [
            'uuid' => $this->uuids->generate(),
            'url' => $url,
            'event_types' => json_encode($events, JSON_THROW_ON_ERROR),
            'secret_encrypted' => $this->encrypter->encrypt($secret),
        ]);

        return ApiResponse::ok([
            'secret' => $secret, // shown once — never returned again
            'endpoints' => array_map(
                fn(array $row): array => $this->shape($row),
                $this->integrations->endpoints($tenant->tenantId),
            ),
        ], 201);
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('integrations.manage')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to manage integrations.', 403);
        }

        $endpoint = $this->integrations->findEndpoint($tenant->tenantId, (string) $params['uuid']);
        if ($endpoint === null) {
            return ApiResponse::error('NOT_FOUND', 'Endpoint not found.', 404);
        }

        $body = $request->body();
        $data = [];
        $fields = [];

        if (array_key_exists('url', $body)) {
            $url = trim((string) $body['url']);
            if (filter_var($url, FILTER_VALIDATE_URL) === false || !str_starts_with($url, 'https://') || strlen($url) > 500) {
                $fields['url'] = 'Enter a valid https:// endpoint URL.';
            } else {
                $data['url'] = $url;
            }
        }
        if (array_key_exists('event_types', $body)) {
            [$events, $eventError] = $this->validEvents($body['event_types']);
            if ($eventError !== null) {
                $fields['event_types'] = $eventError;
            } else {
                $data['event_types'] = json_encode($events, JSON_THROW_ON_ERROR);
            }
        }
        if (array_key_exists('is_active', $body)) {
            $data['is_active'] = !empty($body['is_active']) ? 1 : 0;
        }

        if ($fields !== []) {
            return ApiResponse::error('VALIDATION', 'Fix the highlighted fields.', 422, $fields);
        }

        $this->integrations->updateEndpoint($tenant->tenantId, (int) $endpoint['id'], $data);

        return ApiResponse::ok(['saved' => true]);
    }

    /** @param array<string, string> $params */
    public function destroy(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('integrations.manage')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to manage integrations.', 403);
        }

        $endpoint = $this->integrations->findEndpoint($tenant->tenantId, (string) $params['uuid']);
        if ($endpoint === null) {
            return ApiResponse::error('NOT_FOUND', 'Endpoint not found.', 404);
        }

        $this->integrations->deleteEndpoint($tenant->tenantId, (int) $endpoint['id']);

        return ApiResponse::ok(['saved' => true]);
    }

    /**
     * Create a private API credential. The full bearer token is returned
     * exactly once; only its SHA-256 hash is stored (plan.md §12.4).
     * @param array<string, string> $params
     */
    public function createCredential(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('integrations.manage')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to manage integrations.', 403);
        }

        $body = $request->body();
        [$scopes, $scopeError] = $this->validScopes($body['scopes'] ?? null);
        $fields = [];
        if ($scopeError !== null) {
            $fields['scopes'] = $scopeError;
        }
        $expiresAt = null;
        if (isset($body['expires_at']) && trim((string) $body['expires_at']) !== '') {
            $expiresAt = trim((string) $body['expires_at']);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresAt) !== 1) {
                $fields['expires_at'] = 'Use Y-m-d format.';
                $expiresAt = null;
            }
        }
        if ($fields !== []) {
            return ApiResponse::error('VALIDATION', 'Fix the highlighted fields.', 422, $fields);
        }

        [$token, $publicId, $secretHash] = self::mintToken();
        $this->credentials->insert([
            'uuid' => $this->uuids->generate(),
            'tenant_id' => $tenant->tenantId,
            'public_id' => $publicId,
            'secret_hash' => $secretHash,
            'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
            'expires_at' => $expiresAt !== null ? $expiresAt . ' 00:00:00' : null,
            'created_by_user_id' => $membership->actorUserId(),
        ]);

        return ApiResponse::ok([
            'token' => $token, // shown once — never returned again
            'credentials' => array_map(
                fn(array $row): array => $this->credentialShape($row),
                $this->credentials->listForTenant($tenant->tenantId),
            ),
        ], 201);
    }

    /**
     * Rotate a credential's key material in place — the old token dies
     * immediately, the new one is returned exactly once.
     * @param array<string, string> $params
     */
    public function rotateCredential(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('integrations.manage')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to manage integrations.', 403);
        }

        $credential = $this->credentials->findByUuid($tenant->tenantId, (string) $params['uuid']);
        if ($credential === null) {
            return ApiResponse::error('NOT_FOUND', 'Credential not found.', 404);
        }

        [$token, $publicId, $secretHash] = self::mintToken();
        $this->credentials->updateSecret($tenant->tenantId, (int) $credential['id'], $publicId, $secretHash);

        return ApiResponse::ok([
            'token' => $token, // shown once — never returned again
            'credentials' => array_map(
                fn(array $row): array => $this->credentialShape($row),
                $this->credentials->listForTenant($tenant->tenantId),
            ),
        ]);
    }

    /** @param array<string, string> $params */
    public function destroyCredential(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('integrations.manage')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to manage integrations.', 403);
        }

        $credential = $this->credentials->findByUuid($tenant->tenantId, (string) $params['uuid']);
        if ($credential === null) {
            return ApiResponse::error('NOT_FOUND', 'Credential not found.', 404);
        }

        $this->credentials->revoke($tenant->tenantId, (string) $credential['uuid']);

        return ApiResponse::ok(['saved' => true]);
    }

    /* ------------------------------------------------------------------ */

    /**
     * New "{public_id}.{secret}" bearer token. public_id is 32 hex chars to
     * match api_credentials.public_id; the secret is 48 hex chars.
     * @return array{0: string, 1: string, 2: string}
     */
    private static function mintToken(): array
    {
        $publicId = bin2hex(random_bytes(16));
        $secret = bin2hex(random_bytes(24));

        return [$publicId . '.' . $secret, $publicId, hash('sha256', $secret)];
    }

    /** @return array{TenantContext, MembershipContext} */
    private function context(Request $request): array
    {
        return [$request->attribute('tenant'), $request->attribute('membership')];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shape(array $row): array
    {
        $events = json_decode((string) ($row['event_types'] ?? '[]'), true);

        return [
            'uuid' => $row['uuid'],
            'url' => $row['url'],
            'event_types' => is_array($events) ? $events : [],
            'is_active' => (bool) $row['is_active'],
            'last_success_at' => $row['last_success_at'],
            'last_failure_at' => $row['last_failure_at'],
        ];
    }

    /** @return array{list<string>, string|null} */
    private function validEvents(mixed $value): array
    {
        if (!is_array($value)) {
            return [[], 'Choose at least one event.'];
        }
        $events = array_values(array_unique(array_filter(array_map(
            static fn($v): string => is_string($v) ? trim($v) : '',
            $value,
        ))));
        $invalid = array_diff($events, self::EVENT_TYPES);
        if ($events === [] || $invalid !== []) {
            return [[], 'Choose at least one valid event.'];
        }

        return [$events, null];
    }

    /** @return array{list<string>, string|null} */
    private function validScopes(mixed $value): array
    {
        if (!is_array($value)) {
            return [[], 'Choose at least one scope.'];
        }
        $scopes = array_values(array_unique(array_filter(array_map(
            static fn($v): string => is_string($v) ? trim($v) : '',
            $value,
        ))));
        $invalid = array_diff($scopes, self::CREDENTIAL_SCOPES);
        if ($scopes === [] || $invalid !== []) {
            return [[], 'Choose at least one valid scope.'];
        }

        return [$scopes, null];
    }

    /**
     * Credential row for API output — public id and metadata only, never
     * the secret hash.
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function credentialShape(array $row): array
    {
        $scopes = json_decode((string) ($row['scopes'] ?? '[]'), true);
        $expired = $row['expires_at'] !== null && (string) $row['expires_at'] <= gmdate('Y-m-d H:i:s');

        return [
            'uuid' => $row['uuid'],
            'public_id' => $row['public_id'],
            'scopes' => is_array($scopes) ? $scopes : [],
            'expires_at' => $row['expires_at'],
            'revoked' => $row['revoked_at'] !== null,
            'expired' => $expired,
            'last_used_at' => $row['last_used_at'],
            'created_at' => $row['created_at'],
        ];
    }
}
