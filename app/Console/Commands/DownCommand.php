<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Command;
use App\Console\Output;
use App\Shared\Support\MaintenanceMode;

final class DownCommand implements Command
{
    public function __construct(private readonly MaintenanceMode $maintenance) {}

    public function name(): string
    {
        return 'down';
    }

    public function description(): string
    {
        return 'Enable maintenance mode [--retry=seconds]';
    }

    public function run(array $args, Output $output): int
    {
        $retry = 300;
        foreach ($args as $arg) {
            if (preg_match('/^--retry=(\d+)$/', $arg, $m) === 1) {
                $retry = max(1, (int) $m[1]);
            }
        }
        $this->maintenance->enable($retry);
        $output->success("Maintenance mode on (Retry-After: {$retry}s). /health stays reachable.");

        return 0;
    }
}
