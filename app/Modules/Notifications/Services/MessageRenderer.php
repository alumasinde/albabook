<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Core\Database\Database;

/**
 * Turns a notification_queue payload into channel text. A tenant-scoped or
 * system `notification_templates` row wins when present; otherwise a
 * built-in English message per event is used so notifications still go out
 * before the business customises its templates.
 */
final class MessageRenderer
{
    /** Used only when an event has no subject of its own. */
    private const FALLBACK_SUBJECT = 'Update from {business_name}';

    /**
     * Built-in bodies. Every message names the business up front — a
     * customer may book with several businesses on AlbaBook and an SMS
     * sender ID alone does not say which one this is.
     */
    private const FALLBACKS = [
        'payment_update' => '{business_name}: payment update for appointment {reference}: paid {paid_amount} of {total_amount}.',
        'booking_received' => '{business_name}: we received your booking {reference} for {start_at}. You will get a confirmation shortly.',
        'booking_confirmed' => '{business_name}: your booking {reference} is confirmed for {start_at}.',
        'booking_rejected' => '{business_name}: your booking {reference} could not be accommodated. Please pick another time.',
        'appointment_reminder_24h' => '{business_name}: reminder, your appointment {reference} is tomorrow at {start_at}.',
        'appointment_reminder_2h' => '{business_name}: reminder, your appointment {reference} starts soon ({start_at}).',
        'appointment_cancelled' => '{business_name}: your appointment {reference} has been cancelled.',
        'appointment_expired' => '{business_name}: your booking {reference} expired before it could be confirmed.',
        // No receipt link: the only receipt page is the staff-only
        // /appointments/{uuid}/receipt, which customers cannot open.
        'appointment_completed' => 'Thank you for visiting {business_name}! Your appointment {reference} is complete.',
        'appointment_rescheduled' => '{business_name}: your appointment {reference} was moved to {start_at}.',
        'manage_link' => '{business_name}: manage your booking {reference}: {manage_url}',
        // Business-facing alerts (to the team, not the customer).
        'business_new_booking' => 'New online booking {reference}: {customer_name} ({customer_phone}), {service_name}, {start_at} at {branch_name}. {approval_note}',
        'business_booking_cancelled' => 'Cancelled by customer: {reference}, {customer_name} ({customer_phone}), {service_name}, {start_at} at {branch_name}. The slot is free again.',
    ];

    /** Built-in email subjects per event (templates may override). */
    private const SUBJECTS = [
        'payment_update' => 'Payment received — {business_name}',
        'booking_received' => 'Booking received — {business_name}',
        'booking_confirmed' => 'Booking confirmed — {business_name}',
        'booking_rejected' => 'Booking not accepted — {business_name}',
        'appointment_reminder_24h' => 'Reminder: your appointment tomorrow — {business_name}',
        'appointment_reminder_2h' => 'Reminder: your appointment today — {business_name}',
        'appointment_cancelled' => 'Appointment cancelled — {business_name}',
        'appointment_expired' => 'Booking expired — {business_name}',
        'appointment_completed' => 'Thank you for your visit — {business_name}',
        'appointment_rescheduled' => 'Appointment moved — {business_name}',
        'manage_link' => 'Manage your booking — {business_name}',
        'business_new_booking' => 'New booking {reference} — {customer_name}, {start_at}',
        'business_booking_cancelled' => 'Booking cancelled by customer — {reference}, {start_at}',
    ];

    public function __construct(private readonly Database $db) {}

    /**
     * @param array<string, mixed> $payload
     * @param string|null $waSender WhatsApp sender mode ('platform'|'business');
     *        decides whose templates apply (see 028_whatsapp_templates.sql)
     * @return array{subject: string, body: string, template: array{name: string, language: string, params: list<string>}|null}
     */
    public function render(int $tenantId, string $channel, string $locale, array $payload, ?string $waSender = null): array
    {
        $event = (string) ($payload['event'] ?? '');
        // WhatsApp scope: platform mode uses only the platform's approved
        // templates; business mode only the business's own mappings (their
        // names exist only in their WhatsApp account).
        $scope = $channel === 'whatsapp' ? ($waSender === 'business' ? 'tenant' : 'platform') : 'any';
        $template = $this->template($tenantId, $channel, $event, $locale, $scope);
        $subject = ($template['subject'] ?? '') !== ''
            ? (string) $template['subject']
            : (self::SUBJECTS[$event] ?? self::FALLBACK_SUBJECT);
        $body = $template['body'] ?? self::FALLBACKS[$event] ?? '{business_name}: update for your booking {reference}.';

        $values = $this->values($payload);
        // Rows queued before payloads carried the name (and till receipts)
        // still get it, so no message goes out with a blank business.
        if (($values['business_name'] ?? '') === '') {
            $values['business_name'] = $this->businessName($tenantId);
        }

        $waTemplate = null;
        if ($channel === 'whatsapp' && ($template['wa_template_name'] ?? null) !== null && $template['wa_template_name'] !== '') {
            $keys = json_decode((string) ($template['wa_params'] ?? '[]'), true);
            $waTemplate = [
                'name' => (string) $template['wa_template_name'],
                'language' => (string) ($template['wa_language'] ?: 'en'),
                // Positional {{1}}, {{2}}… values from the payload keys.
                'params' => array_values(array_map(
                    static fn(mixed $k): string => is_string($k) ? ($values[$k] ?? '') : '',
                    is_array($keys) ? $keys : [],
                )),
            ];
        }

        return [
            'subject' => $this->interpolate((string) $subject, $values),
            'body' => $this->interpolate($body, $values),
            'template' => $waTemplate,
        ];
    }

    private function businessName(int $tenantId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT name FROM tenants WHERE id = :t');
        $stmt->execute(['t' => $tenantId]);
        $name = $stmt->fetchColumn();

        return is_string($name) && $name !== '' ? $name : 'AlbaBook';
    }

    /**
     * @param 'any'|'platform'|'tenant' $scope
     * @return array<string, mixed>|null
     */
    private function template(int $tenantId, string $channel, string $event, string $locale, string $scope = 'any'): ?array
    {
        $scopeSql = match ($scope) {
            'platform' => 'tenant_id IS NULL AND :t >= 0',
            'tenant' => 'tenant_id = :t',
            default => '(tenant_id = :t OR tenant_id IS NULL)',
        };
        $stmt = $this->db->pdo()->prepare(
            "SELECT subject, body, wa_template_name, wa_language, wa_params FROM notification_templates
              WHERE channel = :c AND event_key = :e AND locale = :l AND is_active = 1
                AND {$scopeSql}
              ORDER BY tenant_id IS NULL LIMIT 1",
        );
        $stmt->execute(['c' => $channel, 'e' => $event, 'l' => $locale, 't' => $tenantId]);
        $row = $stmt->fetch();
        if ($row !== false) {
            /** @var array{subject: ?string, body: string} */
            return $row;
        }

        // Fall back to the default locale when the tenant's has no template.
        if ($locale !== 'en') {
            $stmt->execute(['c' => $channel, 'e' => $event, 'l' => 'en', 't' => $tenantId]);
            $row = $stmt->fetch();
            if ($row !== false) {
                /** @var array{subject: ?string, body: string} */
                return $row;
            }
        }

        return null;
    }

    /**
     * Payload values + money helpers: every `*_minor` key also gets a
     * matching `*_amount` placeholder rendered in major units.
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private function values(array $payload): array
    {
        $values = [];
        foreach ($payload as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $values[(string) $key] = (string) $value;
            }
            if (is_int($value) && str_ends_with((string) $key, '_minor')) {
                $values[substr((string) $key, 0, -6) . '_amount'] = number_format($value / 100, 2);
            }
        }

        return $values;
    }

    /**
     * Unknown placeholders render as empty text rather than leaking the raw
     * "{name}" to customers (e.g. a tenant template using a key the payload
     * does not carry); whitespace left behind is collapsed.
     * @param array<string, string> $values
     */
    private function interpolate(string $text, array $values): string
    {
        $out = (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}|\{\s*([a-zA-Z0-9_]+)\s*\}/',
            static fn (array $m): string => $values[($m[2] ?? '') !== '' ? $m[2] : $m[1]] ?? '',
            $text,
        );

        return trim((string) preg_replace('/[ \t]{2,}/', ' ', $out));
    }
}
