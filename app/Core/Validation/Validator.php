<?php

declare(strict_types=1);

namespace App\Core\Validation;

use App\Shared\Validation\PhoneNumber;

/**
 * Small rule engine for form input. It returns trimmed values and throws a
 * ValidationException carrying field errors, so controllers only deal with
 * success or a form to re-render.
 */
final class Validator
{
    /**
     * @param array<string, mixed>              $input
     * @param array<string, list<string>>        $rules
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function validate(array $input, array $rules): array
    {
        $clean = [];
        $errors = [];
        $old = $this->safeOld($input);

        foreach ($rules as $field => $fieldRules) {
            $value = $input[$field] ?? null;
            $nullable = in_array('nullable', $fieldRules, true);

            if ($value === null || (is_string($value) && trim($value) === '')) {
                if (in_array('required', $fieldRules, true) && !$nullable) {
                    $errors[$field] = 'This field is required.';
                } elseif (in_array('accepted', $fieldRules, true) && !$this->isAccepted($value)) {
                    $errors[$field] = 'Please accept to continue.';
                } else {
                    $clean[$field] = $nullable ? null : '';
                }
                continue;
            }

            $value = is_string($value) ? trim($value) : $value;

            foreach ($fieldRules as $rule) {
                $error = $this->applyRule($field, $value, $rule, $input);
                if ($error !== null) {
                    $errors[$field] = $error;
                    break;
                }
            }

            $clean[$field] = $value;
        }

        if ($errors !== []) {
            throw new ValidationException($errors, $old);
        }

        return $clean;
    }

    /** @param array<string, mixed> $input */
    private function applyRule(string $field, mixed $value, string $rule, array $input): ?string
    {
        if ($rule === 'required' || $rule === 'nullable') {
            return null;
        }
        if ($rule === 'string') {
            return is_string($value) ? null : 'This must be text.';
        }
        if ($rule === 'email') {
            return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false && mb_strlen($value) <= 254
                ? null
                : 'Enter a valid email address.';
        }
        if ($rule === 'accepted') {
            return $this->isAccepted($value) ? null : 'Please accept to continue.';
        }
        if ($rule === 'phone') {
            return PhoneNumber::normalise(is_string($value) ? $value : null) !== null
                ? null
                : 'Enter a valid phone number, e.g. +254712345678.';
        }
        if (str_starts_with($rule, 'min:')) {
            return is_string($value) && mb_strlen($value) >= (int) substr($rule, 4)
                ? null
                : 'Must be at least ' . (int) substr($rule, 4) . ' characters.';
        }
        if (str_starts_with($rule, 'max:')) {
            return is_string($value) && mb_strlen($value) <= (int) substr($rule, 4)
                ? null
                : 'Must be at most ' . (int) substr($rule, 4) . ' characters.';
        }
        if ($rule === 'confirmed') {
            return (string) ($input[$field . '_confirmation'] ?? '') === (string) $value
                ? null
                : 'The values do not match.';
        }
        if (str_starts_with($rule, 'in:')) {
            $allowed = explode(',', substr($rule, 3));

            return in_array((string) $value, $allowed, true) ? null : 'Choose one of the offered options.';
        }
        if (str_starts_with($rule, 'regex:')) {
            $pattern = substr($rule, 6);

            return @preg_match($pattern, (string) $value) === 1 ? null : 'The format is not valid.';
        }

        throw new \LogicException("Unknown validation rule \"{$rule}\".");
    }

    private function isAccepted(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'on', 'yes', 'true'], true);
    }

    /**
     * Never echo passwords back into a form.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function safeOld(array $input): array
    {
        unset($input['password'], $input['password_confirmation'], $input['current_password'], $input['_csrf']);

        return $input;
    }
}
