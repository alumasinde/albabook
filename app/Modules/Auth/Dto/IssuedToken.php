<?php

declare(strict_types=1);

namespace App\Modules\Auth\Dto;

final readonly class IssuedToken
{
    public function __construct(
        public string $token,
        public int $expiresAt,
    ) {}
}
