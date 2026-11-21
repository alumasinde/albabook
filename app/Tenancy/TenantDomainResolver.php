<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Core\Config\Config;
use App\Core\Config\ConfigValidator;

/**
 * Normalises a custom domain to lowercase ASCII (IDNA) before lookup. The
 * platform hosts can never be claimed as tenant domains.
 */
final class TenantDomainResolver
{
    /** @var list<string> */
    private array $platformHosts;

    public function __construct(private readonly Config $config)
    {
        $this->platformHosts = array_values(array_filter($config->array('app.hosts'), 'is_string'));
    }

    public function normalise(string $domain): ?string
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return null;
        }
        $domain = (string) preg_replace('/:\d{1,5}$/', '', $domain);
        $domain = rtrim($domain, '.');

        if (str_contains($domain, '://') || str_contains($domain, '/')) {
            return null;
        }

        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7E]/', $domain) === 1) {
            $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                return null;
            }
            $domain = $ascii;
        }

        if (!ConfigValidator::isHostname($domain) || in_array($domain, $this->platformHosts, true)) {
            return null;
        }

        return $domain;
    }
}
