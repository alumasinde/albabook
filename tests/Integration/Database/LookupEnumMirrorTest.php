<?php

declare(strict_types=1);

namespace Tests\Integration\Database;

use App\Shared\Enums\AppointmentStatus;
use App\Shared\Enums\TenantStatus;
use Tests\Support\DatabaseTestCase;

/**
 * decisions.md D-023: where code branches on a lookup table, the PHP enum
 * must match the seeded rows exactly.
 */
final class LookupEnumMirrorTest extends DatabaseTestCase
{
    public function testTenantStatusEnumMatchesLookupTable(): void
    {
        $rows = $this->pdo->query('SELECT code, is_terminal FROM tenant_statuses ORDER BY sort_position')->fetchAll();

        self::assertSame(array_map(static fn(TenantStatus $s): string => $s->value, TenantStatus::cases()), array_column($rows, 'code'));
        foreach ($rows as $row) {
            self::assertSame((bool) $row['is_terminal'], TenantStatus::from((string) $row['code'])->isTerminal(), (string) $row['code']);
        }
    }

    public function testAppointmentStatusEnumMatchesLookupTableIncludingBlocksTime(): void
    {
        $rows = $this->pdo->query('SELECT code, blocks_time, is_terminal FROM appointment_statuses ORDER BY sort_position')->fetchAll();

        self::assertSame(array_map(static fn(AppointmentStatus $s): string => $s->value, AppointmentStatus::cases()), array_column($rows, 'code'));
        foreach ($rows as $row) {
            $status = AppointmentStatus::from((string) $row['code']);
            self::assertSame((bool) $row['blocks_time'], $status->blocksTime(), "{$status->value} blocks_time");
            self::assertSame((bool) $row['is_terminal'], $status->isTerminal(), "{$status->value} is_terminal");
        }
    }

    public function testLocaleConfigMatchesLocalesTable(): void
    {
        $config = require dirname(__DIR__, 3) . '/config/i18n.php';
        $customer = $this->pdo->query('SELECT code FROM locales WHERE is_active AND is_customer_facing ORDER BY sort_position')->fetchAll(\PDO::FETCH_COLUMN);
        $dashboard = $this->pdo->query('SELECT code FROM locales WHERE is_active AND is_dashboard ORDER BY sort_position')->fetchAll(\PDO::FETCH_COLUMN);

        self::assertSame($config['customer_locales'], $customer);
        self::assertSame($config['dashboard_locales'], $dashboard);
    }

    public function testEveryAppointmentStatusHasEnglishAndSwahiliLabels(): void
    {
        $en = require dirname(__DIR__, 3) . '/lang/en/lookup.php';
        $sw = require dirname(__DIR__, 3) . '/lang/sw/lookup.php';
        foreach (AppointmentStatus::cases() as $status) {
            self::assertArrayHasKey($status->value, $en['appointment_status']);
            self::assertArrayHasKey($status->value, $sw['appointment_status']);
        }
    }

    public function testBookingPolicyDefaultsCoverEveryPolicyColumn(): void
    {
        $defaults = require dirname(__DIR__, 3) . '/config/booking.php';
        $columns = $this->pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'booking_policies' AND IS_NULLABLE = 'YES'
               AND EXTRA NOT LIKE '%GENERATED%'
               AND COLUMN_NAME NOT IN ('branch_id', 'deposit_value')",
        )->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($columns as $column) {
            self::assertArrayHasKey($column, $defaults, "config/booking.php must define a platform default for booking_policies.{$column}");
        }
    }
}
