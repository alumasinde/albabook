<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Command;
use App\Console\Output;
use App\Core\Config\Config;
use App\Shared\I18n\Translator;

/**
 * Reports translation gaps (decisions.md D-017).
 *
 * Customer-facing domains must exist in every customer locale. Without
 * --strict, gaps are reported but the command succeeds (they fall back to
 * English); with --strict, any gap fails, for use before launch.
 */
final class LangCheckCommand implements Command
{
    /** Domains rendered on the booking host and in customer messages. */
    private const CUSTOMER_DOMAINS = ['common', 'booking', 'errors', 'lookup'];

    public function __construct(
        private readonly Translator $translator,
        private readonly Config $config,
    ) {
    }

    public function name(): string
    {
        return 'lang:check';
    }

    public function description(): string
    {
        return 'List missing translations for customer-facing locales [--strict]';
    }

    public function run(array $args, Output $output): int
    {
        $fallback = $this->config->string('i18n.fallback_locale', 'en');
        $reference = array_filter(
            $this->translator->all($fallback),
            static fn (string $key): bool => in_array(explode('.', $key, 2)[0], self::CUSTOMER_DOMAINS, true),
            ARRAY_FILTER_USE_KEY,
        );

        $missingTotal = 0;
        foreach ($this->config->array('i18n.customer_locales') as $locale) {
            if (!is_string($locale) || $locale === $fallback) {
                continue;
            }
            $present = $this->translator->all($locale);
            $missing = array_diff_key($reference, $present);
            $unknown = array_diff_key(array_filter($present, static fn (string $k): bool => in_array(explode('.', $k, 2)[0], self::CUSTOMER_DOMAINS, true), ARRAY_FILTER_USE_KEY), $reference);

            $output->line(sprintf('%s: %d/%d customer-facing strings translated', $locale, count($reference) - count($missing), count($reference)));
            foreach (array_keys($missing) as $key) {
                $output->line("  missing  {$key}");
            }
            foreach (array_keys($unknown) as $key) {
                $output->warn("  unknown  {$key} (not in {$fallback})");
            }
            $missingTotal += count($missing) + count($unknown);
        }

        if ($missingTotal > 0 && in_array('--strict', $args, true)) {
            $output->error("{$missingTotal} translation problem(s).");

            return 1;
        }

        return 0;
    }
}
