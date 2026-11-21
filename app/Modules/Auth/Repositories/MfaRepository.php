<?php

declare(strict_types=1);

namespace App\Modules\Auth\Repositories;

use App\Core\Database\Database;
use App\Modules\Auth\Mfa\MfaSubject;

/**
 * TOTP factors and recovery codes for tenant users and platform users.
 * Table names come from the MfaSubject enum, never from request data.
 */
final class MfaRepository
{
    public function __construct(private readonly Database $db) {}

    public function createFactor(MfaSubject $subject, int $ownerId, string $secretEncrypted, ?string $label): int
    {
        $table = $subject->factorTable();
        $owner = $subject->ownerColumn();
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO {$table} ({$owner}, type, label, secret_encrypted)
             VALUES (:owner_id, 'totp', :label, :secret)",
        )->execute([
            'owner_id' => $ownerId,
            'label' => $label === null || $label === '' ? null : mb_substr($label, 0, 100),
            'secret' => $secretEncrypted,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null the newest confirmed factor */
    public function confirmedFactor(MfaSubject $subject, int $ownerId): ?array
    {
        $table = $subject->factorTable();
        $owner = $subject->ownerColumn();
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, label, secret_encrypted, confirmed_at, last_used_step
             FROM {$table}
             WHERE {$owner} = :owner_id AND confirmed_at IS NOT NULL
             ORDER BY id DESC LIMIT 1",
        );
        $stmt->execute(['owner_id' => $ownerId]);

        return $stmt->fetch() ?: null;
    }

    /** @return array<string, mixed>|null newest unconfirmed factor (enrollment in progress) */
    public function unconfirmedFactor(MfaSubject $subject, int $ownerId): ?array
    {
        $table = $subject->factorTable();
        $owner = $subject->ownerColumn();
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, label, secret_encrypted, created_at
             FROM {$table}
             WHERE {$owner} = :owner_id AND confirmed_at IS NULL
             ORDER BY id DESC LIMIT 1",
        );
        $stmt->execute(['owner_id' => $ownerId]);

        return $stmt->fetch() ?: null;
    }

    public function hasConfirmedFactor(MfaSubject $subject, int $ownerId): bool
    {
        return $this->confirmedFactor($subject, $ownerId) !== null;
    }

    /** @return array<string, mixed>|null */
    public function findFactor(MfaSubject $subject, int $ownerId, int $factorId): ?array
    {
        $table = $subject->factorTable();
        $owner = $subject->ownerColumn();
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, label, secret_encrypted, confirmed_at, last_used_step
             FROM {$table} WHERE id = :id AND {$owner} = :owner_id LIMIT 1",
        );
        $stmt->execute(['id' => $factorId, 'owner_id' => $ownerId]);

        return $stmt->fetch() ?: null;
    }

    public function confirmFactor(MfaSubject $subject, int $factorId, string $at): void
    {
        $table = $subject->factorTable();
        $this->db->pdo()->prepare(
            "UPDATE {$table} SET confirmed_at = COALESCE(confirmed_at, :at) WHERE id = :id",
        )->execute(['at' => $at, 'id' => $factorId]);
    }

    public function recordUsedStep(MfaSubject $subject, int $factorId, int $step): void
    {
        $table = $subject->factorTable();
        $this->db->pdo()->prepare(
            "UPDATE {$table} SET last_used_step = GREATEST(COALESCE(last_used_step, 0), :step) WHERE id = :id",
        )->execute(['step' => $step, 'id' => $factorId]);
    }

    public function deleteFactor(MfaSubject $subject, int $ownerId, int $factorId): void
    {
        $table = $subject->factorTable();
        $owner = $subject->ownerColumn();
        $this->db->pdo()->prepare(
            "DELETE FROM {$table} WHERE id = :id AND {$owner} = :owner_id",
        )->execute(['id' => $factorId, 'owner_id' => $ownerId]);
    }

    public function deleteUnconfirmedFactors(MfaSubject $subject, int $ownerId): void
    {
        $table = $subject->factorTable();
        $owner = $subject->ownerColumn();
        $this->db->pdo()->prepare(
            "DELETE FROM {$table} WHERE {$owner} = :owner_id AND confirmed_at IS NULL",
        )->execute(['owner_id' => $ownerId]);
    }

    /** @param list<string> $codeHashes */
    public function replaceRecoveryCodes(MfaSubject $subject, int $ownerId, array $codeHashes): void
    {
        $table = $subject->recoveryTable();
        $owner = $subject->ownerColumn();
        $pdo = $this->db->pdo();

        $pdo->prepare("DELETE FROM {$table} WHERE {$owner} = :owner_id")->execute(['owner_id' => $ownerId]);
        $insert = $pdo->prepare("INSERT INTO {$table} ({$owner}, code_hash) VALUES (:owner_id, :hash)");
        foreach ($codeHashes as $hash) {
            $insert->execute(['owner_id' => $ownerId, 'hash' => $hash]);
        }
    }

    public function consumeRecoveryCode(MfaSubject $subject, int $ownerId, string $codeHash, string $at): bool
    {
        $table = $subject->recoveryTable();
        $owner = $subject->ownerColumn();
        $stmt = $this->db->pdo()->prepare(
            "UPDATE {$table} SET used_at = :at
             WHERE {$owner} = :owner_id AND code_hash = :hash AND used_at IS NULL",
        );
        $stmt->execute(['at' => $at, 'owner_id' => $ownerId, 'hash' => $codeHash]);

        return $stmt->rowCount() === 1;
    }

    public function unusedRecoveryCodeCount(MfaSubject $subject, int $ownerId): int
    {
        $table = $subject->recoveryTable();
        $owner = $subject->ownerColumn();
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE {$owner} = :owner_id AND used_at IS NULL",
        );
        $stmt->execute(['owner_id' => $ownerId]);

        return (int) $stmt->fetchColumn();
    }
}
