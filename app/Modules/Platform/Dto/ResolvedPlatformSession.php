<?php

declare(strict_types=1);

namespace App\Modules\Platform\Dto;

final readonly class ResolvedPlatformSession
{
    public function __construct(
        public int $sessionId,
        public int $userId,
        public string $userUuid,
        public string $email,
        public string $firstName,
        public string $lastName,
        public bool $mfaVerified,
        public bool $mfaEnrolled,
        public string $token,
        public \DateTimeImmutable $lastSeenAt,
    ) {}
}
