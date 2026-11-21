<?php

declare(strict_types=1);

namespace App\Shared\I18n;

use App\Core\Http\RequestContext;

/**
 * Translations from lang/{locale}/{domain}.php (decisions.md D-017).
 *
 * trans('booking.confirm_title') looks in lang/<locale>/booking.php for the
 * key "confirm_title" (nested keys use dots). Missing strings fall back to the
 * fallback locale, then to the key itself so a gap is visible, never fatal.
 * Placeholders use ":name" syntax.
 */
final class Translator
{
    /** @var array<string, array<string, array<mixed>>> locale => domain => strings */
    private array $loaded = [];

    public function __construct(
        private readonly string $path,
        private readonly string $fallbackLocale,
        private readonly RequestContext $context,
    ) {}

    /** @param array<string, string|int> $replace */
    public function trans(string $key, array $replace = [], ?string $locale = null): string
    {
        $locale ??= $this->context->locale;
        $line = $this->find($key, $locale) ?? $this->find($key, $this->fallbackLocale) ?? $key;

        // Longer names first so ":pages" is not consumed by ":page".
        uksort($replace, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($replace as $name => $value) {
            $line = str_replace(':' . $name, (string) $value, $line);
        }

        return $line;
    }

    public function has(string $key, string $locale): bool
    {
        return $this->find($key, $locale) !== null;
    }

    /** @return array<string, string> flattened "domain.key" => string for one locale */
    public function all(string $locale): array
    {
        $result = [];
        foreach (glob($this->path . '/' . $locale . '/*.php') ?: [] as $file) {
            $domain = basename($file, '.php');
            foreach (self::flatten($this->domain($locale, $domain)) as $key => $value) {
                $result["{$domain}.{$key}"] = $value;
            }
        }
        ksort($result);

        return $result;
    }

    private function find(string $key, string $locale): ?string
    {
        if (preg_match('/^[a-z0-9_]+$/', $locale) !== 1 || !str_contains($key, '.')) {
            return null;
        }
        [$domain, $rest] = explode('.', $key, 2);
        $value = $this->domain($locale, $domain);
        foreach (explode('.', $rest) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return is_string($value) ? $value : null;
    }

    /** @return array<mixed> */
    private function domain(string $locale, string $domain): array
    {
        if (!isset($this->loaded[$locale][$domain])) {
            $file = $this->path . '/' . $locale . '/' . $domain . '.php';
            $strings = preg_match('/^[a-z0-9_]+$/', $domain) === 1 && is_file($file) ? require $file : [];
            $this->loaded[$locale][$domain] = is_array($strings) ? $strings : [];
        }

        return $this->loaded[$locale][$domain];
    }

    /**
     * @param array<mixed> $values
     * @return array<string, string>
     */
    private static function flatten(array $values, string $prefix = ''): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            $full = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            if (is_array($value)) {
                $result += self::flatten($value, $full);
            } elseif (is_string($value)) {
                $result[$full] = $value;
            }
        }

        return $result;
    }
}
