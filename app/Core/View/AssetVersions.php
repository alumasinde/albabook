<?php

declare(strict_types=1);

namespace App\Core\View;

use App\Core\Http\HostGroup;

/**
 * Cache-busting asset URLs: /assets/css/app.css?v=<content hash>.
 *
 * Versions come from public/assets/manifest.json, written once by
 * `npm run build` (scripts/build-assets.mjs) — a single small file read per
 * request instead of hashing every referenced asset. A page references ~30
 * assets; re-hashing them on every request was slow wherever file reads are
 * expensive (antivirus scanning on Windows) and could exceed the execution
 * time limit. Assets missing from the manifest (a stale build, or files added
 * since) fall back to a cheap mtime+size stamp — still a correct cache-buster.
 *
 * The booking host serves assets under /_/assets so they can never collide
 * with a business slug (D-005).
 */
final class AssetVersions
{
    /** @var array<string, string> */
    private array $versions = [];

    /** @var array<string, string>|null */
    private ?array $manifest = null;

    public function __construct(private readonly string $publicAssetsPath) {}

    public function url(string $path, ?HostGroup $group): string
    {
        $path = ltrim($path, '/');
        if (str_contains($path, '..')) {
            throw new \InvalidArgumentException('Asset paths must not contain "..".');
        }

        $prefix = $group?->assetPrefix() ?? '/assets';
        $version = $this->versions[$path] ??= $this->version($path);

        return $prefix . '/' . $path . ($version !== '' ? '?v=' . $version : '');
    }

    private function version(string $path): string
    {
        $manifest = $this->manifest ??= $this->loadManifest();
        if (isset($manifest[$path])) {
            return $manifest[$path];
        }

        // Not in the manifest: stat only, never read the file.
        $file = $this->publicAssetsPath . '/' . $path;
        $mtime = @filemtime($file);
        if ($mtime === false) {
            return '';
        }

        return dechex($mtime) . dechex((int) @filesize($file));
    }

    /** @return array<string, string> */
    private function loadManifest(): array
    {
        $raw = @file_get_contents($this->publicAssetsPath . '/manifest.json');
        if ($raw === false) {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }

        return array_filter($data, static fn(mixed $v, mixed $k): bool => is_string($k) && is_string($v), ARRAY_FILTER_USE_BOTH);
    }
}
