<?php

declare(strict_types=1);

namespace App\Modules\Auth\Dto;

final readonly class RememberedLogin
{
    public function __construct(
        public ResolvedSession $session,
        public IssuedToken $remember,
    ) {}
}
