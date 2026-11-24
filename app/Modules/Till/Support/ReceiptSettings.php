<?php

declare(strict_types=1);

namespace App\Modules\Till\Support;

/**
 * A business's receipt design (tenant_settings.preferences.receipt).
 * Everything is optional and falls back to the defaults below, so receipts
 * look professional before anyone opens Settings. normalise() is the single
 * validator for both the settings API and stored values.
 */
final class ReceiptSettings
{
    /** Header mark choices: a Font Awesome icon, the business initial, or nothing. */
    public const LOGOS = [
        'spa' => 'Lotus (spa)',
        'scissors' => 'Scissors',
        'hand-sparkles' => 'Sparkles (nails & beauty)',
        'leaf' => 'Leaf',
        'gem' => 'Gem',
        'star' => 'Star',
        'heart' => 'Heart',
        'dumbbell' => 'Dumbbell',
        'tooth' => 'Tooth',
        'user-doctor' => 'Clinician',
        'initial' => 'Business initial',
        'none' => 'No logo',
    ];

    public const PAPER = ['80mm' => '80 mm thermal', '58mm' => '58 mm thermal'];

    /** @var array<string, bool|string> */
    public const DEFAULTS = [
        'logo' => 'spa',
        'tagline' => 'Look good • Feel great',
        'heading' => 'Payment receipt',
        'paper' => '80mm',
        'show_address' => true,
        'show_phone' => true,
        'show_email' => false,
        'show_kra_pin' => true,
        'show_staff' => true,
        'show_customer' => true,
        'show_levies' => true,
        'show_qr' => true,
        'mask_references' => true,
        'show_powered_by' => true,
    ];

    private const BOOLEANS = [
        'show_address', 'show_phone', 'show_email', 'show_kra_pin', 'show_staff',
        'show_customer', 'show_levies', 'show_qr', 'mask_references', 'show_powered_by',
    ];

    /**
     * Stored/legacy values → complete, valid settings (invalid entries fall
     * back to defaults silently).
     * @param array<string, mixed> $stored
     * @return array<string, bool|string>
     */
    public static function fromStored(array $stored): array
    {
        [$settings] = self::normalise($stored + self::DEFAULTS);

        return $settings;
    }

    /**
     * Validates input over the defaults.
     * @param array<string, mixed> $input
     * @return array{0: array<string, bool|string>, 1: array<string, string>} [settings, field errors]
     */
    public static function normalise(array $input): array
    {
        $out = self::DEFAULTS;
        $errors = [];

        $logo = (string) ($input['logo'] ?? self::DEFAULTS['logo']);
        if (array_key_exists($logo, self::LOGOS)) {
            $out['logo'] = $logo;
        } else {
            $errors['logo'] = 'Choose a logo from the list.';
        }

        $paper = (string) ($input['paper'] ?? self::DEFAULTS['paper']);
        if (array_key_exists($paper, self::PAPER)) {
            $out['paper'] = $paper;
        } else {
            $errors['paper'] = 'Choose a paper size.';
        }

        foreach (['tagline' => 60, 'heading' => 30] as $key => $max) {
            $value = trim((string) ($input[$key] ?? ''));
            if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1 || mb_strlen($value) > $max) {
                $errors[$key] = "Up to {$max} characters, single line.";
            } else {
                // An empty heading falls back to the default; an empty tagline hides it.
                $out[$key] = $key === 'heading' && $value === '' ? (string) self::DEFAULTS['heading'] : $value;
            }
        }

        foreach (self::BOOLEANS as $key) {
            if (array_key_exists($key, $input)) {
                $out[$key] = filter_var($input[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return [$out, $errors];
    }

    /**
     * Payment reference masking: "RYH3KX9PD4" → "RYH3••••D4". Enough remains
     * for staff and the customer to match their M-Pesa SMS; not enough to
     * reuse the full code from a photographed or discarded receipt.
     */
    public static function maskReference(string $reference): string
    {
        $reference = trim($reference);
        $length = mb_strlen($reference);
        if ($length <= 4) {
            return str_repeat('•', $length);
        }
        if ($length <= 7) {
            return mb_substr($reference, 0, 1) . str_repeat('•', $length - 2) . mb_substr($reference, -1);
        }

        return mb_substr($reference, 0, 4) . '••••' . mb_substr($reference, -2);
    }
}
