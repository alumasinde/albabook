<?php

declare(strict_types=1);

namespace App\Modules\Auth\Context;

use App\Auth\MembershipContext;

final class CurrentMembership
{
    public ?MembershipContext $context = null;

    public function set(?MembershipContext $context): void
    {
        $this->context = $context;
    }

    public function get(): ?MembershipContext
    {
        return $this->context;
    }
}
