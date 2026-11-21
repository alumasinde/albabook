<?php

declare(strict_types=1);

namespace App\Core\Config;

final class ConfigurationException extends \RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct("Invalid configuration:\n - " . implode("\n - ", $errors));
    }
}
