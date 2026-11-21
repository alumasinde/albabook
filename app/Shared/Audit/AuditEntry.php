<?php

declare(strict_types=1);

namespace App\Shared\Audit;

use App\Core\Http\Request;

/**
 * One audited event. before/after are masked by AuditLogger before storage.
 */
final readonly class AuditEntry
{
    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public function __construct(
        public string $action,
        public ?int $tenantId = null,
        public string $actorType = 'system',
        public ?int $actorId = null,
        public ?string $targetType = null,
        public ?string $targetId = null,
        public array $before = [],
        public array $after = [],
        public ?Request $request = null,
    ) {}
}
