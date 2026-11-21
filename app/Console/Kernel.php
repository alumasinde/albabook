<?php

declare(strict_types=1);

namespace App\Console;

use App\Console\Commands\AuditDailyCommand;
use App\Console\Commands\DbSeedCommand;
use App\Console\Commands\DemoSeedCommand;
use App\Console\Commands\DownCommand;
use App\Console\Commands\LangCheckCommand;
use App\Console\Commands\MigrateCommand;
use App\Console\Commands\MigrateFreshCommand;
use App\Console\Commands\MigrateStatusCommand;
use App\Console\Commands\PlatformAdminCommand;
use App\Console\Commands\UpCommand;
use App\Core\Container;
use App\Shared\Support\TokenGenerator;

/**
 * Minimal command dispatcher. symfony/console can replace it when commands
 * need richer input handling (plan.md §3.4).
 */
final class Kernel
{
    /** @var list<class-string<Command>> */
    private const COMMANDS = [
        AuditDailyCommand::class,
        MigrateCommand::class,
        MigrateStatusCommand::class,
        MigrateFreshCommand::class,
        DbSeedCommand::class,
        DemoSeedCommand::class,
        LangCheckCommand::class,
        PlatformAdminCommand::class,
        DownCommand::class,
        UpCommand::class,
    ];

    public function __construct(private readonly Container $container) {}

    /** @param list<string> $args */
    public function run(array $args, ?Output $output = null): int
    {
        $output ??= new Output();
        $name = $args[0] ?? 'list';

        $commands = [];
        foreach (self::COMMANDS as $class) {
            $command = $this->container->get($class);
            $commands[$command->name()] = $command;
        }

        if ($name === 'list' || $name === 'help') {
            $output->line('AlbaBook console');
            $output->line();
            $output->line('  key:generate [--show]  Write a new APP_KEY to .env (or print it)');
            foreach ($commands as $command) {
                $output->line(sprintf('  %-22s %s', $command->name(), $command->description()));
            }

            return 0;
        }

        if (!isset($commands[$name])) {
            $output->error("Unknown command \"{$name}\". Run `php bin/console list`.");

            return 64; // EX_USAGE
        }

        try {
            return $commands[$name]->run(array_slice($args, 1), $output);
        } catch (\Throwable $e) {
            $output->error($e->getMessage());

            return 1;
        }
    }

    public static function generateKey(string $basePath, bool $showOnly): int
    {
        $key = TokenGenerator::generateAppKey();
        if ($showOnly) {
            fwrite(STDOUT, $key . PHP_EOL);

            return 0;
        }

        $envFile = $basePath . '/.env';
        if (!is_file($envFile)) {
            if (!copy($basePath . '/.env.example', $envFile)) {
                fwrite(STDERR, "Could not create .env from .env.example.\n");

                return 1;
            }
        }
        $contents = (string) file_get_contents($envFile);
        if (preg_match('/^APP_KEY=.+$/m', $contents) === 1) {
            fwrite(STDERR, "APP_KEY is already set in .env; refusing to overwrite it (it would invalidate CSRF tokens and encrypted data). Use --show to print a new key.\n");

            return 1;
        }
        $contents = preg_match('/^APP_KEY=$/m', $contents) === 1
            ? (string) preg_replace('/^APP_KEY=$/m', 'APP_KEY=' . $key, $contents)
            : rtrim($contents) . PHP_EOL . 'APP_KEY=' . $key . PHP_EOL;
        file_put_contents($envFile, $contents);
        fwrite(STDOUT, "APP_KEY written to .env\n");

        return 0;
    }
}
