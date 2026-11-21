<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Command;
use App\Console\Output;
use App\Core\Database\Migrations\MigrationRunner;
use App\Core\Database\Migrations\MigrationStatus;

final class MigrateStatusCommand implements Command
{
    public function __construct(private readonly MigrationRunner $runner)
    {
    }

    public function name(): string
    {
        return 'migrate:status';
    }

    public function description(): string
    {
        return 'Show applied, pending and modified migrations';
    }

    public function run(array $args, Output $output): int
    {
        $problems = 0;
        foreach ($this->runner->status() as $status) {
            $output->line(sprintf('  %-9s %-40s %s', $status->state, $status->version, $status->appliedAt ?? ''));
            if (in_array($status->state, [MigrationStatus::MODIFIED, MigrationStatus::MISSING], true)) {
                $problems++;
            }
        }
        if ($problems > 0) {
            $output->error("{$problems} applied migration(s) changed or are missing. Applied migrations are immutable.");

            return 1;
        }

        return 0;
    }
}
