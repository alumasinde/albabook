<?php

declare(strict_types=1);

namespace App\Core\Database\Seeders;

use App\Core\Database\Database;

/**
 * Ordered reference data. Runs inside one transaction so a failed seed never
 * leaves a half-populated catalogue.
 */
final class DatabaseSeeder
{
    public function __construct(
        private readonly Database $db,
        private readonly PermissionSeeder $permissions,
        private readonly RoleTemplateSeeder $roles,
        private readonly TenantStatusPolicySeeder $policies,
        private readonly PlanSeeder $plans,
    ) {}

    /** @return array{permissions: int, roles: int, policies: int, plans: int, providers: int} */
    public function run(): array
    {
        return $this->db->transaction(fn(): array => [
            'permissions' => $this->permissions->run(),
            'roles' => $this->roles->run(),
            'policies' => $this->policies->run(),
            'plans' => $this->plans->run(),
            'providers' => $this->notificationProviders(),
        ]);
    }

    /**
     * Reference notification providers (SMS/WhatsApp). Idempotent — safe to
     * re-run against a seeded database.
     */
    private function notificationProviders(): int
    {
        $providers = [
            ['africastalking_sms', "Africa's Talking", 'sms'],
            ['twilio_sms', 'Twilio SMS', 'sms'],
            ['whatsapp_cloud', 'WhatsApp Cloud API (Meta)', 'whatsapp'],
            ['twilio_whatsapp', 'Twilio WhatsApp', 'whatsapp'],
        ];

        $stmt = $this->db->pdo()->prepare(
            'INSERT IGNORE INTO notification_providers (code, name, channel, is_active, created_at)
             VALUES (:code, :name, :channel, 1, NOW())',
        );
        $count = 0;
        foreach ($providers as [$code, $name, $channel]) {
            $stmt->execute(['code' => $code, 'name' => $name, 'channel' => $channel]);
            $count += $stmt->rowCount();
        }

        return $count;
    }
}
