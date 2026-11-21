<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
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

    /* ------------------------------------------------------------------ */

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
}
