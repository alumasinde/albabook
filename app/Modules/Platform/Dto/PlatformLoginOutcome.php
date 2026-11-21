<?php

declare(strict_types=1);

namespace App\Modules\Platform\Dto;

final readonly class PlatformLoginOutcome
{
    public function __construct(
        public int $platformUserId,
        public IssuedPlatformSession $session,
        public bool $mfaEnrolled,
    ) {}
}
