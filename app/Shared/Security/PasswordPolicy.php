<?php

declare(strict_types=1);

namespace App\Shared\Security;

use App\Core\Config\Config;

/**
 * Minimum 12 characters, maximum 128, no composition rules, and a bundled
 * top-10,000 denylist (auth.md §8b).
 */
final class PasswordPolicy
{
    /** @var array<string, true>|null */
    private ?array $common = null;

    private readonly string $listPath;

    public function __construct(
        private readonly Config $config,
        string $basePath,
    ) {
        $this->listPath = $basePath . '/' . $config->string('auth.password.common_passwords_path');
    }

    /** @return list<string> human-readable problems; empty means accepted */
    public function violations(string $password): array
    {
        $errors = [];
        $length = mb_strlen($password);
        $min = $this->config->int('auth.password.min_length', 12);
        $max = $this->config->int('auth.password.max_length', 128);

        if ($length < $min) {
            $errors[] = "Password must be at least {$min} characters.";
        }
        if ($length > $max) {
            $errors[] = "Password must be at most {$max} characters.";
        }
        if ($errors === [] && isset($this->commonPasswords()[mb_strtolower($password)])) {
            $errors[] = 'This password is too common. Choose something less predictable.';
        }

        return $errors;
    }

    /** @return array<string, true> */
    private function commonPasswords(): array
    {
        if ($this->common !== null) {
            return $this->common;
        }

        $this->common = [];
        $handle = @fopen($this->listPath, 'rb');
        if ($handle === false) {
            return $this->common;
        }
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line !== '') {
                $this->common[mb_strtolower($line)] = true;
            }
        }
        fclose($handle);

        return $this->common;
    }
}
