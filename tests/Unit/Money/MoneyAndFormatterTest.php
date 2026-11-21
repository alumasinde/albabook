<?php

declare(strict_types=1);

namespace Tests\Unit\Money;

use App\Shared\Money\ArrayCurrencyCatalog;
use App\Shared\Money\Currency;
use App\Shared\Money\CurrencyFormatter;
use App\Shared\Money\Money;
use PHPUnit\Framework\TestCase;

final class MoneyAndFormatterTest extends TestCase
{
    private CurrencyFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new CurrencyFormatter(new ArrayCurrencyCatalog([
            new Currency('KES', 'KSh', 2),
            new Currency('UGX', 'USh', 0),
        ]));
    }

    public function testFormatsWholeShillingsWithoutDecimals(): void
    {
        self::assertSame('KSh 38,500', $this->formatter->format(new Money(3850000, 'KES')));
    }

    public function testKeepsNonZeroCents(): void
    {
        self::assertSame('KSh 1,250.50', $this->formatter->format(new Money(125050, 'KES')));
    }

    public function testCanAlwaysShowDecimals(): void
    {
        self::assertSame('KSh 1,500.00', $this->formatter->format(new Money(150000, 'KES'), alwaysShowDecimals: true));
    }

    public function testZeroExponentCurrency(): void
    {
        self::assertSame('USh 1,234,567', $this->formatter->format(new Money(1234567, 'UGX')));
    }

    public function testSmallAndNegativeAmounts(): void
    {
        self::assertSame('KSh 0.05', $this->formatter->format(new Money(5, 'KES')));
        self::assertSame('-KSh 2,000', $this->formatter->format(new Money(-200000, 'KES')));
    }

    public function testParsesUserInputWithoutFloats(): void
    {
        self::assertSame(150050, $this->formatter->parse('1,500.5', 'KES')->amountMinor);
        self::assertSame(150000, $this->formatter->parse(' 1500 ', 'KES')->amountMinor);
        // 0.1 + 0.2 style inputs stay exact.
        self::assertSame(30, $this->formatter->parse('0.30', 'KES')->amountMinor);
        self::assertSame(5000, $this->formatter->parse('5000', 'UGX')->amountMinor);
    }

    public function testRejectsTooManyDecimalsAndJunk(): void
    {
        foreach (['1.505', 'abc', '1e5', '1.2.3', ''] as $input) {
            try {
                $this->formatter->parse($input, 'KES');
                self::fail("Expected rejection of \"{$input}\"");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->formatter->parse('100.5', 'UGX');
    }

    public function testArithmeticRequiresSameCurrency(): void
    {
        self::assertSame(300, (new Money(100, 'KES'))->add(new Money(200, 'KES'))->amountMinor);

        $this->expectException(\InvalidArgumentException::class);
        (new Money(100, 'KES'))->add(new Money(100, 'UGX'));
    }

    public function testInclusiveVatIsExtractedWithIntegerRounding(): void
    {
        // KSh 1,160.00 including 16% VAT contains exactly KSh 160.00 of tax.
        self::assertSame(16000, (new Money(116000, 'KES'))->inclusiveTaxBasisPoints(1600)->amountMinor);
        // KSh 1,000.00 incl. 16% → 137.931… → 137.93
        self::assertSame(13793, (new Money(100000, 'KES'))->inclusiveTaxBasisPoints(1600)->amountMinor);
        self::assertSame(0, (new Money(100000, 'KES'))->inclusiveTaxBasisPoints(0)->amountMinor);
    }

    public function testPercentageForDeposits(): void
    {
        // 30% deposit of KSh 2,500 = KSh 750
        self::assertSame(75000, (new Money(250000, 'KES'))->percentageBasisPoints(3000)->amountMinor);
    }

    public function testUnknownCurrencyFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->formatter->format(new Money(1, 'EUR'));
    }
}
