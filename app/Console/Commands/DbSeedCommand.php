<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Command;
use App\Console\Output;
use App\Core\Database\Seeders\DatabaseSeeder;

final class DbSeedCommand implements Command
{
    public function __construct(private readonly DatabaseSeeder $seeder) {}

    public function name(): string
    {
        return 'db:seed';
    }

    public function description(): string
    {
        return 'Seed permissions, role templates and tenant status policies';
    }

    public function run(array $args, Output $output): int
    {
        $counts = $this->seeder->run();

        $output->line(sprintf('  permissions      %d', $counts['permissions']));
        $output->line(sprintf('  role templates   %d', $counts['roles']));
        $output->line(sprintf('  policy rows      %d', $counts['policies']));
        $output->success('Reference data seeded.');

        return 0;
    }
}
