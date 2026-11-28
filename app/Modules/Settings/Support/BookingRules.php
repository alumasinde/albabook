<?php

declare(strict_types=1);

namespace App\Modules\Settings\Support;

/**
 * The business-editable booking rules (booking_policies, tenant-wide row).
 *
 * Only rules the engine actually honours are exposed. A null limit means
 * "no limit" (no cancellation cutoff, unlimited advance booking, …).
 */
final class BookingRules
{
    /** Effective values when the business has never saved its rules. */
    public const DEFAULTS = [
        'confirmation_mode' => 'auto',
        'customer_verification' => 'none',
        'pending_expiry_minutes' => 720,
        'min_notice_minutes' => 30,
        'max_advance_days' => null,
        'slot_interval_minutes' => 30,
        'cancellation_cutoff_minutes' => null,
        'reschedule_cutoff_minutes' => null,
        'max_reschedules' => null,
    ];

    /** [min, max] for each integer rule; null = unlimited is allowed where noted. */
    private const RANGES = [
        'pending_expiry_minutes' => [15, 10080, false],
        'min_notice_minutes' => [0, 20160, false],
        'max_advance_days' => [1, 730, true],
        'slot_interval_minutes' => [5, 240, false],
        'cancellation_cutoff_minutes' => [0, 20160, true],
        'reschedule_cutoff_minutes' => [0, 20160, true],
        'max_reschedules' => [0, 20, true],
    ];

    /** Pending requests are never held longer than this, whatever is stored. */
    public const DEFAULT_PENDING_EXPIRY = 720;

    /**
     * Stored row (or null) → full rules with defaults filled in.
     *
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>
     */
    public static function fromRow(?array $row): array
    {
        $out = self::DEFAULTS;
        if ($row === null) {
            return $out;
        }
        if (in_array($row['confirmation_mode'] ?? null, ['auto', 'manual'], true)) {
            $out['confirmation_mode'] = $row['confirmation_mode'];
        }
        if (in_array($row['customer_verification'] ?? null, ['none', 'otp_phone'], true)) {
            $out['customer_verification'] = $row['customer_verification'];
        }
        foreach (self::RANGES as $key => [, , $nullable]) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            if ($row[$key] === null) {
                // A stored NULL only means "unlimited" for nullable rules;
                // for the others it means "use the default".
                if ($nullable) {
                    $out[$key] = null;
                }
                continue;
            }
            $out[$key] = (int) $row[$key];
        }

        return $out;
    }

    /**
     * Validate a PATCH body. Returns [rules, fieldErrors].
     *
     * @param array<string, mixed> $body
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public static function normalise(array $body): array
    {
        $rules = [];
        $fields = [];

        $mode = $body['confirmation_mode'] ?? 'auto';
        if (!in_array($mode, ['auto', 'manual'], true)) {
            $fields['confirmation_mode'] = 'Choose automatic or manual confirmation.';
        }
        $rules['confirmation_mode'] = $mode;

        // SMS code before an online booking (update.md 1.7). otp_email is in
        // the schema but not offered: an e-mail code adds friction without
        // proving the phone that reminders go to.
        $verification = $body['customer_verification'] ?? 'none';
        if (!in_array($verification, ['none', 'otp_phone'], true)) {
            $fields['customer_verification'] = 'Choose whether customers confirm their phone.';
        }
        $rules['customer_verification'] = $verification;

        foreach (self::RANGES as $key => [$min, $max, $nullable]) {
            $value = $body[$key] ?? null;
            if ($value === '' || $value === null) {
                if ($nullable) {
                    $rules[$key] = null;
                    continue;
                }
                $fields[$key] = 'Required.';
                continue;
            }
            if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
                $fields[$key] = 'Enter a whole number.';
                continue;
            }
            $value = (int) $value;
            if ($value < $min || $value > $max) {
                $fields[$key] = sprintf('Enter a value between %d and %d.', $min, $max);
                continue;
            }
            $rules[$key] = $value;
        }

        return [$rules, $fields];
    }
}
