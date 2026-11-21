<?php

declare(strict_types=1);

namespace App\Console;

interface Command
{
    public function name(): string;

    public function description(): string;

    /** @param list<string> $args */
    public function run(array $args, Output $output): int;
}
