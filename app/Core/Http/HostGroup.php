<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * The AlbaBook interfaces (plan.md §2.1, decisions.md D-005), plus the
 * public marketing site on the bare domain. Each has its own routes and
 * middleware stack.
 */
enum HostGroup: string
{
    case Tenant = 'app';
    case Booking = 'book';
    case Api = 'api';
    case Admin = 'admin';
    case Marketing = 'site';

    public function usesCsrf(): bool
    {
        return $this !== self::Api;
    }

    public function prefersJson(): bool
    {
        return $this === self::Api;
    }

    /** URL prefix for static assets on this host (D-005: /_/ on the booking host). */
    public function assetPrefix(): string
    {
        return $this === self::Booking ? '/_/assets' : '/assets';
    }
}
