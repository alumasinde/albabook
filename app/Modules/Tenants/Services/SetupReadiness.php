<?php

declare(strict_types=1);

namespace App\Modules\Tenants\Services;

use App\Core\Database\Database;

/**
 * What a business still needs before its booking page can take bookings.
 * Drives three things from one definition:
 *  - the dashboard setup checklist (update.md 2.3),
 *  - the publish / go-live gate (2.4): blocking items must all pass,
 *  - the setup wizard's progress (2.2).
 *
 * "Bookable" means what the availability engine needs: an active,
 * online-bookable service performed by an active, online-bookable staff
 * member who works at an active branch and has weekly hours there.
 */
final class SetupReadiness
{
    public function __construct(private readonly Database $db) {}

    /**
     * @return array{ready: bool, done: int, total: int, items: list<array{key: string, label: string, done: bool, blocking: bool, href: string, hint: string}>}
     */
    public function check(int $tenantId): array
    {
        $items = [
            $this->item('email', 'Verify your e-mail address', $this->ownerEmailVerified($tenantId), true,
                '/verify-email', 'We sent a link when you signed up. Bookings cannot go live until it is verified.'),
            $this->item('branch', 'Add your location', $this->exists(
                'SELECT 1 FROM branches WHERE tenant_id = :t AND is_active = 1 LIMIT 1', $tenantId,
            ), true, '/branches', 'Where customers come to you.'),
            $this->item('hours', 'Set opening hours', $this->exists(
                'SELECT 1 FROM business_hours h JOIN branches b ON b.tenant_id = h.tenant_id AND b.id = h.branch_id
                  WHERE h.tenant_id = :t AND b.is_active = 1 AND (h.effective_to IS NULL OR h.effective_to >= UTC_DATE()) LIMIT 1', $tenantId,
            ), true, '/branches', 'The days and times your location is open.'),
            $this->item('service', 'Add a service customers can book online', $this->exists(
                'SELECT 1 FROM services WHERE tenant_id = :t AND is_active = 1 AND is_bookable_online = 1 LIMIT 1', $tenantId,
            ), true, '/services', 'With its duration and price.'),
            $this->item('staff', 'Assign someone to do the service, with working hours', $this->bookableStaff($tenantId), true,
                '/staff', 'A team member (or you) who offers the service at your location and has weekly hours there.'),
            $this->item('rules', 'Choose how bookings are confirmed', $this->exists(
                'SELECT 1 FROM booking_policies WHERE tenant_id = :t AND branch_scope = 0 LIMIT 1', $tenantId,
            ), false, '/settings#booking-rules', 'Automatic confirmation is on until you choose.'),
            $this->item('live', 'Turn on your booking page', $this->exists(
                'SELECT 1 FROM booking_page_settings WHERE tenant_id = :t AND is_enabled = 1 LIMIT 1', $tenantId,
            ), false, '/setup', 'Going live starts your free trial. Then share your link.'),
        ];

        $blockingDone = array_reduce($items, static fn(bool $ok, array $i): bool => $ok && (!$i['blocking'] || $i['done']), true);
        $done = count(array_filter($items, static fn(array $i): bool => $i['done']));

        return ['ready' => $blockingDone, 'done' => $done, 'total' => count($items), 'items' => $items];
    }

    /**
     * Reasons the page cannot go live (blocking items not done), for a 422.
     *
     * @return list<string>
     */
    public function blockers(int $tenantId): array
    {
        $out = [];
        foreach ($this->check($tenantId)['items'] as $item) {
            if ($item['blocking'] && !$item['done']) {
                $out[] = $item['label'];
            }
        }

        return $out;
    }

    private function bookableStaff(int $tenantId): bool
    {
        return $this->exists(
            "SELECT 1
               FROM staff s
               JOIN staff_services ss ON ss.tenant_id = s.tenant_id AND ss.staff_id = s.id AND ss.is_bookable_online = 1
               JOIN services sv ON sv.tenant_id = s.tenant_id AND sv.id = ss.service_id
                               AND sv.is_active = 1 AND sv.is_bookable_online = 1
               JOIN staff_branches sb ON sb.tenant_id = s.tenant_id AND sb.staff_id = s.id
               JOIN branches b ON b.tenant_id = s.tenant_id AND b.id = sb.branch_id AND b.is_active = 1
               JOIN staff_schedules sh ON sh.tenant_id = s.tenant_id AND sh.staff_id = s.id AND sh.branch_id = b.id
                                      AND (sh.effective_to IS NULL OR sh.effective_to >= UTC_DATE())
              WHERE s.tenant_id = :t AND s.status = 'active' AND s.is_bookable_online = 1
              LIMIT 1",
            $tenantId,
        );
    }

    private function ownerEmailVerified(int $tenantId): bool
    {
        return $this->exists(
            "SELECT 1
               FROM tenant_users tu
               JOIN users u ON u.id = tu.user_id AND u.email_verified_at IS NOT NULL
               JOIN membership_roles mr ON mr.tenant_id = tu.tenant_id AND mr.tenant_user_id = tu.id
               JOIN roles r ON r.id = mr.role_id AND r.tenant_id = tu.tenant_id AND r.slug = 'owner'
              WHERE tu.tenant_id = :t AND tu.status = 'active'
              LIMIT 1",
            $tenantId,
        );
    }

    private function exists(string $sql, int $tenantId): bool
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['t' => $tenantId]);

        return $stmt->fetchColumn() !== false;
    }

    /** @return array{key: string, label: string, done: bool, blocking: bool, href: string, hint: string} */
    private function item(string $key, string $label, bool $done, bool $blocking, string $href, string $hint): array
    {
        return ['key' => $key, 'label' => $label, 'done' => $done, 'blocking' => $blocking, 'href' => $href, 'hint' => $hint];
    }
}
