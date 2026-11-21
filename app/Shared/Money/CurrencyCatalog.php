<?php

declare(strict_types=1);

namespace App\Shared\Money;

interface CurrencyCatalog
{
    /** @throws \InvalidArgumentException for an unknown currency */
    public function get(string $code): Currency;
}
