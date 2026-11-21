<?php

declare(strict_types=1);

namespace App\Shared\Money;

final readonly class Currency
{
    public function __construct(
        public string $code,
        public string $symbol,
        public int $exponent,
    ) {}
}
