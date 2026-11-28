<?php

declare(strict_types=1);

namespace App\Shared\Validation;

/**
 * Phone numbers to E.164. Kenyan numbers are the default at launch:
 * 0712…, 712…, 254712…, +254 0712… and 00254712… all become +254712….
 * Other countries must carry a leading + (or the 00 international prefix).
 *
 * Kenyan numbers are also checked for shape: nine national digits starting
 * with 1 or 7 (mobile ranges), so typos like +2540712… or a 7-digit number
 * are rejected instead of being stored and then failing at send time.
 */
final class PhoneNumber
{
    public static function normalise(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/[\s().\/-]+/', '', trim($value)) ?? '';
        if ($value === '') {
            return null;
        }
        // 00 international prefix → +
        if (str_starts_with($value, '00')) {
            $value = '+' . substr($value, 2);
        }

        if (preg_match('/^0([17]\d{8})$/', $value, $m) === 1) {
            $value = '+254' . $m[1];
        } elseif (preg_match('/^([17]\d{8})$/', $value, $m) === 1) {
            $value = '+254' . $m[1];
        } elseif (preg_match('/^\+?2540?(\d{9})$/', $value, $m) === 1) {
            // 254712…, +254712…, and the common "+254 0712…" typo.
            $value = '+254' . $m[1];
        }

        if (preg_match('/^\+[1-9]\d{7,14}$/', $value) !== 1) {
            return null;
        }
        if (str_starts_with($value, '+254') && preg_match('/^\+254[17]\d{8}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }
}
