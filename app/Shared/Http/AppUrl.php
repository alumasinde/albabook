<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Core\Config\Config;
use App\Core\Http\HostGroup;
use App\Core\Http\Request;

/**
 * Absolute URLs for links that leave the current request (emails, redirects
 * between host groups). Schemes follow the incoming request in local HTTP;
 * CLI-generated links default to HTTPS.
 */
final class AppUrl
{
    public function __construct(private readonly Config $config) {}

    public function to(HostGroup $group, string $path, ?Request $request = null): string
    {
        $host = $this->config->string('app.hosts.' . $group->value);
        $scheme = $request !== null ? ($request->isSecure() ? 'https' : 'http') : 'https';

        // All hosts are served by the same front end, so a non-standard port
        // on the current request (local dev, e.g. :8010) applies to the
        // target host too. Standard ports are omitted.
        $port = $request?->port();
        if ($port !== null && !($scheme === 'http' && $port === 80) && !($scheme === 'https' && $port === 443)) {
            $host .= ':' . $port;
        }

        return $scheme . '://' . $host . '/' . ltrim($path, '/');
    }

    public function app(string $path, ?Request $request = null): string
    {
        return $this->to(HostGroup::Tenant, $path, $request);
    }

    public function admin(string $path, ?Request $request = null): string
    {
        return $this->to(HostGroup::Admin, $path, $request);
    }
}
