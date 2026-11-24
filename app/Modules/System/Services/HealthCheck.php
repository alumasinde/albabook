<?php

declare(strict_types=1);

namespace App\Modules\System\Services;

use App\Core\Database\Database;
use App\Core\Database\Migrations\MigrationRunner;
use App\Core\Database\Migrations\MigrationStatus;
use App\Modules\System\Repositories\PlatformStateRepository;
use App\Shared\Support\MaintenanceMode;
use Psr\Log\LoggerInterface;

final class HealthCheck
{
    public function __construct(
        private readonly Database $db,
        private readonly MigrationRunner $migrations,
        private readonly MaintenanceMode $maintenance,
        private readonly PlatformStateRepository $state,
        private readonly LoggerInterface $logger,
        private readonly string $basePath,
        private readonly int $schedulerMaxLagSeconds = 600,
        private readonly int $backupMaxAgeSeconds = 93600,
    ) {}

    /**
     * checks: probes that mean this node cannot serve traffic (they gate the
     * HTTP status). signals: freshness of out-of-request work — informational
     * only, so a stale cron or backup never makes a load balancer drain an
     * otherwise healthy node.
     *
     * @return array{status: string, checks: array<string, string>, signals: array<string, string|array<string, string>>}
     */
    public function run(): array
    {
        $checks = [
            'database' => $this->check('database', function (): bool {
                $statement = $this->db->pdo()->query('SELECT 1');

                return $statement !== false && (int) $statement->fetchColumn() === 1;
            }),
            'migrations' => $this->check('migrations', function (): bool {
                foreach ($this->migrations->status() as $status) {
                    if ($status->state !== MigrationStatus::APPLIED) {
                        return false;
                    }
                }

                return true;
            }),
            'storage' => $this->check('storage', fn(): bool => is_writable($this->basePath . '/storage/logs') && is_writable($this->basePath . '/storage/cache')),
        ];

        $signals = [
            'scheduler' => $this->signalFreshness(PlatformStateRepository::SCHEDULER_LAST_RUN, $this->schedulerMaxLagSeconds),
            'backups' => $this->signalFreshness(PlatformStateRepository::BACKUP_LAST_OK, $this->backupMaxAgeSeconds),
        ];

        // Supervised workers (workers/*.php) heartbeat as worker.{name}.last_run_at.
        // Deployments that use cron only simply have no worker keys.
        $workers = [];
        foreach ($this->state->allLike('worker.') as $name => $value) {
            $short = preg_replace('/^worker\.|\.last_run_at$/', '', $name);
            $workers[$short ?? $name] = $this->freshnessValue($value, $this->schedulerMaxLagSeconds);
        }
        if ($workers !== []) {
            $signals['workers'] = $workers;
        }

        $healthy = !in_array('fail', $checks, true);
        if ($this->maintenance->isActive()) {
            $checks['maintenance'] = 'on';
        }

        return [
            'status' => $healthy && !$this->maintenance->isActive() ? 'ok' : 'degraded',
            'checks' => $checks,
            'signals' => $signals,
        ];
    }

    /** @param callable(): bool $probe */
    private function check(string $name, callable $probe): string
    {
        try {
            return $probe() ? 'ok' : 'fail';
        } catch (\Throwable $e) {
            $this->logger->error("Health check \"{$name}\" failed", ['exception' => $e]);

            return 'fail';
        }
    }

    private function signalFreshness(string $name, int $maxAgeSeconds): string
    {
        try {
            $raw = $this->state->get($name);
        } catch (\Throwable $e) {
            $this->logger->error("Health signal \"{$name}\" unreadable", ['exception' => $e]);

            return 'unknown';
        }

        return $raw === null ? 'unknown' : $this->freshnessValue($raw, $maxAgeSeconds);
    }

    private function freshnessValue(string $raw, int $maxAgeSeconds): string
    {
        // platform_state values are UTC_TIMESTAMP(); parse them as UTC
        // regardless of the server's default timezone.
        try {
            $timestamp = (new \DateTimeImmutable($raw, new \DateTimeZone('UTC')))->getTimestamp();
        } catch (\Throwable) {
            return 'unknown';
        }

        return (time() - $timestamp) <= $maxAgeSeconds ? 'fresh' : 'stale';
    }
}
