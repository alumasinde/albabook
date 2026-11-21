<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Command;
use App\Console\Output;
use App\Core\Config\Config;
use App\Core\Database\Database;
use App\Core\Http\Request;
use App\Modules\Appointments\Services\ManualBookingService;
use App\Modules\Appointments\Services\TransitionException;
use App\Modules\Auth\Repositories\UserRepository;
use App\Shared\Security\PasswordHasher;
use App\Shared\Support\UuidFactory;
use App\Tenancy\RoleRepository;
use App\Tenancy\TenantMembershipRepository;
use App\Tenancy\TenantRegistrationService;

/**
 * Local demo data: one tenant with a branch, hours, a booking policy,
 * services, two bookable staff, a published booking page and a handful of
 * appointments. Idempotent — re-running only repairs logins, it never
 * duplicates tenant content.
 */
final class DemoSeedCommand implements Command
{
    private const OWNER_EMAIL = 'owner@demo.albabook.test';
    private const STAFF_EMAIL = 'staff@demo.albabook.test';
    private const PASSWORD = 'demo-password-1';
    private const TENANT_SLUG = 'royal-beauty';
    private const TENANT_NAME = 'Royal Beauty Studio';

    public function __construct(
        private readonly Config $config,
        private readonly Database $db,
        private readonly UserRepository $users,
        private readonly TenantMembershipRepository $memberships,
        private readonly RoleRepository $roles,
        private readonly TenantRegistrationService $registration,
        private readonly ManualBookingService $bookings,
        private readonly PasswordHasher $hasher,
        private readonly UuidFactory $uuids,
    ) {}

    public function name(): string
    {
        return 'demo:seed';
    }

    public function description(): string
    {
        return 'Create a demo tenant with staff, services and bookings (local only)';
    }

    /** @param list<string> $args */
    public function run(array $args, Output $output): int
    {
        $env = $this->config->string('app.env');
        if (!in_array($env, ['local', 'testing'], true)) {
            $output->error("demo:seed is disabled in the \"{$env}\" environment.");

            return 1;
        }

        $hash = $this->hasher->hash(self::PASSWORD);
        $ownerId = $this->ensureUser(self::OWNER_EMAIL, 'Diana', 'Owner', $hash);
        $staffUserId = $this->ensureUser(self::STAFF_EMAIL, 'Amina', 'Otieno', $hash);

        $tenantId = $this->tenantIdForSlug();
        if ($tenantId === null) {
            $tenantId = $this->createTenant($ownerId);
            $output->line('  tenant           ' . self::TENANT_NAME . ' (/' . self::TENANT_SLUG . ')');
        } else {
            $output->line('  tenant           ' . self::TENANT_NAME . ' already exists, reusing');
        }

        $this->activateTenant($tenantId);

        $staffMembershipId = $this->ensureStaffMembership($tenantId, $staffUserId, $ownerId);

        if ($this->branchId($tenantId) === null) {
            $this->seedContent($tenantId, $staffMembershipId);
            $output->line('  branch           Main Branch (Westlands), Mon–Sat 08:00–18:00');
            $output->line('  services         4 bookable services, KES pricing');
            $output->line('  staff            Amina Otieno (login), Brian Mwangi');
            $output->line('  booking page     enabled at book host /' . self::TENANT_SLUG);
            $this->seedAppointments($tenantId);
            $output->line('  appointments     sample bookings today/tomorrow');
        }

        $output->success('Demo data ready.');
        $output->line();
        $output->line('  Sign in at the app host:');
        $output->line('    owner  ' . self::OWNER_EMAIL . '   full access');
        $output->line('    staff  ' . self::STAFF_EMAIL . '   own appointments only');
        $output->line('    both use password: ' . self::PASSWORD);
        $output->line();
        $output->line('  Booking page: book host /' . self::TENANT_SLUG);

        return 0;
    }

    private function ensureUser(string $email, string $first, string $last, string $hash): int
    {
        $existing = $this->users->findByEmail($email);
        if ($existing !== null) {
            $id = (int) $existing['id'];
            $this->users->updatePasswordHash($id, $hash);
            $this->users->markEmailVerified($id, date('Y-m-d H:i:s'));

            return $id;
        }

        $id = $this->users->create([
            'first_name' => $first,
            'middle_name' => null,
            'last_name' => $last,
            'email' => $email,
            'phone_e164' => null,
            'password_hash' => $hash,
            'locale' => 'en',
        ], $this->uuids->generate());
        $this->users->markEmailVerified($id, date('Y-m-d H:i:s'));

        return $id;
    }

    private function tenantIdForSlug(): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM tenants WHERE slug = :slug');
        $stmt->execute(['slug' => self::TENANT_SLUG]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function createTenant(int $ownerId): int
    {
        $request = Request::create('POST', 'http://app.albabook.localhost/onboarding/business');
        $context = $this->registration->register([
            'name' => self::TENANT_NAME,
            'slug' => self::TENANT_SLUG,
            'timezone' => 'Africa/Nairobi',
            'currency' => 'KES',
        ], $ownerId, $request);

        return $context->tenantId;
    }

    private function activateTenant(int $tenantId): void
    {
        $this->db->pdo()->prepare("UPDATE tenants SET status = 'active' WHERE id = :id AND status != 'active'")
            ->execute(['id' => $tenantId]);
    }

    private function ensureStaffMembership(int $tenantId, int $staffUserId, int $ownerId): int
    {
        $existing = $this->memberships->find($staffUserId, $tenantId);
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $membershipId = $this->memberships->create($tenantId, $staffUserId, 'active', $ownerId);

        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM roles WHERE tenant_id = :t AND slug = 'staff' LIMIT 1",
        );
        $stmt->execute(['t' => $tenantId]);
        $roleId = $stmt->fetchColumn();
        if ($roleId !== false) {
            $this->roles->assignRole($tenantId, $membershipId, (int) $roleId);
        }

        return $membershipId;
    }

    private function branchId(int $tenantId): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM branches WHERE tenant_id = :t LIMIT 1');
        $stmt->execute(['t' => $tenantId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Creates the branch, hours, policy, services, staff and booking page in
     * one transaction — mirrors what the (unbuilt) settings UI would write.
     */
    private function seedContent(int $tenantId, int $staffMembershipId): void
    {
        $this->db->transaction(function () use ($tenantId, $staffMembershipId): void {
            $pdo = $this->db->pdo();

            $pdo->prepare(
                'INSERT INTO branches (uuid, tenant_id, name, town, timezone, is_active, created_at, updated_at)
                 VALUES (:uuid, :t, :name, :town, :tz, 1, NOW(), NOW())',
            )->execute([
                'uuid' => $this->uuids->generate(), 't' => $tenantId,
                'name' => 'Main Branch', 'town' => 'Westlands, Nairobi',
                'tz' => 'Africa/Nairobi',
            ]);
            $branchId = (int) $pdo->lastInsertId();

            $hours = $pdo->prepare(
                'INSERT INTO business_hours (tenant_id, branch_id, weekday, opens_at, closes_at, created_at, updated_at)
                 VALUES (:t, :b, :w, :o, :c, NOW(), NOW())',
            );
            for ($weekday = 1; $weekday <= 6; $weekday++) {
                $hours->execute([
                    't' => $tenantId, 'b' => $branchId, 'w' => $weekday,
                    'o' => '08:00:00', 'c' => '18:00:00',
                ]);
            }

            $pdo->prepare(
                'INSERT INTO booking_policies
                    (tenant_id, branch_id, slot_interval_minutes, min_notice_minutes, max_advance_days,
                     hold_ttl_seconds, confirmation_mode, pending_expiry_minutes, staff_assignment_strategy,
                     customer_changes, cancellation_cutoff_minutes, reschedule_cutoff_minutes, max_reschedules,
                     deposit_mode, deposit_value, deposit_due_minutes, customer_verification,
                     max_active_bookings_per_contact, customer_match, policy_version, created_at, updated_at)
                 VALUES
                    (:t, NULL, 15, 60, 30, 900, :mode, 1440, :strategy, :changes, 120, 120, 2,
                     :deposit, NULL, NULL, :verify, 3, :match, 1, NOW(), NOW())',
            )->execute([
                't' => $tenantId, 'mode' => 'auto', 'strategy' => 'least_booked_day',
                'changes' => 'self_service', 'deposit' => 'none', 'verify' => 'none',
                'match' => 'phone_or_email',
            ]);

            $serviceStmt = $pdo->prepare(
                'INSERT INTO services (uuid, tenant_id, name, description, duration_minutes,
                    buffer_before_minutes, buffer_after_minutes, price_type, price_amount_minor,
                    currency, is_active, is_bookable_online, sort_position, created_at, updated_at)
                 VALUES (:uuid, :t, :name, :descr, :dur, 0, 0, :ptype, :price,
                    :cur, 1, 1, :pos, NOW(), NOW())',
            );
            $linkStmt = $pdo->prepare(
                'INSERT INTO service_branches (tenant_id, service_id, branch_id, is_bookable_online)
                 VALUES (:t, :s, :b, 1)',
            );
            $services = [
                ['Classic Haircut', 'Wash, cut and finish.', 45, 150000],
                ['Box Braids', 'Full-head box braids, hair included.', 180, 550000],
                ['Deep Tissue Massage', '60-minute full-body massage.', 60, 350000],
                ['Gel Manicure', 'Shape, cuticle care and gel polish.', 50, 200000],
            ];
            $serviceIds = [];
            foreach ($services as $i => [$name, $descr, $duration, $price]) {
                $serviceStmt->execute([
                    'uuid' => $this->uuids->generate(), 't' => $tenantId,
                    'name' => $name, 'descr' => $descr, 'dur' => $duration,
                    'ptype' => 'fixed', 'price' => $price, 'cur' => 'KES',
                    'pos' => $i + 1,
                ]);
                $serviceId = (int) $pdo->lastInsertId();
                $serviceIds[] = $serviceId;
                $linkStmt->execute(['t' => $tenantId, 's' => $serviceId, 'b' => $branchId]);
            }

            $productStmt = $pdo->prepare(
                'INSERT INTO products (uuid, tenant_id, name, sku, price_minor, tax_rate_bp,
                    currency, stock_quantity, is_active, created_at, updated_at)
                 VALUES (:uuid, :t, :name, :sku, :price, 0, :cur, :stock, 1, NOW(), NOW())',
            );
            foreach ([
                ['Argan Hair Oil 100ml', 'OIL-ARG-100', 120000, 24],
                ['Edge Control Gel', 'GEL-EDG-50', 85000, 18],
                ['Nail Polish Remover', 'RMV-NPL-200', 65000, 30],
                ['Massage Candle', 'CND-MSG-01', 150000, null],
            ] as [$name, $sku, $price, $stock]) {
                $productStmt->execute([
                    'uuid' => $this->uuids->generate(), 't' => $tenantId,
                    'name' => $name, 'sku' => $sku, 'price' => $price,
                    'cur' => 'KES', 'stock' => $stock,
                ]);
            }

            $staffStmt = $pdo->prepare(
                'INSERT INTO staff (uuid, tenant_id, tenant_user_id, first_name, last_name, display_name,
                    booking_priority, is_bookable_online, status, created_at, updated_at)
                 VALUES (:uuid, :t, :tu, :first, :last, :display, :prio, 1, :status, NOW(), NOW())',
            );
            $staffSkill = $pdo->prepare(
                'INSERT INTO staff_services (tenant_id, staff_id, service_id, is_bookable_online)
                 VALUES (:t, :staff, :s, 1)',
            );
            $staffBranch = $pdo->prepare(
                'INSERT INTO staff_branches (tenant_id, staff_id, branch_id) VALUES (:t, :staff, :b)',
            );
            $schedule = $pdo->prepare(
                'INSERT INTO staff_schedules (tenant_id, staff_id, branch_id, weekday, starts_at, ends_at, created_at, updated_at)
                 VALUES (:t, :staff, :b, :w, :o, :c, NOW(), NOW())',
            );

            $staffRows = [
                [$staffMembershipId, 'Amina', 'Otieno', 'Amina O.', 1],
                [null, 'Brian', 'Mwangi', 'Brian M.', 2],
            ];
            $staffIds = [];
            foreach ($staffRows as [$membershipId, $first, $last, $display, $prio]) {
                $staffStmt->execute([
                    'uuid' => $this->uuids->generate(), 't' => $tenantId, 'tu' => $membershipId,
                    'first' => $first, 'last' => $last, 'display' => $display,
                    'prio' => $prio, 'status' => 'active',
                ]);
                $staffId = (int) $pdo->lastInsertId();
                $staffIds[] = $staffId;
                $staffBranch->execute(['t' => $tenantId, 'staff' => $staffId, 'b' => $branchId]);
                foreach ($serviceIds as $serviceId) {
                    $staffSkill->execute(['t' => $tenantId, 'staff' => $staffId, 's' => $serviceId]);
                }
                for ($weekday = 1; $weekday <= 6; $weekday++) {
                    $schedule->execute([
                        't' => $tenantId, 'staff' => $staffId, 'b' => $branchId,
                        'w' => $weekday, 'o' => '08:00:00', 'c' => '18:00:00',
                    ]);
                }
            }

            $pdo->prepare(
                'INSERT INTO booking_page_settings
                    (tenant_id, is_enabled, staff_selection, branch_selection, service_presentation,
                     calendar_presentation, time_slot_presentation, show_prices, show_duration,
                     booking_flow, allow_indexing, default_locale, updated_at)
                 VALUES (:t, 1, :staff_sel, :branch_sel, :svc, :cal, :slots, 1, 1, :flow, 0, :loc, NOW())',
            )->execute([
                't' => $tenantId, 'staff_sel' => 'required', 'branch_sel' => 'hidden',
                'svc' => 'cards', 'cal' => 'standard', 'slots' => 'grid',
                'flow' => 'step_by_step', 'loc' => 'en',
            ]);

        });
    }

    /**
     * A few bookings via the real booking service so the dashboard, calendar
     * and appointment list have live data. Skipped silently if a slot is
     * already taken.
     */
    private function seedAppointments(int $tenantId): void
    {
        $branchId = (int) $this->column('SELECT id FROM branches WHERE tenant_id = :t LIMIT 1', $tenantId);
        $staffIds = $this->columnList('SELECT id FROM staff WHERE tenant_id = :t ORDER BY id', $tenantId);
        $serviceIds = $this->columnList('SELECT id FROM services WHERE tenant_id = :t ORDER BY sort_position', $tenantId);

        if ($branchId === 0 || $staffIds === [] || $serviceIds === []) {
            return;
        }

        $customers = [
            ['name' => 'Wanjiru Kamau', 'phone' => '+254712345001', 'email' => 'wanjiru@example.com', 'notes' => null],
            ['name' => 'Otieno Ochieng', 'phone' => '+254712345002', 'email' => 'otieno@example.com', 'notes' => null],
            ['name' => 'Achieng Odhiambo', 'phone' => '+254712345003', 'email' => 'achieng@example.com', 'notes' => null],
            ['name' => 'Mutua Kilonzo', 'phone' => '+254712345004', 'email' => 'mutua@example.com', 'notes' => null],
        ];

        $today = new \DateTimeImmutable('today', new \DateTimeZone('Africa/Nairobi'));
        $slots = [
            [$today, '09:00'], [$today, '10:30'], [$today, '14:00'], [$today->modify('+1 day'), '09:30'],
        ];

        foreach ($slots as $i => [$day, $time]) {
            try {
                $this->bookings->create(
                    $tenantId,
                    $branchId,
                    'Africa/Nairobi',
                    (int) $staffIds[$i % count($staffIds)],
                    (int) $serviceIds[$i % count($serviceIds)],
                    $day->format('Y-m-d'),
                    $time,
                    $customers[$i],
                    'dashboard',
                    'en',
                    'KES',
                    null,
                    'system',
                );
            } catch (TransitionException) {
                // Slot clash or other policy failure — demo data is best-effort.
            }
        }
    }

    private function column(string $sql, int $tenantId): mixed
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['t' => $tenantId]);

        return $stmt->fetchColumn();
    }

    /** @return list<int> */
    private function columnList(string $sql, int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['t' => $tenantId]);

        /** @var list<int> */
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }
}
