<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Core\Database\Database;
use App\Modules\Notifications\Repositories\NotificationRepository;

/**
 * Processes due scheduled_notifications rows into notification_queue
 * jobs. Rows whose appointment moved on (version changed) or ended
 * (cancelled/completed/no-show/expired) are skipped so stale reminders
 * never reach a customer.
 */
final class ReminderService
{
    public function __construct(
        private readonly Database $db,
        private readonly NotificationPolicy $policy,
        private readonly NotificationRepository $queue,
    ) {}

    /** Process up to $limit due reminders; returns the number claimed. */
    public function dispatchBatch(int $limit = 50): int
    {
        $rows = $this->claim($limit);
        foreach ($rows as $row) {
            $this->process($row);
        }

        return count($rows);
    }

    /** @return list<array<string, mixed>> */
    private function claim(int $limit): array
    {
        return $this->db->transaction(function () use ($limit): array {
            $stmt = $this->db->pdo()->prepare(
                "SELECT id, tenant_id, appointment_id, appointment_version, kind
                   FROM scheduled_notifications
                  WHERE status = 'pending' AND send_at <= UTC_TIMESTAMP()
                  ORDER BY send_at LIMIT :n
                  FOR UPDATE SKIP LOCKED",
            );
            $stmt->bindValue('n', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = array_values($stmt->fetchAll());
            if ($rows === []) {
                return [];
            }
            $ids = implode(',', array_map(static fn(array $r): int => (int) $r['id'], $rows));
            $this->db->pdo()->exec("UPDATE scheduled_notifications SET status = 'processing' WHERE id IN ({$ids})");

            return $rows;
        });
    }

    /** @param array<string, mixed> $row */
    private function process(array $row): void
    {
        $id = (int) $row['id'];
        $appointment = $this->appointment((int) $row['tenant_id'], (int) $row['appointment_id']);

        if ($appointment === null
            || (int) $appointment['version'] !== (int) $row['appointment_version']
        ) {
            $this->mark($id, 'skipped');

            return;
        }
        if (in_array((string) $appointment['status'], ['cancelled', 'rejected', 'completed', 'no_show', 'expired'], true)) {
            $this->mark($id, 'skipped');

            return;
        }

        $payload = json_encode([
            'event' => (string) $row['kind'],
            'appointment_uuid' => (string) $appointment['uuid'],
            'reference' => (string) $appointment['reference'],
            'start_at' => $this->localStart($appointment),
            'business_name' => (string) $appointment['business_name'],
            'branch_name' => (string) $appointment['branch_name'],
        ], JSON_THROW_ON_ERROR);

        $queued = 0;
        foreach (['email' => $appointment['customer_email'], 'sms' => $appointment['customer_phone']] as $channel => $recipient) {
            if ($recipient === null || $recipient === ''
                || !$this->policy->allows((int) $row['tenant_id'], $channel, (string) $row['kind'])) {
                continue;
            }
            $this->queue->enqueue((int) $row['tenant_id'], (int) $row['appointment_id'], $channel, (string) $recipient, $payload);
            $queued++;
        }

        $this->mark($id, $queued > 0 ? 'sent' : 'skipped');
    }

    /** @return array<string, mixed>|null */
    private function appointment(int $tenantId, int $appointmentId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.id, a.uuid, a.reference, a.status, a.version, a.start_at,
                    COALESCE(b.timezone, t.timezone) AS timezone,
                    t.name AS business_name, b.name AS branch_name,
                    a.customer_email_snapshot AS customer_email,
                    a.customer_phone_snapshot AS customer_phone
               FROM appointments a
               JOIN tenants t ON t.id = a.tenant_id
               JOIN branches b ON b.tenant_id = a.tenant_id AND b.id = a.branch_id
              WHERE a.tenant_id = :t AND a.id = :a',
        );
        $stmt->execute(['t' => $tenantId, 'a' => $appointmentId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $appointment */
    private function localStart(array $appointment): string
    {
        // Branch timezone, falling back to the tenant's (booking-engine.md §3).
        try {
            $tz = new \DateTimeZone((string) ($appointment['timezone'] ?: 'UTC'));
        } catch (\Exception) {
            $tz = new \DateTimeZone('UTC');
        }

        return (new \DateTimeImmutable((string) $appointment['start_at'], new \DateTimeZone('UTC')))
            ->setTimezone($tz)
            ->format('j M Y H:i');
    }

    private function mark(int $id, string $status): void
    {
        $this->db->pdo()->prepare(
            'UPDATE scheduled_notifications SET status = :s, sent_at = UTC_TIMESTAMP() WHERE id = :id',
        )->execute(['s' => $status, 'id' => $id]);
    }
}
