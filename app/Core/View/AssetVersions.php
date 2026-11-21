<?php

declare(strict_types=1);

namespace App\Core\View;

use App\Core\Http\HostGroup;

/**
 * Cache-busting asset URLs: /assets/css/app.css?v=<content hash>.
 * Hashes are computed once per process. The booking host serves assets under
 * /_/assets so they can never collide with a business slug (D-005).
 */
final class AssetVersions
{
    /** @var array<string, string> */
    private array $versions = [];

    public function __construct(private readonly string $publicAssetsPath)
    {
    }

    public function url(string $path, ?HostGroup $group): string
    {
        $path = ltrim($path, '/');
        if (str_contains($path, '..')) {
            throw new \InvalidArgumentException('Asset paths must not contain "..".');
        }

        $prefix = $group?->assetPrefix() ?? '/assets';
        $version = $this->versions[$path] ??= $this->hash($path);

        return $prefix . '/' . $path . ($version !== '' ? '?v=' . $version : '');
    }

    private function hash(string $path): string
    {
        $file = $this->publicAssetsPath . '/' . $path;

        return is_file($file) ? substr((string) hash_file('xxh128', $file), 0, 12) : '';
    }
}
