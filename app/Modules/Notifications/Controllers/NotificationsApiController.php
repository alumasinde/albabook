<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Auth\Context\MembershipContext;
use App\Modules\Notifications\Repositories\NotificationRepository;
use App\Shared\Http\ApiResponse;
use App\Tenancy\TenantContext;

/**
 * Private JSON API for the notifications center (session-authenticated,
 * D-008). Reads require notifications.view; retry/cancel require
 * notifications.manage.
 */
final class NotificationsApiController
{
    private const STATUSES = ['pending', 'processing', 'sent', 'failed', 'cancelled'];

    public function __construct(private readonly NotificationRepository $notifications) {}

    /** @param array<string, string> $params */
    public function list(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('notifications.view')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to view notifications.', 403);
        }

        $result = $this->notifications->listForTenant($tenant->tenantId, [
            'q' => trim((string) $request->query('q', '')),
            'channel' => trim((string) $request->query('channel', '')),
            'status' => trim((string) $request->query('status', '')),
            'page' => (int) $request->query('page', 1),
        ]);

        return ApiResponse::ok([
            'rows' => array_map(fn(array $row): array => $this->shape($row), $result['rows']),
            'total' => $result['total'],
            'stats' => $this->notifications->stats($tenant->tenantId),
            'channels' => $this->notifications->channels(),
            'statuses' => self::STATUSES,
        ]);
    }

    /** Requeue a failed notification.
     * @param array<string, string> $params
     */
    public function retry(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('notifications.manage')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to manage notifications.', 403);
        }

        $row = $this->notifications->findByUuid($tenant->tenantId, (string) $params['uuid']);
        if ($row === null) {
            return ApiResponse::error('NOT_FOUND', 'Notification not found.', 404);
        }
        if ($row['status'] !== 'failed') {
            return ApiResponse::error('NOT_RETRYABLE', 'Only failed notifications can be retried.', 409);
        }

        $this->notifications->requeue($tenant->tenantId, (int) $row['id']);

        return ApiResponse::ok(['saved' => true]);
    }

    /** Cancel a queued notification.
     * @param array<string, string> $params
     */
    public function cancel(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('notifications.manage')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to manage notifications.', 403);
        }

        $row = $this->notifications->findByUuid($tenant->tenantId, (string) $params['uuid']);
        if ($row === null) {
            return ApiResponse::error('NOT_FOUND', 'Notification not found.', 404);
        }
        if (!in_array($row['status'], ['pending', 'processing'], true)) {
            return ApiResponse::error('NOT_CANCELLABLE', 'Only queued notifications can be cancelled.', 409);
        }

        $this->notifications->cancel($tenant->tenantId, (int) $row['id']);

        return ApiResponse::ok(['saved' => true]);
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
        $payload = json_decode((string) ($row['payload'] ?? '{}'), true);
        $event = is_array($payload) ? (string) ($payload['event'] ?? $payload['event_key'] ?? '') : '';

        return [
            'uuid' => $row['uuid'],
            'channel' => $row['channel'],
            'recipient' => $this->mask((string) $row['recipient']),
            'event' => $event,
            'status' => $row['status'],
            'attempts' => (int) $row['attempts'],
            'scheduled_at' => $row['scheduled_at'],
            'sent_at' => $row['sent_at'],
            'last_error' => $row['last_error'],
        ];
    }

    /** Mask recipients in the list view (privacy — logs already do this). */
    private function mask(string $recipient): string
    {
        if (str_contains($recipient, '@')) {
            [$local, $domain] = explode('@', $recipient, 2);

            return mb_substr($local, 0, 2) . '***@' . $domain;
        }
        $len = mb_strlen($recipient);

        return $len > 4 ? str_repeat('*', $len - 4) . mb_substr($recipient, -4) : '****';
    }
}
