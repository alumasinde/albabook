<?php

declare(strict_types=1);

namespace App\Shared\Money;

use App\Core\Database\Database;

/**
 * Reads the `currencies` lookup table once per process (decisions.md D-023).
 */
final class DatabaseCurrencyCatalog implements CurrencyCatalog
{
    /** @var array<string, Currency>|null */
    private ?array $currencies = null;

    public function __construct(private readonly Database $db)
    {
    }

    public function get(string $code): Currency
    {
        $this->currencies ??= $this->load();

        return $this->currencies[$code] ?? throw new \InvalidArgumentException("Unknown currency {$code}.");
    }

    /** @return array<string, Currency> */
    private function load(): array
    {
        $rows = $this->db->pdo()->query('SELECT code, symbol, minor_unit_exponent FROM currencies')->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['code']] = new Currency((string) $row['code'], (string) $row['symbol'], (int) $row['minor_unit_exponent']);
        }

        return $result;
    }
}
