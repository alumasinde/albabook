<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Core\Database\Database;
use App\Modules\Notifications\Repositories\NotificationRepository;
use App\Shared\Support\UuidFactory;

/**
 * Produces the side effects of appointment changes inside the caller's
 * transaction so queue state cannot diverge from the booking:
 *   - notification_queue rows for customer-facing events,
 *   - scheduled_notifications reminder rows (version-pinned; the reminders
 *     worker skips rows whose appointment_version no longer matches),
 *   - outbox_events rows for webhook fan-out (appointment.* event types).
 */
final class AppointmentEventService
{
    /** Appointment status => customer notification_events.code. */
    private const EVENT_FOR = [
        'rescheduled' => 'appointment_rescheduled',
        'confirmed' => 'booking_confirmed',
        'rejected' => 'booking_rejected',
        'cancelled' => 'appointment_cancelled',
        'completed' => 'appointment_completed',
        'expired' => 'appointment_expired',
        // Reschedules go through onTransitioned('rescheduled'): the customer
        // gets the new time, reminders are re-armed against the new version.
        'rescheduled' => 'appointment_rescheduled',
    ];

    /** scheduled_notifications.kind => seconds before start_at. */
    private const REMINDERS = [
        'appointment_reminder_24h' => 86400,
        'appointment_reminder_2h' => 7200,
    ];

    private const TERMINAL = ['cancelled', 'rejected', 'completed', 'no_show', 'expired'];

    /** Bookings customers make themselves; only these alert the business. */
    private const CUSTOMER_SOURCES = ['public_page', 'widget', 'api'];

    /** Upper bound on alert e-mails per event (large teams). */
    private const MAX_ALERT_RECIPIENTS = 10;

    public function __construct(
        private readonly Database $db,
        private readonly UuidFactory $uuids,
        private readonly NotificationPolicy $policy,
        private readonly NotificationRepository $queue,
    ) {}

    /**
     * New booking: receipt/confirmation notice + reminders + outbox event.
     * Call inside the transaction that inserted the appointment.
     */
    public function onCreated(int $tenantId, int $appointmentId): void
    {
        $appointment = $this->load($tenantId, $appointmentId);
        if ($appointment === null) {
            return;
        }
        $event = (string) $appointment['status'] === 'confirmed' ? 'booking_confirmed' : 'booking_received';
        $this->notify($appointment, $event);
        $this->emit($appointment, 'appointment.created', $event);
        $this->scheduleReminders($appointment);
        // Online bookings tell the business (staff-made ones need no alert).
        if (in_array((string) ($appointment['booking_source'] ?? ''), self::CUSTOMER_SOURCES, true)) {
            $this->alertBusiness($appointment, 'business_new_booking');
        }
    }

    /**
     * Status transition: matching customer event + outbox event + reminder
     * lifecycle (pending reminders are cancelled, then re-armed against the
     * new version when the appointment remains live). Call inside the
     * transition transaction, after updateStatus.
     */
    public function onTransitioned(int $tenantId, int $appointmentId, string $to): void
    {
        $appointment = $this->load($tenantId, $appointmentId);
        if ($appointment === null) {
            return;
        }
        $event = self::EVENT_FOR[$to] ?? null;
        if ($event !== null) {
            $this->notify($appointment, $event);
        }
        $this->emit($appointment, 'appointment.' . $to, $event ?? 'status.changed');
        // A customer cancelling (manage link / public API) frees a slot the
        // business should know about; staff cancellations need no alert.
        if ($to === 'cancelled' && (string) ($appointment['cancelled_by'] ?? '') === 'customer') {
            $this->alertBusiness($appointment, 'business_booking_cancelled');
        }
        $this->cancelReminders($tenantId, $appointmentId);
        if (!in_array($to, self::TERMINAL, true)) {
            $this->scheduleReminders($appointment);
        }
    }

    /** @param array<string, mixed> $appointment */
    private function notify(array $appointment, string $event): void
    {
        $payload = $this->payload($appointment, $event);
        // Phone recipients go to SMS and/or WhatsApp per the business's setting.
        $channels = ['email' => 'customer_email_snapshot'];
        foreach ($this->policy->phoneChannels((int) $appointment['tenant_id']) as $phoneChannel) {
            $channels[$phoneChannel] = 'customer_phone_snapshot';
        }
        foreach ($channels as $channel => $column) {
            $recipient = trim((string) ($appointment[$column] ?? ''));
            if ($recipient === '' || !$this->policy->allows((int) $appointment['tenant_id'], $channel, $event)) {
                continue;
            }
            $this->queue->enqueue((int) $appointment['tenant_id'], (int) $appointment['id'], $channel, $recipient, $payload);
        }
    }

    /**
     * Business-facing alert: e-mail to every active team member who can
     * approve bookings for this branch (appointments.confirm, branch scope
     * honoured), plus an SMS to the optional alert phone in Settings ->
     * Notifications. Each channel obeys the business's preference grid.
     *
     * @param array<string, mixed> $appointment
     */
    private function alertBusiness(array $appointment, string $event): void
    {
        $tenantId = (int) $appointment['tenant_id'];
        $payload = $this->payload($appointment, $event);

        if ($this->policy->allows($tenantId, 'email', $event)) {
            foreach ($this->alertEmails($tenantId, (int) $appointment['branch_id']) as $email) {
                $this->queue->enqueue($tenantId, (int) $appointment['id'], 'email', $email, $payload);
            }
        }
        $phone = $this->policy->businessAlertPhone($tenantId);
        if ($phone !== null && $this->policy->allows($tenantId, 'sms', $event)) {
            $this->queue->enqueue($tenantId, (int) $appointment['id'], 'sms', $phone, $payload);
        }
    }

    /** @return list<string> */
    private function alertEmails(int $tenantId, int $branchId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT DISTINCT u.email
               FROM tenant_users tu
               JOIN users u ON u.id = tu.user_id AND u.status = 'active' AND u.email_verified_at IS NOT NULL
               JOIN membership_roles mr ON mr.tenant_id = tu.tenant_id AND mr.tenant_user_id = tu.id
               JOIN role_permissions rp ON rp.role_id = mr.role_id
               JOIN permissions p ON p.id = rp.permission_id AND p.scope = 'tenant' AND p.identifier = 'appointments.confirm'
              WHERE tu.tenant_id = :t AND tu.status = 'active'
                AND (NOT EXISTS (SELECT 1 FROM membership_branches mb
                                  WHERE mb.tenant_id = tu.tenant_id AND mb.tenant_user_id = tu.id)
                     OR EXISTS (SELECT 1 FROM membership_branches mb2
                                 WHERE mb2.tenant_id = tu.tenant_id AND mb2.tenant_user_id = tu.id AND mb2.branch_id = :b))
              ORDER BY u.email
              LIMIT " . self::MAX_ALERT_RECIPIENTS,
        );
        $stmt->execute(['t' => $tenantId, 'b' => $branchId]);

        return array_values(array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /** @param array<string, mixed> $appointment */
    private function emit(array $appointment, string $eventType, string $event): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO outbox_events (uuid, tenant_id, event_type, aggregate_type, aggregate_id, payload)
             VALUES (?, ?, ?, ?, ?, ?)',
        )->execute([
            $this->uuids->generate(),
            (int) $appointment['tenant_id'],
            $eventType,
            'appointment',
            (int) $appointment['id'],
            $this->payload($appointment, $event),
        ]);
    }

    /**
     * Arm a pending reminder row per kind when its send time is still ahead.
     *
     * @param array<string, mixed> $appointment
     */
    private function scheduleReminders(array $appointment): void
    {
        $start = new \DateTimeImmutable((string) $appointment['start_at'], new \DateTimeZone('UTC'));
        foreach (self::REMINDERS as $kind => $offsetSeconds) {
            $sendAt = $start->getTimestamp() - $offsetSeconds;
            if ($sendAt <= time()) {
                continue;
            }
            $this->db->pdo()->prepare(
                "INSERT INTO scheduled_notifications (tenant_id, appointment_id, appointment_version, kind, send_at, status)
                 VALUES (:t, :a, :v, :k, :s, 'pending')",
            )->execute([
                't' => (int) $appointment['tenant_id'],
                'a' => (int) $appointment['id'],
                'v' => (int) $appointment['version'],
                'k' => $kind,
                's' => gmdate('Y-m-d H:i:s', $sendAt),
            ]);
        }
    }

    private function cancelReminders(int $tenantId, int $appointmentId): void
    {
        $this->db->pdo()->prepare(
            "UPDATE scheduled_notifications SET status = 'cancelled'
              WHERE tenant_id = :t AND appointment_id = :a AND status = 'pending'",
        )->execute(['t' => $tenantId, 'a' => $appointmentId]);
    }

    /**
     * Payload shape matches ReminderService/till receipts so the workers'
     * MessageRenderer sees consistent keys.
     *
     * @param array<string, mixed> $appointment
     */
    private function payload(array $appointment, string $event): string
    {
        return json_encode([
            'event' => $event,
            'appointment_uuid' => (string) $appointment['uuid'],
            'reference' => (string) $appointment['reference'],
            'status' => (string) $appointment['status'],
            'start_at' => $this->localStart($appointment),
            'customer_name' => (string) ($appointment['customer_name_snapshot'] ?? ''),
            'business_name' => (string) ($appointment['business_name'] ?? ''),
            'branch_name' => (string) ($appointment['branch_name'] ?? ''),
            // Business alerts: who booked what, and whether it needs approval.
            'customer_phone' => (string) ($appointment['customer_phone_snapshot'] ?? ''),
            'service_name' => (string) ($appointment['service_name'] ?? ''),
            'approval_note' => (string) $appointment['status'] === 'pending' ? 'Needs your approval in AlbaBook.' : '',
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Start time in the branch's timezone (falls back to the tenant's, per
     * booking-engine.md §3). Runs inside the booking transaction, so an
     * unparseable zone degrades to UTC instead of failing the booking.
     * @param array<string, mixed> $appointment
     */
    private function localStart(array $appointment): string
    {
        try {
            $tz = new \DateTimeZone((string) ($appointment['timezone'] ?: 'UTC'));
        } catch (\Exception) {
            $tz = new \DateTimeZone('UTC');
        }

        return (new \DateTimeImmutable((string) $appointment['start_at'], new \DateTimeZone('UTC')))
            ->setTimezone($tz)
            ->format('j M Y H:i');
    }

    /** @return array<string, mixed>|null */
    private function load(int $tenantId, int $appointmentId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.id, a.tenant_id, a.branch_id, a.uuid, a.reference, a.status, a.version, a.start_at,
                    a.booking_source, a.cancelled_by,
                    a.customer_name_snapshot, a.customer_email_snapshot, a.customer_phone_snapshot,
                    (SELECT ai.service_name_snapshot FROM appointment_items ai
                      WHERE ai.tenant_id = a.tenant_id AND ai.appointment_id = a.id
                      ORDER BY ai.item_position LIMIT 1) AS service_name,
                    t.name AS business_name, b.name AS branch_name,
                    COALESCE(b.timezone, t.timezone) AS timezone
               FROM appointments a
               JOIN tenants t ON t.id = a.tenant_id
               JOIN branches b ON b.tenant_id = a.tenant_id AND b.id = a.branch_id
              WHERE a.tenant_id = :t AND a.id = :a',
        );
        $stmt->execute(['t' => $tenantId, 'a' => $appointmentId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }
}
