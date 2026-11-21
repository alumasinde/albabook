<?php

declare(strict_types=1);

namespace Tests\Unit\Tenancy;

use App\Tenancy\TenantPolicyMatrix;
use PHPUnit\Framework\TestCase;

final class TenantPolicyMatrixTest extends TestCase
{
    public function testCodeDefaultsApplyWhenTheTableIsEmpty(): void
    {
        $matrix = new TenantPolicyMatrix([]);

        self::assertTrue($matrix->allows('pending', 'dashboard.view'));
        self::assertTrue($matrix->allows('pending', 'onboarding.manage'));
        self::assertFalse($matrix->allows('pending', 'booking.create'));

        self::assertTrue($matrix->allows('trial', 'booking.create'));
        self::assertFalse($matrix->allows('trial', 'billing.view'));

        self::assertTrue($matrix->allows('active', 'anything.at.all'), 'active allows everything');

        self::assertFalse($matrix->allows('suspended', 'dashboard.view'));
        self::assertFalse($matrix->allows('archived', 'dashboard.view'));

        self::assertTrue($matrix->allows('cancelled', 'data.export'));
        self::assertFalse($matrix->allows('cancelled', 'booking.create'));
    }

    public function testDatabaseRowsReplaceTheDefaults(): void
    {
        $matrix = new TenantPolicyMatrix([
            ['status' => 'suspended', 'operation' => 'billing.view', 'allowed' => true],
            ['status' => 'pending', 'operation' => 'dashboard.view', 'allowed' => false],
        ]);

        self::assertTrue($matrix->allows('suspended', 'billing.view'));
        self::assertFalse($matrix->allows('pending', 'dashboard.view'));
        self::assertFalse($matrix->allows('pending', 'onboarding.manage'), 'defaults no longer apply');
        self::assertFalse($matrix->allows('active', 'dashboard.view'), 'a wildcard must be explicit');
    }

    public function testTheWildcardAndExplicitRowsCombine(): void
    {
        $matrix = new TenantPolicyMatrix([
            ['status' => 'trial', 'operation' => '*', 'allowed' => false],
            ['status' => 'trial', 'operation' => 'dashboard.view', 'allowed' => true],
            ['status' => 'trial', 'operation' => 'booking.create', 'allowed' => false],
        ]);

        self::assertTrue($matrix->allows('trial', 'dashboard.view'));
        self::assertFalse($matrix->allows('trial', 'booking.create'));
        self::assertFalse($matrix->allows('trial', 'business.manage'));
    }

    public function testAnUnknownStatusAllowsNothing(): void
    {
        self::assertFalse((new TenantPolicyMatrix([]))->allows('deleted', 'dashboard.view'));
    }
}
