<?php

declare(strict_types=1);

namespace App\Modules\Auth\Dto;

final readonly class IssuedSession
{
    public function __construct(
        public int $id,
        public string $token,
        public int $expiresAt,
    ) {}
}
