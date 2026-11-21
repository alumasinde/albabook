<?php

declare(strict_types=1);

namespace App\Shared\Money;

/** In-memory catalog for tests and tooling that run without a database. */
final class ArrayCurrencyCatalog implements CurrencyCatalog
{
    /** @var array<string, Currency> */
    private array $currencies = [];

    /** @param list<Currency> $currencies */
    public function __construct(array $currencies)
    {
        foreach ($currencies as $currency) {
            $this->currencies[$currency->code] = $currency;
        }
    }

    public function get(string $code): Currency
    {
        return $this->currencies[$code] ?? throw new \InvalidArgumentException("Unknown currency {$code}.");
    }
}
