<?php

declare(strict_types=1);

namespace App\Console;

final class Output
{
    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function __construct($stdout = null, $stderr = null)
    {
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
    }

    public function line(string $text = ''): void
    {
        fwrite($this->stdout, $text . PHP_EOL);
    }

    public function success(string $text): void
    {
        $this->line("\033[32m{$text}\033[0m");
    }

    public function warn(string $text): void
    {
        fwrite($this->stderr, "\033[33m{$text}\033[0m" . PHP_EOL);
    }

    public function error(string $text): void
    {
        fwrite($this->stderr, "\033[31m{$text}\033[0m" . PHP_EOL);
    }
}
