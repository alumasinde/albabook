<?php

declare(strict_types=1);

namespace App\Shared\Money;

/**
 * The only place money becomes text, and text becomes money (D-006).
 * Pure integer/string arithmetic: no float ever touches an amount.
 */
final class CurrencyFormatter
{
    public function __construct(private readonly CurrencyCatalog $catalog)
    {
    }

    /**
     * "KSh 38,500" / "KSh 1,250.50". Whole amounts drop zero decimals unless
     * $alwaysShowDecimals is set (receipts, payment records).
     */
    public function format(Money $money, bool $alwaysShowDecimals = false, bool $withSymbol = true): string
    {
        $currency = $this->catalog->get($money->currency);
        $negative = $money->amountMinor < 0;
        $digits = (string) abs($money->amountMinor);

        if ($currency->exponent > 0) {
            $digits = str_pad($digits, $currency->exponent + 1, '0', STR_PAD_LEFT);
            $whole = substr($digits, 0, -$currency->exponent);
            $fraction = substr($digits, -$currency->exponent);
        } else {
            $whole = $digits;
            $fraction = '';
        }

        $text = self::groupThousands($whole);
        if ($fraction !== '' && ($alwaysShowDecimals || trim($fraction, '0') !== '')) {
            $text .= '.' . $fraction;
        }
        if ($withSymbol) {
            $text = $currency->symbol . ' ' . $text;
        }

        return ($negative ? '-' : '') . $text;
    }

    /**
     * Parse user input such as "1,500", "1500.5" or "1500.50" into minor units.
     *
     * @throws \InvalidArgumentException for malformed input or too many decimals
     */
    public function parse(string $input, string $currencyCode): Money
    {
        $currency = $this->catalog->get($currencyCode);
        $value = str_replace([',', ' ', "\u{00A0}"], '', trim($input));
        if (preg_match('/^(-)?(\d+)(?:\.(\d+))?$/', $value, $m) !== 1) {
            throw new \InvalidArgumentException('Enter an amount like 1500 or 1,500.50.');
        }
        $fraction = $m[3] ?? '';
        if (strlen($fraction) > $currency->exponent) {
            throw new \InvalidArgumentException("{$currencyCode} allows at most {$currency->exponent} decimal places.");
        }

        $minorDigits = ltrim($m[2] . str_pad($fraction, $currency->exponent, '0'), '0');
        if ($minorDigits === '') {
            $minorDigits = '0';
        }
        if (strlen($minorDigits) > 18) {
            throw new \InvalidArgumentException('Amount is too large.');
        }
        $minor = (int) $minorDigits;

        return new Money($m[1] === '-' ? -$minor : $minor, $currencyCode);
    }

    private static function groupThousands(string $digits): string
    {
        return ltrim(strrev(implode(',', str_split(strrev($digits), 3))), ',');
    }
}
