<?php

declare(strict_types=1);

namespace App\Shared\Money;

/**
 * Integer minor units + ISO 4217 code (decisions.md D-006).
 * There is deliberately no float constructor or accessor.
 */
final readonly class Money
{
    public function __construct(
        public int $amountMinor,
        public string $currency,
    ) {
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new \InvalidArgumentException('Currency must be an ISO 4217 code.');
        }
    }

    public static function zero(string $currency): self
    {
        return new self(0, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amountMinor + $other->amountMinor, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amountMinor - $other->amountMinor, $this->currency);
    }

    public function multiply(int $quantity): self
    {
        return new self($this->amountMinor * $quantity, $this->currency);
    }

    /**
     * Share of this amount in basis points (1600 = 16%), rounded half up.
     * Integer-only, so no float rounding can creep into tax or deposits.
     */
    public function percentageBasisPoints(int $basisPoints): self
    {
        return new self(intdiv($this->amountMinor * $basisPoints + 5000, 10000), $this->currency);
    }

    /**
     * Tax contained in a tax-inclusive amount: round(gross × rate / (10000 + rate)),
     * half up (booking-engine.md §13, decisions.md D-015).
     */
    public function inclusiveTaxBasisPoints(int $basisPoints): self
    {
        if ($basisPoints <= 0) {
            return self::zero($this->currency);
        }
        $denominator = 10000 + $basisPoints;

        return new self(intdiv(2 * $this->amountMinor * $basisPoints + $denominator, 2 * $denominator), $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amountMinor === $other->amountMinor;
    }

    public function isNegative(): bool
    {
        return $this->amountMinor < 0;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException("Cannot combine {$this->currency} with {$other->currency}.");
        }
    }
}
