<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Core\Config\Config;
use App\Core\Validation\ValidationException;

/**
 * Slug format and reserved words (multi-tenancy.md §4). Database claimability
 * (another tenant, slug history) is checked by TenantRepository, which owns
 * the transactional view.
 */
final class SlugPolicy
{
    public function __construct(private readonly Config $config) {}

    public function normalise(string $slug): string
    {
        return strtolower(trim($slug));
    }

    public function isReserved(string $slug): bool
    {
        /** @var list<string> $reserved */
        $reserved = $this->config->array('tenancy.reserved_slugs');

        return in_array($slug, $reserved, true);
    }

    public function isValid(string $slug): bool
    {
        $slug = $this->normalise($slug);
        $min = $this->config->int('tenancy.slug.min_length', 3);
        $max = $this->config->int('tenancy.slug.max_length', 60);

        return mb_strlen($slug) >= $min
            && mb_strlen($slug) <= $max
            && preg_match($this->config->string('tenancy.slug.pattern'), $slug) === 1
            && !$this->isReserved($slug);
    }

    /** @throws ValidationException */
    public function assertValid(string $slug): string
    {
        $slug = $this->normalise($slug);
        $errors = [];

        if ($this->isReserved($slug)) {
            $errors['slug'] = 'That address is reserved. Choose another.';
        } elseif (!$this->isValid($slug)) {
            $errors['slug'] = 'Use 3–60 lowercase letters, numbers and single hyphens (no leading or trailing hyphen).';
        }

        if ($errors !== []) {
            throw new ValidationException($errors, ['slug' => $slug]);
        }

        return $slug;
    }

    /** @return list<string> */
    public function reserved(): array
    {
        /** @var list<string> $reserved */
        $reserved = $this->config->array('tenancy.reserved_slugs');

        return $reserved;
    }
}
