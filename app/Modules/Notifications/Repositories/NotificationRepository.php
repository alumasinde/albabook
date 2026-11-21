<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Repositories;

use App\Core\Database\Database;

/**
 * Tenant-scoped reads for the notifications center: the outbound queue,
 * delivery logs and channel reference data.
 */
final class NotificationRepository
{
    private const PER_PAGE = 20;

    public function __construct(private readonly Database $db) {}

    /** Queue + log rows, newest first, with optional filters.
     * @param array{q?: string, channel?: string, status?: string, page?: int} $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function listForTenant(int $tenantId, array $filters): array
    {
        $where = ['tenant_id = :tid'];
        $params = ['tid' => $tenantId];

        $channel = trim((string) ($filters['channel'] ?? ''));
        if ($channel !== '') {
            $where[] = 'channel = :ch';
            $params['ch'] = $channel;
        }
        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $where[] = 'status = :st';
            $params['st'] = $status;
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = 'recipient LIKE :q';
            $params['q'] = '%' . $q . '%';
        }
        $whereSql = implode(' AND ', $where);

        $countStmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM notification_queue WHERE {$whereSql}",
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $page = max(1, (int) ($filters['page'] ?? 1));
        $offset = ($page - 1) * self::PER_PAGE;
        $stmt = $this->db->pdo()->prepare(
            "SELECT uuid, channel, recipient, payload, status, scheduled_at,
                    attempts, last_error, created_at, sent_at
             FROM notification_queue
             WHERE {$whereSql}
             ORDER BY created_at DESC
             LIMIT :limit OFFSET :offset",
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', self::PER_PAGE, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return ['rows' => array_values($stmt->fetchAll()), 'total' => $total];
    }

    /** Summary counters for the stat cards.
     * @return array<string, int>
     */
    public function stats(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT
                SUM(status IN ('pending','processing')) AS queued,
                SUM(status = 'sent' AND created_at >= CURRENT_DATE) AS sent_today,
                SUM(status = 'failed') AS failed
             FROM notification_queue WHERE tenant_id = ?",
        );
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch();

        return [
            'queued' => (int) ($row['queued'] ?? 0),
            'sent_today' => (int) ($row['sent_today'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
        ];
    }

    /** @return array<string, mixed>|null */
    public function findByUuid(int $tenantId, string $uuid): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, status FROM notification_queue WHERE tenant_id = ? AND uuid = ?',
        );
        $stmt->execute([$tenantId, $uuid]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** Reset a failed notification to pending for another attempt. */
    public function requeue(int $tenantId, int $id): void
    {
        $this->db->pdo()->prepare(
            "UPDATE notification_queue
             SET status = 'pending', last_error = NULL, scheduled_at = NOW()
             WHERE tenant_id = ? AND id = ? AND status = 'failed'",
        )->execute([$tenantId, $id]);
    }

    public function cancel(int $tenantId, int $id): void
    {
        $this->db->pdo()->prepare(
            "UPDATE notification_queue
             SET status = 'cancelled'
             WHERE tenant_id = ? AND id = ? AND status IN ('pending','processing')",
        )->execute([$tenantId, $id]);
    }

    /** @return list<array{code: string, name: string}> */
    public function channels(): array
    {
        $stmt = $this->db->pdo()->query('SELECT code, name FROM communication_channels ORDER BY code');

        /** @var list<array{code: string, name: string}> */
        return $stmt === false ? [] : array_values($stmt->fetchAll());
    }
}
