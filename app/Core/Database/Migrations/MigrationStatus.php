<?php

declare(strict_types=1);

namespace App\Core\Database\Migrations;

final readonly class MigrationStatus
{
    public const APPLIED = 'applied';
    public const PENDING = 'pending';
    public const MODIFIED = 'modified';   // applied, but the file changed since
    public const MISSING = 'missing';     // recorded as applied, file no longer exists

    public function __construct(
        public string $version,
        public string $state,
        public ?string $appliedAt = null,
    ) {
    }
}
