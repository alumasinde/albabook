<?php

declare(strict_types=1);

namespace App\Shared\Support;

final class MaintenanceMode
{
    public function __construct(private readonly string $flagFile) {}

    public function isActive(): bool
    {
        return is_file($this->flagFile);
    }

    public function retryAfterSeconds(): int
    {
        $data = $this->read();
        $retry = $data['retry_after'] ?? 300;

        return is_int($retry) && $retry > 0 ? $retry : 300;
    }

    public function enable(int $retryAfterSeconds = 300): void
    {
        $dir = dirname($this->flagFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($this->flagFile, json_encode([
            'since' => gmdate('c'),
            'retry_after' => $retryAfterSeconds,
        ], JSON_THROW_ON_ERROR));
    }

    public function disable(): void
    {
        if (is_file($this->flagFile)) {
            unlink($this->flagFile);
        }
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        if (!is_file($this->flagFile)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($this->flagFile), true);

        return is_array($decoded) ? $decoded : [];
    }
}
