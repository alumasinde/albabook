<?php

declare(strict_types=1);

namespace App\Shared\Validation;

/**
 * Kenyan numbers are the default at launch: 0712… and 2547… both become
 * +2547…. International input must already carry a leading +.
 */
final class PhoneNumber
{
    public static function normalise(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/[\s().-]+/', '', trim($value)) ?? '';
        if ($value === '') {
            return null;
        }

        if (preg_match('/^0(\d{9})$/', $value, $m) === 1) {
            $value = '+254' . $m[1];
        } elseif (preg_match('/^254(\d{9})$/', $value, $m) === 1) {
            $value = '+254' . $m[1];
        }

        return preg_match('/^\+[1-9]\d{7,14}$/', $value) === 1 ? $value : null;
    }
}
