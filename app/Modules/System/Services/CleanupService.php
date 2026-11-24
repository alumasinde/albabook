<?php

declare(strict_types=1);

namespace App\Modules\System\Services;

use App\Core\Database\Database;
use App\Shared\Security\RateLimiter;
use App\Tenancy\TenantDomainsRepository;

/**
 * Periodic housekeeping run by cron/scheduler.php. Everything here is
 * bounded and idempotent so it is safe to run every minute.
 */
final class CleanupService
{
    public function __construct(
        private readonly Database $db,
        private readonly RateLimiter $limiter,
    ) {}

    /** Expire booking holds whose expires_at has passed. */
    public function expireHolds(): int
    {
        $stmt = $this->db->pdo()->exec(
            "UPDATE booking_holds SET status = 'expired', updated_at = UTC_TIMESTAMP()
              WHERE status = 'active' AND expires_at <= UTC_TIMESTAMP()",
        );

        return $stmt === false ? 0 : $stmt;
    }

    /** Expire pending OTP rows older than their expiry. */
    public function expireVerificationCodes(): int
    {
        $stmt = $this->db->pdo()->exec(
            "UPDATE booking_verification_codes SET consumed_at = UTC_TIMESTAMP()
              WHERE consumed_at IS NULL AND expires_at <= UTC_TIMESTAMP()",
        );

        return $stmt === false ? 0 : $stmt;
    }

    /** Return rows stuck in 'processing' past an hour to 'pending'. */
    public function recoverStuckNotifications(): int
    {
        $pdo = $this->db->pdo();
        $count = 0;
        foreach (['notification_queue', 'scheduled_notifications', 'outbox_events', 'webhook_deliveries'] as $table) {
            $stmt = $pdo->exec(
                "UPDATE {$table} SET status = 'pending'
                  WHERE status = 'processing' AND created_at < UTC_TIMESTAMP() - INTERVAL 1 HOUR",
            );
            $count += $stmt === false ? 0 : $stmt;
        }

        return $count;
    }

    /** Prune rate-limit buckets older than a day. */
    public function pruneRateLimits(): void
    {
        $this->limiter->pruneOlderThan(86400);
    }

    /** Lapsed unverified custom-domain claims (tenancy.domain_claim_ttl_hours). */
    public function pruneStaleDomainClaims(int $ttlHours = 72): int
    {
        return (new TenantDomainsRepository($this->db))->pruneStaleClaims($ttlHours);
    }
}
