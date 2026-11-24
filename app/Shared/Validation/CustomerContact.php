<?php

declare(strict_types=1);

namespace App\Shared\Validation;

/**
 * Shared validation for customer contact details on public booking paths
 * (booking page, widget, public API). Invalid emails used to be stored and
 * then fail five times at send; phones are normalised to E.164 so customer
 * matching and SMS delivery work from the raw input ("0712…").
 */
final class CustomerContact
{
    private const MAX_NAME = 150;
    private const MAX_EMAIL = 254;
    private const MAX_NOTES = 1000;

    /**
     * Validates and normalises in place. Returns field => message errors,
     * keyed with $prefix (e.g. "customer." for the API).
     *
     * @param array{name: string, phone: ?string, email: ?string, notes: ?string} $customer
     * @return array<string, string>
     */
    public static function validate(array &$customer, string $prefix = '', bool $requireContact = true): array
    {
        $errors = [];

        if ($customer['name'] === '') {
            $errors[$prefix . 'name'] = 'Tell us your name.';
        } elseif (mb_strlen($customer['name']) > self::MAX_NAME) {
            $errors[$prefix . 'name'] = 'That name is too long.';
        }

        if ($customer['email'] !== null) {
            $email = strtolower($customer['email']);
            if (strlen($email) > self::MAX_EMAIL || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors[$prefix . 'email'] = 'Enter a valid email address.';
            } else {
                $customer['email'] = $email;
            }
        }

        if ($customer['phone'] !== null) {
            $phone = PhoneNumber::normalise($customer['phone']);
            if ($phone === null) {
                $errors[$prefix . 'phone'] = 'Enter a valid phone number, e.g. 0712 345 678.';
            } else {
                $customer['phone'] = $phone;
            }
        }

        if ($requireContact && $customer['phone'] === null && $customer['email'] === null) {
            $errors[$prefix . 'phone'] = 'Leave a phone number or an email so we can confirm.';
        }

        if ($customer['notes'] !== null && mb_strlen($customer['notes']) > self::MAX_NOTES) {
            $errors[$prefix . 'notes'] = 'Notes can be up to ' . self::MAX_NOTES . ' characters.';
        }

        return $errors;
    }
}
