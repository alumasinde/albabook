<?php

declare(strict_types=1);

namespace App\Modules\Auth\Repositories;

use App\Modules\Auth\Services\MfaService;
use App\Core\Database\Database;

final class UserRepository
{
    private const COLUMNS = 'id, uuid, first_name, middle_name, last_name, email, phone_e164,
        password_hash, email_verified_at, locale, status, last_login_at';

    public function __construct(private readonly Database $db) {}

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => strtolower(trim($email))]);

        return $stmt->fetch() ?: null;
    }

    /**
     * @param array{first_name: string, middle_name: ?string, last_name: string, email: string,
     *              phone_e164: ?string, password_hash: string, locale: string} $data
     */
    public function create(array $data, string $uuid): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO users (uuid, first_name, middle_name, last_name, email, phone_e164, password_hash, locale)
             VALUES (:uuid, :first_name, :middle_name, :last_name, :email, :phone_e164, :password_hash, :locale)',
        )->execute(['uuid' => $uuid] + $data);

        return (int) $pdo->lastInsertId();
    }

    public function updatePasswordHash(int $userId, string $hash): void
    {
        $this->db->pdo()->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
            ->execute(['hash' => $hash, 'id' => $userId]);
    }

    public function markEmailVerified(int $userId, string $verifiedAt): void
    {
        $this->db->pdo()->prepare(
            'UPDATE users SET email_verified_at = COALESCE(email_verified_at, :verified_at) WHERE id = :id',
        )->execute(['verified_at' => $verifiedAt, 'id' => $userId]);
    }

    public function updateEmail(int $userId, string $email): void
    {
        $this->db->pdo()->prepare('UPDATE users SET email = :email WHERE id = :id')
            ->execute(['email' => strtolower($email), 'id' => $userId]);
    }

    /** The instant until which TOTP verification is locked for this user. */
    public function mfaLockedUntil(int $userId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT mfa_locked_until FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $value = $stmt->fetchColumn();

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Cumulative per-user TOTP failure counter; locks once $max is reached. */
    public function recordMfaFailure(int $userId, int $max, int $lockMinutes): void
    {
        // Fixed counting window (MfaService::USER_FAILURE_WINDOW_MINUTES):
        // a failure after the window has lapsed starts a fresh count, so
        // stray typos spread over weeks never add up to a lock. SET clauses
        // evaluate left to right: the window test reads the OLD start, the
        // lock test reads the NEW attempt count.
        $this->db->pdo()->prepare(
            'UPDATE users
                SET mfa_failed_attempts = IF(
                        mfa_failures_started_at IS NULL OR mfa_failures_started_at < UTC_TIMESTAMP() - INTERVAL :win1 MINUTE,
                        1, mfa_failed_attempts + 1),
                    mfa_failures_started_at = IF(
                        mfa_failures_started_at IS NULL OR mfa_failures_started_at < UTC_TIMESTAMP() - INTERVAL :win2 MINUTE,
                        UTC_TIMESTAMP(), mfa_failures_started_at),
                    mfa_locked_until = IF(
                        mfa_failed_attempts >= :max,
                        DATE_ADD(UTC_TIMESTAMP(), INTERVAL :mins MINUTE),
                        mfa_locked_until)
              WHERE id = :id',
        )->execute([
            'win1' => MfaService::USER_FAILURE_WINDOW_MINUTES,
            'win2' => MfaService::USER_FAILURE_WINDOW_MINUTES,
            'max' => $max, 'mins' => $lockMinutes, 'id' => $userId,
        ]);
    }

    public function resetMfaFailures(int $userId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE users SET mfa_failed_attempts = 0, mfa_failures_started_at = NULL, mfa_locked_until = NULL WHERE id = ?',
        )->execute([$userId]);
    }

    public function touchLastLogin(int $userId, string $at): void
    {
        $this->db->pdo()->prepare('UPDATE users SET last_login_at = :at WHERE id = :id')
            ->execute(['at' => $at, 'id' => $userId]);
    }
}
