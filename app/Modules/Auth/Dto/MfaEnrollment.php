<?php

declare(strict_types=1);

namespace App\Modules\Auth\Dto;

final readonly class MfaEnrollment
{
    public function __construct(
        public int $factorId,
        public string $secret,
        public string $provisioningUri,
    ) {}
}
