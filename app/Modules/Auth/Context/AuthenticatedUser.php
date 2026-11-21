<?php

declare(strict_types=1);

namespace App\Modules\Auth\Context;

final readonly class AuthenticatedUser
{
    public function __construct(
        public int $id,
        public string $uuid,
        public string $email,
        public string $firstName,
        public string $lastName,
        public bool $emailVerified,
        public string $locale,
        public int $sessionId,
        public ?int $activeTenantId,
        public bool $mfaVerified,
        public string $sessionToken,
    ) {}

    public function displayName(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }
}
