<?php

declare(strict_types=1);

namespace App\Core\Database\Seeders;

use App\Core\Database\Database;
use App\Shared\Support\UuidFactory;

/**
 * Reference plans + their feature matrix. Idempotent — safe to re-run.
 * NULL limit_value means unlimited.
 */
final class PlanSeeder
{
    /** @var array<string, array{price:int, features: array<string, array{enabled: bool, limit: ?int}>}> */
    private const PLANS = [
        'trial' => [
            'price' => 0,
            'features' => [
                'max_staff' => ['enabled' => true, 'limit' => 3],
                'max_branches' => ['enabled' => true, 'limit' => 1],
                'monthly_bookings' => ['enabled' => true, 'limit' => 100],
                'advanced_reports' => ['enabled' => false, 'limit' => null],
                'api_access' => ['enabled' => false, 'limit' => null],
                'custom_booking_domain' => ['enabled' => false, 'limit' => null],
            ],
        ],
        'starter' => [
            'price' => 150000,
            'features' => [
                'max_staff' => ['enabled' => true, 'limit' => 5],
                'max_branches' => ['enabled' => true, 'limit' => 2],
                'monthly_bookings' => ['enabled' => true, 'limit' => 500],
                'advanced_reports' => ['enabled' => true, 'limit' => null],
                'api_access' => ['enabled' => false, 'limit' => null],
                'custom_booking_domain' => ['enabled' => false, 'limit' => null],
            ],
        ],
        'growth' => [
            'price' => 350000,
            'features' => [
                'max_staff' => ['enabled' => true, 'limit' => 15],
                'max_branches' => ['enabled' => true, 'limit' => 5],
                'monthly_bookings' => ['enabled' => true, 'limit' => 2000],
                'advanced_reports' => ['enabled' => true, 'limit' => null],
                'api_access' => ['enabled' => true, 'limit' => null],
                'custom_booking_domain' => ['enabled' => true, 'limit' => null],
            ],
        ],
        'pro' => [
            'price' => 750000,
            'features' => [
                'max_staff' => ['enabled' => true, 'limit' => 50],
                'max_branches' => ['enabled' => true, 'limit' => 10],
                'monthly_bookings' => ['enabled' => true, 'limit' => null],
                'advanced_reports' => ['enabled' => true, 'limit' => null],
                'api_access' => ['enabled' => true, 'limit' => null],
                'custom_booking_domain' => ['enabled' => true, 'limit' => null],
                'white_label' => ['enabled' => true, 'limit' => null],
            ],
        ],
    ];

    public function __construct(
        private readonly Database $db,
        private readonly UuidFactory $uuids,
    ) {}

    public function run(): int
    {
        $pdo = $this->db->pdo();
        $planId = $pdo->prepare(
            'INSERT INTO plans (uuid, code, name, description, price_minor, currency, billing_interval, sort_position)
             VALUES (:uuid, :code, :name, :desc, :price, "KES", "monthly", :sort)
             ON DUPLICATE KEY UPDATE name = VALUES(name), price_minor = VALUES(price_minor), sort_position = VALUES(sort_position)',
        );
        $feature = $pdo->prepare(
            'INSERT INTO plan_features (plan_id, feature_key, is_enabled, limit_value)
             SELECT p.id, :f, :en, :lim FROM plans p WHERE p.code = :c
             ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled), limit_value = VALUES(limit_value)',
        );

        $count = 0;
        $sort = 0;
        foreach (self::PLANS as $code => $plan) {
            $planId->execute([
                'uuid' => $this->uuids->generate(),
                'code' => $code,
                'name' => ucfirst($code),
                'desc' => $code === 'trial' ? 'Free trial — starter limits.' : ucfirst($code) . ' plan.',
                'price' => $plan['price'],
                'sort' => $sort += 10,
            ]);
            foreach ($plan['features'] as $key => $f) {
                $feature->execute([
                    'f' => $key, 'en' => $f['enabled'] ? 1 : 0,
                    'lim' => $f['limit'], 'c' => $code,
                ]);
            }
            $count++;
        }

        return $count;
    }
}
