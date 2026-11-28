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

    /**
     * Channels used for a customer's PHONE number, per the business's
     * WhatsApp setting (preferences.whatsapp.customer_mode):
     *   off (default)   → SMS only
     *   instead_of_sms  → WhatsApp only
     *   alongside_sms   → both
     * Each channel is still subject to allows() (preference + availability).
     * @return list<string>
     */
    public function phoneChannels(int $tenantId): array
    {
        return match ($this->whatsappSetting($tenantId, 'customer_mode')) {
            'instead_of_sms' => ['whatsapp'],
            'alongside_sms' => ['sms', 'whatsapp'],
            default => ['sms'],
        };
    }

    /**
     * Optional phone that receives business alerts by SMS
     * (preferences.business_alerts.phone, E.164). Null when not set.
     */
    public function businessAlertPhone(int $tenantId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT JSON_UNQUOTE(JSON_EXTRACT(COALESCE(preferences, JSON_OBJECT()), '$.business_alerts.phone'))
               FROM tenant_settings WHERE tenant_id = :t",
        );
        $stmt->execute(['t' => $tenantId]);
        $value = $stmt->fetchColumn();

        return is_string($value) && preg_match('/^\+[1-9]\d{7,14}$/', $value) === 1 ? $value : null;
    }

    private function whatsappSetting(int $tenantId, string $key): string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT JSON_UNQUOTE(JSON_EXTRACT(COALESCE(preferences, JSON_OBJECT()), :p))
               FROM tenant_settings WHERE tenant_id = :t",
        );
        $stmt->execute(['p' => '$.whatsapp.' . $key, 't' => $tenantId]);
        $value = $stmt->fetchColumn();

        return is_string($value) ? $value : '';
    }

    private function channelAvailable(int $tenantId, string $channel): bool
    {
        if ($channel === 'email') {
            return true; // platform mailer; no tenant account needed
        }

        $features = $this->features ?? new FeatureGate($this->db);
        // WhatsApp follows the explicit sender mode (see ChannelSender):
        // business mode needs the business's own active account; platform
        // mode needs the platform number and a plan that includes WhatsApp.
        if ($channel === 'whatsapp') {
            $business = $this->whatsappSetting($tenantId, 'sender') === 'business'
                && $features->enabled($tenantId, 'whatsapp_own_number');
            if ($business) {
                return $this->tenantAccountActive($tenantId, 'whatsapp');
            }

            return $this->config->get('notifications.platform.whatsapp.provider', '') !== ''
                && $features->underLimit($tenantId, 'whatsapp_messages', 0);
        }

        if ($this->tenantAccountActive($tenantId, $channel)) {
            return true;
        }

        // Platform-owned fallback (config/notifications.php): the platform
        // pays, so it is only available when the plan includes the channel
        // ({channel}_messages enabled). Plans without it never queue rows
        // that would send on the platform's account.
        if ($this->config->get('notifications.platform.' . $channel . '.provider', '') === '') {
            return false;
        }

        return $features->underLimit($tenantId, $channel . '_messages', 0);
    }

    private function tenantAccountActive(int $tenantId, string $channel): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM tenant_notification_accounts a
               JOIN notification_providers p ON p.id = a.provider_id
              WHERE a.tenant_id = :t AND a.status = 'active' AND p.channel = :c AND p.is_active = 1
              LIMIT 1",
        );
        $stmt->execute(['t' => $tenantId, 'c' => $channel]);

        return $stmt->fetchColumn() !== false;
    }
}
