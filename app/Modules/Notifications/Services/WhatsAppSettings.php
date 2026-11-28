<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Core\Database\Database;
use App\Modules\Billing\Services\FeatureGate;

/**
 * A business's WhatsApp configuration (tenant_settings.preferences.whatsapp)
 * and, for own-number mode, its template mappings (notification_templates
 * rows with tenant_id = the business, channel whatsapp).
 *
 *   sender         platform | business
 *   customer_mode  off | instead_of_sms | alongside_sms
 */
final class WhatsAppSettings
{
    public const SENDERS = ['platform', 'business'];
    public const CUSTOMER_MODES = ['off', 'instead_of_sms', 'alongside_sms'];

    /** Payload keys a template may use as {{n}} values. */
    public const PARAM_KEYS = [
        'business_name', 'branch_name', 'customer_name', 'reference', 'start_at',
        'paid_amount', 'total_amount', 'manage_url', 'status',
    ];

    /** Events that may be sent on WhatsApp (customer audience). */
    public const EVENTS = [
        'booking_received', 'booking_confirmed', 'booking_rejected',
        'appointment_reminder_24h', 'appointment_reminder_2h', 'appointment_rescheduled',
        'appointment_cancelled', 'appointment_expired', 'appointment_completed',
        'payment_update', 'manage_link',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly FeatureGate $features,
        private readonly ChannelSender $sender,
    ) {}

    /** @return array<string, mixed> */
    public function describe(int $tenantId): array
    {
        $prefs = $this->prefs($tenantId);

        return [
            'sender' => $this->sender->whatsappSender($tenantId),
            'sender_requested' => in_array($prefs['sender'] ?? '', self::SENDERS, true) ? $prefs['sender'] : 'platform',
            'customer_mode' => in_array($prefs['customer_mode'] ?? '', self::CUSTOMER_MODES, true) ? $prefs['customer_mode'] : 'off',
            'can_use_own_number' => $this->features->enabled($tenantId, 'whatsapp_own_number'),
            'plan_includes_platform' => $this->features->underLimit($tenantId, 'whatsapp_messages', 0),
            'platform_available' => $this->sender->platformConfigured('whatsapp'),
            'own_account_connected' => $this->ownAccountConnected($tenantId),
            'param_keys' => self::PARAM_KEYS,
            'templates' => $this->templates($tenantId),
            'platform_templates' => $this->templates(null),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string> field errors (empty = saved)
     */
    public function update(int $tenantId, array $input): array
    {
        $errors = [];
        $sender = (string) ($input['sender'] ?? 'platform');
        $mode = (string) ($input['customer_mode'] ?? 'off');
        if (!in_array($sender, self::SENDERS, true)) {
            $errors['sender'] = 'Choose who sends WhatsApp messages.';
        } elseif ($sender === 'business' && !$this->features->enabled($tenantId, 'whatsapp_own_number')) {
            $errors['sender'] = 'Sending from your own WhatsApp number is not included in your plan.';
        }
        if (!in_array($mode, self::CUSTOMER_MODES, true)) {
            $errors['customer_mode'] = 'Choose how WhatsApp is used for customers.';
        }
        if ($errors !== []) {
            return $errors;
        }

        $this->db->pdo()->prepare(
            "UPDATE tenant_settings
                SET preferences = JSON_SET(COALESCE(preferences, JSON_OBJECT()), '$.whatsapp', JSON_EXTRACT(:v, '$'))
              WHERE tenant_id = :t",
        )->execute(['v' => json_encode(['sender' => $sender, 'customer_mode' => $mode], JSON_THROW_ON_ERROR), 't' => $tenantId]);

        return [];
    }

    /**
     * Own-number template mappings. Each entry: event_key, name (Meta
     * template name, or a Twilio Content SID "HX…"), language, params
     * (ordered payload keys). An empty name removes the mapping.
     * @param list<array<string, mixed>> $templates
     * @return array<string, string>
     */
    public function saveTemplates(int $tenantId, array $templates): array
    {
        $errors = [];
        $rows = [];
        foreach ($templates as $i => $t) {
            $event = (string) ($t['event_key'] ?? '');
            $name = trim((string) ($t['name'] ?? ''));
            $language = trim((string) ($t['language'] ?? 'en'));
            $params = is_array($t['params'] ?? null) ? array_values($t['params']) : [];
            if (!in_array($event, self::EVENTS, true)) {
                $errors["templates.{$i}.event_key"] = 'Unknown event.';
                continue;
            }
            if ($name !== '' && preg_match('/^([a-z0-9_]{1,512}|HX[0-9a-f]{32})$/', $name) !== 1) {
                $errors["templates.{$i}.name"] = 'Use the template name exactly as approved (lowercase, digits, underscores) or a Twilio Content SID.';
            }
            if (preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $language) !== 1) {
                $errors["templates.{$i}.language"] = 'Language code like en or en_US.';
            }
            foreach ($params as $p) {
                if (!is_string($p) || !in_array($p, self::PARAM_KEYS, true)) {
                    $errors["templates.{$i}.params"] = 'Variables must come from the listed fields.';
                    break;
                }
            }
            if (count($params) > 10) {
                $errors["templates.{$i}.params"] = 'At most 10 variables.';
            }
            $rows[] = [$event, $name, $language, $params];
        }
        if ($errors !== []) {
            return $errors;
        }

        $this->db->transaction(function () use ($tenantId, $rows): void {
            $pdo = $this->db->pdo();
            foreach ($rows as [$event, $name, $language, $params]) {
                if ($name === '') {
                    $pdo->prepare(
                        "DELETE FROM notification_templates
                          WHERE tenant_id = :t AND channel = 'whatsapp' AND event_key = :e AND locale = 'en'",
                    )->execute(['t' => $tenantId, 'e' => $event]);
                    continue;
                }
                // Body: the platform wording, used as free-form fallback text.
                $body = $pdo->prepare(
                    "SELECT body FROM notification_templates
                      WHERE tenant_id IS NULL AND channel = 'whatsapp' AND event_key = :e AND locale = 'en'",
                );
                $body->execute(['e' => $event]);
                $pdo->prepare(
                    "INSERT INTO notification_templates
                        (tenant_id, channel, event_key, locale, body, wa_template_name, wa_language, wa_params, is_active)
                     VALUES (:t, 'whatsapp', :e, 'en', :b, :n, :l, JSON_EXTRACT(:p, '$'), TRUE)
                     ON DUPLICATE KEY UPDATE wa_template_name = VALUES(wa_template_name),
                        wa_language = VALUES(wa_language), wa_params = VALUES(wa_params), is_active = TRUE",
                )->execute([
                    't' => $tenantId, 'e' => $event, 'b' => (string) ($body->fetchColumn() ?: '{business_name}: update for your booking {reference}.'),
                    'n' => $name, 'l' => $language, 'p' => json_encode($params, JSON_THROW_ON_ERROR),
                ]);
            }
        });

        return [];
    }

    /** @return list<array{event_key: string, name: string, language: string, params: list<string>, body: string}> */
    private function templates(?int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT event_key, wa_template_name, wa_language, wa_params, body FROM notification_templates
              WHERE channel = 'whatsapp' AND locale = 'en' AND "
            . ($tenantId === null ? 'tenant_id IS NULL' : 'tenant_id = :t')
            . ' ORDER BY event_key',
        );
        $stmt->execute($tenantId === null ? [] : ['t' => $tenantId]);

        return array_map(static function (array $r): array {
            $params = json_decode((string) ($r['wa_params'] ?? '[]'), true);

            return [
                'event_key' => (string) $r['event_key'],
                'name' => (string) ($r['wa_template_name'] ?? ''),
                'language' => (string) ($r['wa_language'] ?? 'en'),
                'params' => is_array($params) ? array_values(array_map('strval', $params)) : [],
                'body' => (string) $r['body'],
            ];
        }, $stmt->fetchAll());
    }

    private function ownAccountConnected(int $tenantId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM tenant_notification_accounts a JOIN notification_providers p ON p.id = a.provider_id
              WHERE a.tenant_id = :t AND a.status = 'active' AND p.channel = 'whatsapp' LIMIT 1",
        );
        $stmt->execute(['t' => $tenantId]);

        return $stmt->fetchColumn() !== false;
    }

    /** @return array<string, mixed> */
    private function prefs(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT JSON_EXTRACT(COALESCE(preferences, JSON_OBJECT()), '$.whatsapp') FROM tenant_settings WHERE tenant_id = ?",
        );
        $stmt->execute([$tenantId]);
        $raw = $stmt->fetchColumn();
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
