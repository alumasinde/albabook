<?php

declare(strict_types=1);

namespace App\Modules\Booking\Repositories;

use App\Core\Database\Database;
use PDO;

/**
 * Tenant-scoped reads/writes for the booking-page editor. Everything is
 * keyed by tenant_id; the public page reads the same tables via
 * BookingPageRepository.
 */
final class BookingEditorRepository
{
    public function __construct(private readonly Database $db) {}

    /** Booking-page settings row, created with defaults on first access.
     * @return array<string, mixed>
     */
    public function settings(int $tenantId): array
    {
        $row = $this->settingsRow($tenantId);
        if ($row !== null) {
            return $row;
        }

        $this->db->pdo()->prepare(
            'INSERT INTO booking_page_settings (tenant_id) VALUES (?)',
        )->execute([$tenantId]);

        $row = $this->settingsRow($tenantId);

        /** @var array<string, mixed> $row */
        return $row ?? [];
    }

    /** @return array<string, mixed>|null */
    private function settingsRow(int $tenantId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM booking_page_settings WHERE tenant_id = ?',
        );
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $data */
    public function updateSettings(int $tenantId, array $data): void
    {
        $this->settings($tenantId); // ensure the row exists
        $this->db->pdo()->prepare(
            'UPDATE booking_page_settings SET
                is_enabled = :is_enabled,
                seo_title = :seo_title,
                seo_description = :seo_description,
                og_title = :og_title,
                og_description = :og_description,
                allow_indexing = :allow_indexing,
                booking_flow = :booking_flow,
                staff_selection = :staff_selection,
                branch_selection = :branch_selection,
                service_presentation = :service_presentation,
                calendar_presentation = :calendar_presentation,
                time_slot_presentation = :time_slot_presentation,
                show_prices = :show_prices,
                show_duration = :show_duration,
                default_locale = :default_locale
             WHERE tenant_id = :tenant_id',
        )->execute($data + ['tenant_id' => $tenantId]);
    }

    /** All section types with this tenant's enable/order overrides.
     * @return list<array<string, mixed>>
     */
    public function sections(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT t.code, t.name, t.feature_code,
                    COALESCE(s.is_enabled, t.enabled_by_default) AS is_enabled,
                    COALESCE(s.sort_position, t.default_position) AS sort_position
             FROM booking_page_section_types t
             LEFT JOIN booking_page_sections s
               ON s.tenant_id = ? AND s.section_key = t.code
             WHERE t.is_active = 1
             ORDER BY sort_position, t.default_position',
        );
        $stmt->execute([$tenantId]);

        /** @var list<array<string, mixed>> */
        return array_values($stmt->fetchAll());
    }

    /** @param list<array{key: string, enabled: bool, position: int}> $items */
    public function syncSections(int $tenantId, array $items): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO booking_page_sections (tenant_id, section_key, is_enabled, sort_position)
             VALUES (:tid, :key, :en, :pos)
             ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled), sort_position = VALUES(sort_position)',
        );
        foreach ($items as $item) {
            $stmt->execute([
                'tid' => $tenantId,
                'key' => $item['key'],
                'en' => $item['enabled'] ? 1 : 0,
                'pos' => $item['position'],
            ]);
        }
    }

    /** @return list<array<string, mixed>> */
    public function faqs(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, question, answer, sort_position FROM booking_page_faqs
             WHERE tenant_id = ? ORDER BY sort_position, id',
        );
        $stmt->execute([$tenantId]);

        /** @var list<array<string, mixed>> */
        return array_values($stmt->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function findFaq(int $tenantId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, question, answer, sort_position FROM booking_page_faqs
             WHERE tenant_id = ? AND id = ?',
        );
        $stmt->execute([$tenantId, $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @param array{question: string, answer: string, sort_position: int} $data */
    public function insertFaq(int $tenantId, array $data): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO booking_page_faqs (tenant_id, question, answer, sort_position)
             VALUES (?, ?, ?, ?)',
        )->execute([$tenantId, $data['question'], $data['answer'], $data['sort_position']]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array{question: string, answer: string, sort_position: int} $data */
    public function updateFaq(int $tenantId, int $id, array $data): void
    {
        $this->db->pdo()->prepare(
            'UPDATE booking_page_faqs SET question = ?, answer = ?, sort_position = ?
             WHERE tenant_id = ? AND id = ?',
        )->execute([$data['question'], $data['answer'], $data['sort_position'], $tenantId, $id]);
    }

    public function deleteFaq(int $tenantId, int $id): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM booking_page_faqs WHERE tenant_id = ? AND id = ?',
        )->execute([$tenantId, $id]);
    }

    /** Recent publish history.
     * @return list<array<string, mixed>>
     */
    public function versions(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT uuid, version_number, status, is_active, published_at
             FROM booking_page_versions
             WHERE tenant_id = ? ORDER BY version_number DESC LIMIT 10',
        );
        $stmt->execute([$tenantId]);

        /** @var list<array<string, mixed>> */
        return array_values($stmt->fetchAll());
    }

    /** Next version number for a publish snapshot. */
    public function nextVersion(int $tenantId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(MAX(version_number), 0) + 1 FROM booking_page_versions WHERE tenant_id = ?',
        );
        $stmt->execute([$tenantId]);

        return (int) $stmt->fetchColumn();
    }

    /** Archive the previously-active version in the same transaction. */
    public function deactivateVersions(int $tenantId): void
    {
        $this->db->pdo()->prepare(
            "UPDATE booking_page_versions
             SET is_active = 0, status = 'archived'
             WHERE tenant_id = ? AND is_active = 1",
        )->execute([$tenantId]);
    }

    public function insertVersion(int $tenantId, string $uuid, int $versionNumber, string $snapshot, int $userId): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO booking_page_versions
                (uuid, tenant_id, version_number, snapshot, status, is_active, published_at, published_by_user_id)
             VALUES (?, ?, ?, ?, 'published', 1, NOW(), ?)",
        )->execute([$uuid, $tenantId, $versionNumber, $snapshot, $userId]);
    }

    /** @return array<string, mixed>|null */
    public function tenant(int $tenantId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT name, slug, default_locale FROM tenants WHERE id = ?',
        );
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return list<array{code: string}> */
    public function locales(): array
    {
        $stmt = $this->db->pdo()->query('SELECT code FROM locales WHERE is_active = 1 ORDER BY code');

        /** @var list<array{code: string}> */
        return $stmt === false ? [] : array_values($stmt->fetchAll());
    }
}
