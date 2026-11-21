<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Command;
use App\Console\Output;
use App\Shared\Support\MaintenanceMode;

final class UpCommand implements Command
{
    public function __construct(private readonly MaintenanceMode $maintenance)
    {
    }

    public function name(): string
    {
        return 'up';
    }

    public function description(): string
    {
        return 'Disable maintenance mode';
    }

    public function run(array $args, Output $output): int
    {
        $this->maintenance->disable();
        $output->success('Maintenance mode off.');

        return 0;
    }
}
