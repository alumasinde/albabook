<?php

declare(strict_types=1);

namespace App\Modules\Platform\Context;

final readonly class PlatformUser
{
    public function __construct(
        public int $id,
        public string $uuid,
        public string $email,
        public string $firstName,
        public string $lastName,
        public int $sessionId,
        public string $sessionToken,
        public bool $mfaVerified,
        public bool $mfaEnrolled,
    ) {}

    public function displayName(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }
}
