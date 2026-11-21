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
    private const FALLBACK_SUBJECT = 'AlbaBook';

    private const FALLBACKS = [
        'payment_update' => 'Payment update for your appointment {reference}: paid {paid_amount} of {total_amount}.',
        'booking_received' => 'We received your booking {reference} for {start_at}. You will get a confirmation shortly.',
        'booking_confirmed' => 'Your booking {reference} is confirmed for {start_at}.',
        'booking_rejected' => 'Your booking {reference} could not be accommodated. Please pick another time.',
        'appointment_reminder_24h' => 'Reminder: your appointment {reference} is tomorrow at {start_at}.',
        'appointment_reminder_2h' => 'Reminder: your appointment {reference} starts soon ({start_at}).',
        'appointment_cancelled' => 'Your appointment {reference} has been cancelled.',
        'appointment_expired' => 'Your booking {reference} expired before it could be confirmed.',
        'appointment_completed' => 'Thank you for your visit! Your receipt for {reference}: {receipt_url}',
        'appointment_rescheduled' => 'Your appointment {reference} was moved to {start_at}.',
        'manage_link' => 'Manage your booking {reference}: {manage_url}',
    ];

    public function __construct(private readonly Database $db) {}

    /**
     * @param array<string, mixed> $payload
     * @return array{subject: string, body: string}
     */
    public function render(int $tenantId, string $channel, string $locale, array $payload): array
    {
        $event = (string) ($payload['event'] ?? '');
        $template = $this->template($tenantId, $channel, $event, $locale);
        $subject = $template['subject'] ?? self::FALLBACK_SUBJECT;
        $body = $template['body'] ?? self::FALLBACKS[$event] ?? 'Update for your booking {reference}.';

        $values = $this->values($payload);

        return [
            'subject' => $this->interpolate((string) $subject, $values),
            'body' => $this->interpolate($body, $values),
        ];
    }

    /** @return array{subject: ?string, body: string}|null */
    private function template(int $tenantId, string $channel, string $event, string $locale): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT subject, body FROM notification_templates
              WHERE channel = :c AND event_key = :e AND locale = :l AND is_active = 1
                AND (tenant_id = :t OR tenant_id IS NULL)
              ORDER BY tenant_id IS NULL LIMIT 1',
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

    /** @param array<string, string> $values */
    private function interpolate(string $text, array $values): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}|\{\s*([a-zA-Z0-9_]+)\s*\}/',
            static fn (array $m): string => $values[$m[1] !== '' ? $m[1] : $m[2]] ?? $m[0],
            $text,
        );
    }
}
