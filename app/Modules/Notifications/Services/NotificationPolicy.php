<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Core\Config\Config;
use App\Core\Database\Database;
use App\Modules\Billing\Services\FeatureGate;

/**
 * Decides whether an automatic customer notification may be queued:
 *   - the tenant has not switched this channel × event off in Settings
 *     (notification_preferences; a missing row means enabled, matching the
 *     settings screen), and
 *   - for sms/whatsapp, the tenant has an active provider account — without
 *     one the row could only fail and clutter the retry list.
 *
 * Checked when a row is queued (bookings, transitions, till receipts) and
 * again when a reminder is materialised, so a later Settings change is
 * honoured for reminders that were armed earlier.
 */
final class NotificationPolicy
{
    /** @var array<string, bool> per-request memo: "tenant|channel|event" */
    private array $memo = [];

    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly ?FeatureGate $features = null,
    ) {}

    public function allows(int $tenantId, string $channel, string $eventKey): bool
    {
        $key = $tenantId . '|' . $channel . '|' . $eventKey;

        return $this->memo[$key] ??= $this->preferenceEnabled($tenantId, $channel, $eventKey)
            && $this->channelAvailable($tenantId, $channel);
    }

    private function preferenceEnabled(int $tenantId, string $channel, string $eventKey): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT is_enabled FROM notification_preferences
              WHERE tenant_id = :t AND channel = :c AND event_key = :e',
        );
        $stmt->execute(['t' => $tenantId, 'c' => $channel, 'e' => $eventKey]);
        $value = $stmt->fetchColumn();

        return $value === false || (bool) $value;
    }

    private function channelAvailable(int $tenantId, string $channel): bool
    {
        if ($channel === 'email') {
            return true; // platform mailer; no tenant account needed
        }

        // The tenant's own provider account: they pay their provider.
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM tenant_notification_accounts a
               JOIN notification_providers p ON p.id = a.provider_id
              WHERE a.tenant_id = :t AND a.status = 'active' AND p.channel = :c AND p.is_active = 1
              LIMIT 1",
        );
        $stmt->execute(['t' => $tenantId, 'c' => $channel]);
        if ($stmt->fetchColumn() !== false) {
            return true;
        }

        // Platform-owned fallback (config/notifications.php): the platform
        // pays, so it is only available when the plan includes the channel
        // ({channel}_messages enabled). Plans without it never queue rows
        // that would send on the platform's account.
        if ($this->config->get('notifications.platform.' . $channel . '.provider', '') === '') {
            return false;
        }
        $features = $this->features ?? new FeatureGate($this->db);

        return $features->underLimit($tenantId, $channel . '_messages', 0);
    }
}
