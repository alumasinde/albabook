<?php

declare(strict_types=1);

namespace App\Modules\Booking\Services;

use App\Core\Config\Config;
use App\Core\Database\Database;
use App\Modules\Billing\Services\FeatureGate;
use App\Modules\Notifications\Services\ChannelSender;
use App\Modules\Notifications\Services\RecipientNotAllowedException;
use App\Shared\Security\RateLimiter;
use App\Shared\Support\TokenGenerator;
use App\Shared\Support\UuidFactory;
use App\Shared\Validation\PhoneNumber;

/**
 * Customer phone verification for online bookings (update.md 1.7).
 *
 * Flow: send() texts a 6-digit code (stored only as an HMAC, 10-minute
 * lifetime, 5 attempts); verify() checks it and returns a short-lived signed
 * token bound to tenant + phone; the booking request carries that token and
 * tokenPhone() proves the phone was verified.
 *
 * Abuse limits (SMS pumping and brute force):
 *  - 3 codes per phone per 15 min, 10 per IP per hour, 300 per business per hour;
 *  - 5 wrong guesses burn a code; a new code invalidates older ones;
 *  - the platform SMS account only texts allowed prefixes (ChannelSender) and
 *    each code counts toward the plan's monthly SMS allowance.
 */
final class BookingVerificationService
{
    public const CODE_TTL_SECONDS = 600;
    public const TOKEN_TTL_SECONDS = 1800;
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly ChannelSender $sender,
        private readonly RateLimiter $limiter,
        private readonly TokenGenerator $tokens,
        private readonly UuidFactory $uuids,
        private readonly FeatureGate $features,
        private readonly \App\Modules\Notifications\Services\NotificationPolicy $policy,
    ) {}

    /**
     * SMS can deliver a code for this business right now: an SMS account
     * (own, or the platform's on a plan that includes SMS) and allowance left
     * this month. When it cannot, verification is skipped rather than making
     * online booking impossible; unverified bookings still get the safe
     * customer matching in ManualBookingService.
     */
    public function smsAvailable(int $tenantId): bool
    {
        return $this->policy->allows($tenantId, 'sms', 'verification_code') && $this->underSmsAllowance($tenantId);
    }

    /**
     * @return array{ok: bool, code?: string, message?: string, field?: string}
     *         code is an error code (RATE_LIMITED, INVALID_PHONE, SEND_FAILED)
     */
    public function send(int $tenantId, string $businessName, ?int $branchId, string $rawPhone, string $ip): array
    {
        $phone = PhoneNumber::normalise($rawPhone);
        if ($phone === null) {
            return ['ok' => false, 'code' => 'INVALID_PHONE', 'message' => 'Enter a valid phone number, e.g. 0712 345 678.'];
        }
        $contact = $this->contactHash($tenantId, $phone);
        foreach ([
            ['otp-phone:' . $contact, 3, 900],
            ['otp-ip:' . $ip, 10, 3600],
            ['otp-tenant:' . $tenantId, 300, 3600],
        ] as [$key, $limit, $window]) {
            if (!$this->limiter->hit($key, $limit, $window)->allowed) {
                return ['ok' => false, 'code' => 'RATE_LIMITED', 'message' => 'Too many codes requested. Wait a few minutes and try again.'];
            }
        }
        if (!$this->underSmsAllowance($tenantId)) {
            return ['ok' => false, 'code' => 'SEND_FAILED', 'message' => 'We could not send a code right now. Please call the business to book.'];
        }

        $code = $this->tokens->numericCode(6);
        $pdo = $this->db->pdo();
        // A new code replaces any outstanding one for this phone.
        $pdo->prepare(
            "UPDATE booking_verification_codes SET consumed_at = UTC_TIMESTAMP()
              WHERE tenant_id = :t AND contact_hash = :c AND purpose = 'booking' AND consumed_at IS NULL",
        )->execute(['t' => $tenantId, 'c' => $contact]);
        $pdo->prepare(
            "INSERT INTO booking_verification_codes (tenant_id, branch_id, contact_type, contact_hash, purpose, code_hash, expires_at)
             VALUES (:t, :b, 'phone', :c, 'booking', :h, UTC_TIMESTAMP() + INTERVAL " . self::CODE_TTL_SECONDS . ' SECOND)',
        )->execute(['t' => $tenantId, 'b' => $branchId, 'c' => $contact, 'h' => $this->codeHash($contact, $code)]);

        $text = sprintf('%s: your booking code is %s. It expires in 10 minutes. Never share it.', $businessName, $code);
        try {
            $this->sender->send($tenantId, 'sms', $phone, $text);
        } catch (RecipientNotAllowedException) {
            return ['ok' => false, 'code' => 'SEND_FAILED', 'field' => 'phone', 'message' => 'We can only text Kenyan numbers. Please call the business to book.'];
        } catch (\Throwable) {
            return ['ok' => false, 'code' => 'SEND_FAILED', 'message' => 'We could not send a code right now. Please try again or call the business.'];
        }
        $this->recordSent($tenantId, $phone);

        return ['ok' => true];
    }

    /** @return string|null signed verification token, or null when the code is wrong/expired */
    public function verify(int $tenantId, string $rawPhone, string $code): ?string
    {
        $phone = PhoneNumber::normalise($rawPhone);
        $code = preg_replace('/\D/', '', $code) ?? '';
        if ($phone === null || strlen($code) !== 6) {
            return null;
        }
        $contact = $this->contactHash($tenantId, $phone);

        return $this->db->transaction(function () use ($tenantId, $phone, $contact, $code): ?string {
            $stmt = $this->db->pdo()->prepare(
                "SELECT id, code_hash, attempts FROM booking_verification_codes
                  WHERE tenant_id = :t AND contact_hash = :c AND purpose = 'booking'
                    AND consumed_at IS NULL AND expires_at > UTC_TIMESTAMP()
                  ORDER BY id DESC LIMIT 1 FOR UPDATE",
            );
            $stmt->execute(['t' => $tenantId, 'c' => $contact]);
            $row = $stmt->fetch();
            if ($row === false || (int) $row['attempts'] >= self::MAX_ATTEMPTS) {
                return null;
            }
            if (!hash_equals((string) $row['code_hash'], $this->codeHash($contact, $code))) {
                // Burn the code on the last allowed failure.
                $this->db->pdo()->prepare(
                    'UPDATE booking_verification_codes
                        SET attempts = attempts + 1,
                            consumed_at = IF(attempts + 1 >= ' . self::MAX_ATTEMPTS . ', UTC_TIMESTAMP(), consumed_at)
                      WHERE id = :id',
                )->execute(['id' => (int) $row['id']]);

                return null;
            }
            $this->db->pdo()->prepare('UPDATE booking_verification_codes SET consumed_at = UTC_TIMESTAMP() WHERE id = :id')
                ->execute(['id' => (int) $row['id']]);

            return $this->issueToken($tenantId, $phone);
        });
    }

    /**
     * Whether this business asks customers to verify their phone. Only when
     * an SMS account can actually deliver the code: switching it on without
     * SMS must not make online booking impossible.
     *
     * @param array<string, mixed>|null $policy booking_policies row
     */
    public function required(int $tenantId, ?array $policy): bool
    {
        return ($policy['customer_verification'] ?? 'none') === 'otp_phone' && $this->smsAvailable($tenantId);
    }

    /**
     * Gate for a public booking request.
     *
     * @param array<string, mixed>|null $policy
     * @return array{verified: bool, error: ?array{code: string, message: string, fields: array<string, string>}}
     */
    public function gate(int $tenantId, ?array $policy, ?string $phone, string $token): array
    {
        $proven = $token !== '' ? $this->tokenPhone($tenantId, $token) : null;
        $verified = $proven !== null && $phone !== null && hash_equals($proven, $phone);
        if ($verified || !$this->required($tenantId, $policy)) {
            return ['verified' => $verified, 'error' => null];
        }
        if ($phone === null) {
            return ['verified' => false, 'error' => [
                'code' => 'VERIFICATION_REQUIRED', 'message' => 'Enter your phone number so we can text you a code.',
                'fields' => ['phone' => 'A phone number is required.'],
            ]];
        }

        return ['verified' => false, 'error' => [
            'code' => 'VERIFICATION_REQUIRED', 'message' => 'Confirm your phone number with the code we text you.',
            'fields' => ['phone' => 'Verify this number first.'],
        ]];
    }

    /** Phone (E.164) proven by a valid token for this tenant, else null. */
    public function tokenPhone(int $tenantId, string $token): ?string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$body, $mac] = $parts;
        if (!hash_equals($this->sign($body), $mac)) {
            return null;
        }
        $data = json_decode((string) base64_decode(strtr($body, '-_', '+/'), true), true);
        if (!is_array($data) || ($data['t'] ?? null) !== $tenantId || !is_string($data['p'] ?? null) || (int) ($data['e'] ?? 0) < time()) {
            return null;
        }

        return $data['p'];
    }

    private function issueToken(int $tenantId, string $phone): string
    {
        $body = TokenGenerator::base64Url(json_encode(['t' => $tenantId, 'p' => $phone, 'e' => time() + self::TOKEN_TTL_SECONDS], JSON_THROW_ON_ERROR));

        return $body . '.' . $this->sign($body);
    }

    private function sign(string $body): string
    {
        return TokenGenerator::base64Url(hash_hmac('sha256', 'booking-otp|' . $body, $this->config->appKey(), true));
    }

    private function contactHash(int $tenantId, string $phone): string
    {
        return hash_hmac('sha256', 'otp-contact|' . $tenantId . '|' . $phone, $this->config->appKey());
    }

    private function codeHash(string $contact, string $code): string
    {
        return hash_hmac('sha256', 'otp-code|' . $contact . '|' . $code, $this->config->appKey());
    }

    /** Codes count toward the plan's monthly SMS allowance, like every other SMS. */
    private function underSmsAllowance(int $tenantId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM notification_queue
              WHERE tenant_id = :t AND channel = 'sms' AND status = 'sent'
                AND sent_at >= DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-01')",
        );
        $stmt->execute(['t' => $tenantId]);

        return $this->features->underLimit($tenantId, 'sms_messages', (int) $stmt->fetchColumn());
    }

    /** Delivery-log row (no code in it) so the send is metered and visible. */
    private function recordSent(int $tenantId, string $phone): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO notification_queue (uuid, tenant_id, appointment_id, channel, recipient, payload, status, attempts, sent_at)
             VALUES (:u, :t, NULL, 'sms', :r, :p, 'sent', 1, UTC_TIMESTAMP())",
        )->execute([
            'u' => $this->uuids->generate(),
            't' => $tenantId,
            'r' => $phone,
            'p' => json_encode(['event' => 'verification_code'], JSON_THROW_ON_ERROR),
        ]);
    }
}
