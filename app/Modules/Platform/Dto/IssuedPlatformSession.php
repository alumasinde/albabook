<?php

declare(strict_types=1);

namespace App\Modules\Platform\Dto;

final readonly class IssuedPlatformSession
{
    public function __construct(
        public int $id,
        public string $token,
        public int $expiresAt,
    ) {}
}
