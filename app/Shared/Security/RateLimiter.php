<?php

declare(strict_types=1);

namespace App\Shared\Security;

use App\Core\Database\Database;
use App\Shared\Time\Clock;

/**
 * Fixed-window counters in rate_limit_buckets. Callers pass a key that already
 * names the action; personal identifiers should be hashed before they are used
 * as a bucket key.
 */
final class RateLimiter
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {}

    public function hit(string $key, int $limit, int $windowSeconds): RateLimitResult
    {
        $limit = max(1, $limit);
        $windowSeconds = max(1, $windowSeconds);
        $now = $this->clock->now()->getTimestamp();
        $windowStart = intdiv($now, $windowSeconds) * $windowSeconds;
        $start = gmdate('Y-m-d H:i:s', $windowStart);
        $pdo = $this->db->pdo();

        $pdo->prepare(
            'INSERT INTO rate_limit_buckets (bucket_key, window_start, count) VALUES (:k, :w, 1)
             ON DUPLICATE KEY UPDATE count = count + 1',
        )->execute(['k' => $key, 'w' => $start]);

        $stmt = $pdo->prepare('SELECT count FROM rate_limit_buckets WHERE bucket_key = :k AND window_start = :w');
        $stmt->execute(['k' => $key, 'w' => $start]);
        $count = (int) $stmt->fetchColumn();

        return new RateLimitResult(
            allowed: $count <= $limit,
            remaining: max(0, $limit - $count),
            retryAfterSeconds: max(0, $windowStart + $windowSeconds - $now),
        );
    }

    /** Housekeeping; call from a scheduled job in later phases. */
    public function pruneOlderThan(int $seconds): void
    {
        $cutoff = gmdate('Y-m-d H:i:s', $this->clock->now()->getTimestamp() - $seconds);
        $this->db->pdo()->prepare('DELETE FROM rate_limit_buckets WHERE window_start < :cutoff')
            ->execute(['cutoff' => $cutoff]);
    }
}
