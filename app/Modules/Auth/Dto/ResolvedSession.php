<?php

declare(strict_types=1);

namespace App\Modules\Auth\Dto;

final readonly class ResolvedSession
{
    public function __construct(
        public int $sessionId,
        public int $userId,
        public string $userUuid,
        public string $email,
        public string $firstName,
        public string $lastName,
        public bool $emailVerified,
        public string $locale,
        public ?int $activeTenantId,
        public bool $mfaVerified,
        public ?\DateTimeImmutable $mfaVerifiedAt,
        public string $token,
        public \DateTimeImmutable $lastSeenAt,
    ) {}

    public function displayName(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }

    /** Step-up window for API keys, roles, billing, exports and MFA changes. */
    public function hasFreshMfa(int $minutes, \DateTimeImmutable $now): bool
    {
        return $this->mfaVerifiedAt !== null
            && $this->mfaVerifiedAt->getTimestamp() >= $now->getTimestamp() - ($minutes * 60);
    }
}
