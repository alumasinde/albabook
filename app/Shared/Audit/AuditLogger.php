<?php

declare(strict_types=1);

namespace App\Shared\Audit;

use App\Core\Database\Database;
use App\Core\Http\RequestContext;
use App\Shared\Time\Clock;

/**
 * Inserts into the append-only audit_logs table. Personal data and secrets in
 * before/after values are masked here so callers cannot leak them by accident
 * (decisions.md D-012).
 */
final class AuditLogger
{
    private const REDACTED = '[redacted]';
    private const SENSITIVE_KEY = '/(pass|secret|token|authorization|cookie|email|phone|card|kra_pin|pin)/i';

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly RequestContext $context,
    ) {}

    public function record(AuditEntry $entry): void
    {
        $request = $entry->request;
        $requestId = $this->context->requestId;
        if ($requestId !== null && preg_match('/^[0-9a-f-]{36}$/i', $requestId) !== 1) {
            $requestId = null;
        }

        $this->db->pdo()->prepare(
            'INSERT INTO audit_logs
                (tenant_id, actor_type, actor_id, action, target_type, target_id,
                 before_values, after_values, ip_address, user_agent, request_id, created_at)
             VALUES
                (:tenant_id, :actor_type, :actor_id, :action, :target_type, :target_id,
                 :before_values, :after_values, :ip_address, :user_agent, :request_id, :created_at)',
        )->execute([
            'tenant_id' => $entry->tenantId,
            'actor_type' => $entry->actorType,
            'actor_id' => $entry->actorId,
            'action' => mb_substr($entry->action, 0, 100),
            'target_type' => $entry->targetType,
            'target_id' => $entry->targetId === null ? null : mb_substr($entry->targetId, 0, 64),
            'before_values' => $entry->before === [] ? null : json_encode($this->mask($entry->before), JSON_THROW_ON_ERROR),
            'after_values' => $entry->after === [] ? null : json_encode($this->mask($entry->after), JSON_THROW_ON_ERROR),
            'ip_address' => $request !== null && $request->clientIp() !== '' ? $request->clientIp() : null,
            'user_agent' => $request === null ? null : mb_substr((string) $request->header('user-agent', ''), 0, 255),
            'request_id' => $requestId,
            'created_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param array<int|string, mixed> $values
     * @return array<int|string, mixed>
     */
    private function mask(array $values, int $depth = 0): array
    {
        if ($depth > 4) {
            return [self::REDACTED];
        }

        $masked = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key) === 1) {
                $masked[$key] = self::REDACTED;
                continue;
            }
            if (is_array($value)) {
                $masked[$key] = $this->mask($value, $depth + 1);
                continue;
            }
            if (is_string($value) && mb_strlen($value) > 512) {
                $masked[$key] = mb_substr($value, 0, 512) . '…';
                continue;
            }
            $masked[$key] = $value;
        }

        return $masked;
    }
}
