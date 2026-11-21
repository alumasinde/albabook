<?php

declare(strict_types=1);

namespace App\Modules\System\Services;

use App\Core\Database\Database;
use App\Core\Database\Migrations\MigrationRunner;
use App\Core\Database\Migrations\MigrationStatus;
use App\Shared\Support\MaintenanceMode;
use Psr\Log\LoggerInterface;

final class HealthCheck
{
    public function __construct(
        private readonly Database $db,
        private readonly MigrationRunner $migrations,
        private readonly MaintenanceMode $maintenance,
        private readonly LoggerInterface $logger,
        private readonly string $basePath,
    ) {
    }

    /** @return array{status: string, checks: array<string, string>} */
    public function run(): array
    {
        $checks = [
            'database' => $this->check('database', fn (): bool => (int) $this->db->pdo()->query('SELECT 1')->fetchColumn() === 1),
            'migrations' => $this->check('migrations', function (): bool {
                foreach ($this->migrations->status() as $status) {
                    if ($status->state !== MigrationStatus::APPLIED) {
                        return false;
                    }
                }

                return true;
            }),
            'storage' => $this->check('storage', fn (): bool => is_writable($this->basePath . '/storage/logs') && is_writable($this->basePath . '/storage/cache')),
        ];

        $healthy = !in_array('fail', $checks, true);
        if ($this->maintenance->isActive()) {
            $checks['maintenance'] = 'on';
        }

        return ['status' => $healthy && !$this->maintenance->isActive() ? 'ok' : 'degraded', 'checks' => $checks];
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
}
