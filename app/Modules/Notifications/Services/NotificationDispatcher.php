<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Core\Database\Database;
use App\Modules\Billing\Services\FeatureGate;
use App\Shared\Mail\Mailer;
use App\Shared\Mail\MailMessage;

/**
 * Sends rows from notification_queue. Claiming uses FOR UPDATE SKIP
 * LOCKED in a short transaction; the actual send happens after commit so
 * a slow provider never holds row locks (multi-tenancy.md "Background
 * workers"). Failures retry with linear backoff up to MAX_ATTEMPTS.
 */
final class NotificationDispatcher
{
    private const MAX_ATTEMPTS = 5;
    private const RETRY_SECONDS = 300;

    public function __construct(
        private readonly Database $db,
        private readonly Mailer $mailer,
        private readonly ChannelSender $channels,
        private readonly MessageRenderer $renderer,
        private readonly FeatureGate $features,
    ) {}

    /** Process up to $limit due rows; returns the number claimed. */
    public function dispatchBatch(int $limit = 20): int
    {
        $jobs = $this->claim($limit);
        foreach ($jobs as $job) {
            $this->deliver($job);
        }

        return count($jobs);
    }

    /** @return list<array<string, mixed>> */
    private function claim(int $limit): array
    {
        return $this->db->transaction(function () use ($limit): array {
            $stmt = $this->db->pdo()->prepare(
                "SELECT q.id, q.tenant_id, q.channel, q.recipient, q.payload,
                        COALESCE(t.default_locale, 'en') AS locale
                   FROM notification_queue q
                   JOIN tenants t ON t.id = q.tenant_id
                  WHERE q.status = 'pending' AND q.scheduled_at <= UTC_TIMESTAMP()
                  ORDER BY q.id LIMIT :n
                  FOR UPDATE SKIP LOCKED",
            );
            $stmt->bindValue('n', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            $jobs = array_values($stmt->fetchAll());
            if ($jobs === []) {
                return [];
            }

            $ids = implode(',', array_map(static fn(array $j): int => (int) $j['id'], $jobs));
            $this->db->pdo()->exec("UPDATE notification_queue SET status = 'processing' WHERE id IN ({$ids})");

            return $jobs;
        });
    }

    /** @param array<string, mixed> $job */
    private function deliver(array $job): void
    {
        $id = (int) $job['id'];
        try {
            $channel = (string) $job['channel'];
            if ($channel === 'sms' || $channel === 'whatsapp') {
                $feature = $channel . '_messages';
                $stmt = $this->db->pdo()->prepare(
                    "SELECT COUNT(*) FROM notification_queue
                      WHERE tenant_id = :t AND channel = :c AND status = 'sent'
                        AND sent_at >= DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-01')",
                );
                $stmt->execute(['t' => (int) $job['tenant_id'], 'c' => $channel]);
                $sent = (int) $stmt->fetchColumn();
                if (!$this->features->underLimit((int) $job['tenant_id'], $feature, $sent)) {
                    $this->db->pdo()->prepare(
                        "UPDATE notification_queue SET status = 'skipped', last_error = :e WHERE id = :id",
                    )->execute(['e' => 'Monthly ' . $channel . ' limit reached.', 'id' => $id]);

                    return;
                }
            }
            $payload = json_decode((string) $job['payload'], true, 512, JSON_THROW_ON_ERROR);
            $rendered = $this->renderer->render(
                (int) $job['tenant_id'],
                (string) $job['channel'],
                (string) $job['locale'],
                is_array($payload) ? $payload : [],
            );

            if ($job['channel'] === 'email') {
                $this->mailer->send(new MailMessage(
                    (string) $job['recipient'],
                    $rendered['subject'],
                    $rendered['body'],
                ));
            } else {
                $this->channels->send(
                    (int) $job['tenant_id'],
                    (string) $job['channel'],
                    (string) $job['recipient'],
                    $rendered['body'],
                );
            }

            $this->db->pdo()->prepare(
                "UPDATE notification_queue SET status = 'sent', sent_at = UTC_TIMESTAMP(), last_error = NULL WHERE id = :id",
            )->execute(['id' => $id]);
        } catch (RecipientNotAllowedException $e) {
            // Permanent: retrying cannot succeed. Park it without burning attempts.
            $this->db->pdo()->prepare(
                "UPDATE notification_queue SET status = 'skipped', last_error = :e WHERE id = :id",
            )->execute(['e' => mb_substr($e->getMessage(), 0, 500), 'id' => $id]);
        } catch (\Throwable $e) {
            $this->fail($id, $e->getMessage());
        }
    }

    private function fail(int $id, string $error): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT attempts FROM notification_queue WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $attempts = (int) $stmt->fetchColumn() + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->db->pdo()->prepare(
                "UPDATE notification_queue SET status = 'failed', attempts = :a, last_error = :e WHERE id = :id",
            )->execute(['a' => $attempts, 'e' => mb_substr($error, 0, 500), 'id' => $id]);

            return;
        }

        $this->db->pdo()->prepare(
            "UPDATE notification_queue SET status = 'pending', attempts = :a, last_error = :e,
                    scheduled_at = :retry WHERE id = :id",
        )->execute([
            'a' => $attempts,
            'e' => mb_substr($error, 0, 500),
            'retry' => gmdate('Y-m-d H:i:s', time() + self::RETRY_SECONDS * $attempts),
            'id' => $id,
        ]);
    }
}
