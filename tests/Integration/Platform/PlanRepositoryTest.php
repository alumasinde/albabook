<?php

declare(strict_types=1);

namespace Tests\Integration\Platform;

use App\Modules\Platform\Repositories\PlanRepository;
use Tests\Integration\Support\Phase2TestCase;

final class PlanRepositoryTest extends Phase2TestCase
{
    private PlanRepository $plans;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plans = new PlanRepository($this->db);
    }

    public function testUpdateIgnoresKeysOutsideTheColumnList(): void
    {
        $uuid = 'plan-' . bin2hex(random_bytes(4));
        $id = $this->plans->insert([
            'uuid' => $uuid,
            'code' => 'test_plan',
            'name' => 'Test Plan',
            'description' => null,
            'price_minor' => 1000,
            'currency' => 'KES',
            'billing_interval' => 'monthly',
            'is_active' => 1,
            'sort_position' => 1,
        ]);

        // Callers hand over wider arrays — validated() payloads can carry form
        // or auxiliary keys; binding them to the fixed UPDATE used to raise
        // SQLSTATE[HY093] and kill the request.
        $this->plans->update($id, [
            'uuid' => 'must-not-leak',
            'features' => ['x'],
            'csrf_token' => 'stray',
            'code' => 'test_plan_2',
            'name' => 'Renamed Plan',
            'description' => 'desc',
            'price_minor' => 2500,
            'currency' => 'KES',
            'billing_interval' => 'yearly',
            'is_active' => 0,
            'sort_position' => 7,
        ]);

        $row = $this->db->pdo()->prepare('SELECT * FROM plans WHERE id = :id');
        $row->execute(['id' => $id]);
        $plan = $row->fetch();

        self::assertSame($uuid, (string) $plan['uuid']);
        self::assertSame('test_plan_2', (string) $plan['code']);
        self::assertSame('Renamed Plan', (string) $plan['name']);
        self::assertSame(2500, (int) $plan['price_minor']);
        self::assertSame('yearly', (string) $plan['billing_interval']);
        self::assertSame(0, (int) $plan['is_active']);
        self::assertSame(7, (int) $plan['sort_position']);
    }

    public function testInsertIgnoresKeysOutsideTheColumnList(): void
    {
        $id = $this->plans->insert([
            'uuid' => 'plan-' . bin2hex(random_bytes(4)),
            'code' => 'stray_keys',
            'name' => 'Stray Keys',
            'description' => null,
            'price_minor' => 0,
            'currency' => 'KES',
            'billing_interval' => 'monthly',
            'is_active' => 1,
            'sort_position' => 0,
            'unexpected' => 'stray',
        ]);

        $row = $this->db->pdo()->prepare('SELECT code FROM plans WHERE id = :id');
        $row->execute(['id' => $id]);

        self::assertSame('stray_keys', (string) $row->fetchColumn());
    }
}
