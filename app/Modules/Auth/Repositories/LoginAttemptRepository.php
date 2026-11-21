<?php

declare(strict_types=1);

namespace App\Modules\Auth\Repositories;

use App\Core\Database\Database;

/**
 * Login telemetry used for the soft per-account delay and the hard per-IP cap
 * (auth.md §8b). Nothing here locks an account out permanently.
 */
final class LoginAttemptRepository
{
    public function __construct(private readonly Database $db) {}

    public function record(?string $email, string $ipAddress, bool $successful, string $userAgent): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO login_attempts (email, ip_address, successful, user_agent)
             VALUES (:email, :ip, :successful, :ua)',
        )->execute([
            'email' => $email === null || $email === '' ? null : mb_substr($email, 0, 254),
            'ip' => $ipAddress === '' ? null : $ipAddress,
            'successful' => $successful ? 1 : 0,
            'ua' => mb_substr($userAgent, 0, 255),
        ]);
    }

    public function recentFailuresForEmail(string $email, int $minutes): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE email = :email AND successful = 0
               AND attempted_at > UTC_TIMESTAMP() - INTERVAL :minutes MINUTE',
        );
        $stmt->bindValue('email', $email);
        $stmt->bindValue('minutes', max(1, $minutes), \PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public function recentFailuresForIp(string $ipAddress, int $minutes): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE ip_address = :ip AND successful = 0
               AND attempted_at > UTC_TIMESTAMP() - INTERVAL :minutes MINUTE',
        );
        $stmt->bindValue('ip', $ipAddress);
        $stmt->bindValue('minutes', max(1, $minutes), \PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /** Housekeeping for a scheduled job. */
    public function pruneOlderThanDays(int $days): void
    {
        $this->db->pdo()->prepare('DELETE FROM login_attempts WHERE attempted_at < UTC_TIMESTAMP() - INTERVAL :days DAY')
            ->execute(['days' => max(1, $days)]);
    }
}
