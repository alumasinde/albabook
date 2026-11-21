<?php

declare(strict_types=1);

namespace App\Shared\Support;

use App\Core\Http\HostGroup;

/**
 * Fixed allowlist of platform hosts. Verified tenant custom domains
 * (plan.md §24) will be resolved to HostGroup::Booking by a later lookup;
 * until then any other host is unknown.
 */
final class HostMap
{
    /** @var array<string, HostGroup> */
    private array $byHost = [];

    /** @param array<string, string> $hosts HostGroup value => hostname */
    public function __construct(array $hosts)
    {
        foreach ($hosts as $group => $host) {
            $this->byHost[strtolower($host)] = HostGroup::from($group);
        }
    }

    public function groupFor(string $host): ?HostGroup
    {
        return $this->byHost[$host] ?? null;
    }

    public function hostFor(HostGroup $group): string
    {
        $host = array_search($group, $this->byHost, true);

        return is_string($host) ? $host : '';
    }
}
