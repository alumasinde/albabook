<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Command;
use App\Console\Output;
use App\Core\Config\Config;
use App\Core\Database\Migrations\MigrationRunner;

/** Drop everything and re-run all migrations. Refuses outside local/testing. */
final class MigrateFreshCommand implements Command
{
    public function __construct(
        private readonly MigrationRunner $runner,
        private readonly Config $config,
    ) {
    }

    public function name(): string
    {
        return 'migrate:fresh';
    }

    public function description(): string
    {
        return 'Drop all tables and re-run migrations (local/testing only)';
    }

    public function run(array $args, Output $output): int
    {
        $env = $this->config->string('app.env');
        if (!in_array($env, ['local', 'testing'], true)) {
            $output->error("migrate:fresh is disabled in the \"{$env}\" environment.");

            return 1;
        }

        $database = $this->config->string('database.database');
        $output->warn("Dropping every table in \"{$database}\"…");
        $this->runner->dropAll();
        $applied = $this->runner->migrate(static fn (string $version) => $output->line("  applied  {$version}"));
        $output->success(sprintf('Rebuilt with %d migration(s).', count($applied)));

        return 0;
    }
}
