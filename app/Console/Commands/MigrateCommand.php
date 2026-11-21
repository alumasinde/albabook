<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Command;
use App\Console\Output;
use App\Core\Database\Migrations\MigrationRunner;

final class MigrateCommand implements Command
{
    public function __construct(private readonly MigrationRunner $runner) {}

    public function name(): string
    {
        return 'migrate';
    }

    public function description(): string
    {
        return 'Apply pending database migrations';
    }

    public function run(array $args, Output $output): int
    {
        $applied = $this->runner->migrate(static fn(string $version) => $output->line("  applied  {$version}"));
        $output->success($applied === [] ? 'Nothing to migrate.' : sprintf('Applied %d migration(s).', count($applied)));

        return 0;
    }
}
