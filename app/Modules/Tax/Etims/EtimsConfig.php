<?php

declare(strict_types=1);

namespace App\Modules\Tax\Etims;

/**
 * A business's eTIMS setup (tenant_settings.preferences.etims + tenants.kra_pin).
 *
 *   enabled           issue eTIMS invoices at checkout
 *   environment       sandbox | production
 *   tin               the business KRA PIN (tenants.kra_pin)
 *   bhf_id            KRA branch id ("00" = head office)
 *   device_serial     OSCU device serial issued by KRA at registration
 *   cmc_key           communication key from device initialization (encrypted at rest)
 *   item_class_code   KRA item classification code used for services/products
 *   pkg_unit / qty_unit   KRA packaging / quantity unit codes for item codes
 *   zero_rate_tax_type    tax type for 0% lines: A (exempt) or C (zero-rated)
 */
final readonly class EtimsConfig
{
    /** KRA tax types: A exempt, B 16% standard, C zero-rated, D non-VAT, E 8%. */
    public const TAX_TYPES = ['A' => 0, 'B' => 16, 'C' => 0, 'D' => 0, 'E' => 8];

    public function __construct(
        public bool $enabled,
        public string $environment,
        public string $tin,
        public string $bhfId,
        public string $deviceSerial,
        public string $cmcKey,
        public string $itemClassCode,
        public string $pkgUnit,
        public string $qtyUnit,
        public string $zeroRateTaxType,
    ) {}

    /** Ready to submit: switched on and initialized with KRA. */
    public function ready(): bool
    {
        return $this->enabled && $this->tin !== '' && $this->cmcKey !== '' && $this->itemClassCode !== '';
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return ['tin' => $this->tin, 'bhfId' => $this->bhfId, 'cmcKey' => $this->cmcKey];
    }

    /** KRA tax type for a VAT rate in basis points. */
    public function taxType(int $rateBp): string
    {
        return match ($rateBp) {
            1600 => 'B',
            800 => 'E',
            0 => $this->zeroRateTaxType,
            default => throw new EtimsException(sprintf('VAT rate %.2f%% has no eTIMS tax type (use 16%%, 8%% or 0%%).', $rateBp / 100)),
        };
    }
}
