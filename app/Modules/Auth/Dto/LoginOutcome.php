<?php

declare(strict_types=1);

namespace App\Modules\Auth\Dto;

final readonly class LoginOutcome
{
    public function __construct(
        public int $userId,
        public IssuedSession $session,
        public bool $mfaPending,
    ) {}
}
