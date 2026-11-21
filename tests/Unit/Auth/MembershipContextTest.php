<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Auth\MembershipContext;
use PHPUnit\Framework\TestCase;

final class MembershipContextTest extends TestCase
{
    public function testCanChecksAnExactPermissionOnly(): void
    {
        $member = self::member(['appointments.view'], ['receptionist']);

        self::assertTrue($member->can('appointments.view'));
        self::assertFalse($member->can('appointments.cancel'));
        self::assertFalse($member->can('APPOINTMENTS.VIEW'), 'permission identifiers are case-sensitive');
    }

    public function testNullBranchIdsMeansEveryBranch(): void
    {
        $member = self::member(['appointments.view'], ['owner'], null);

        self::assertTrue($member->canAccessBranch(null));
        self::assertTrue($member->canAccessBranch(7));
        self::assertTrue($member->canAccessBranch(99));
    }

    public function testAListRestrictsAccessToThoseBranches(): void
    {
        $member = self::member(['appointments.view'], ['branch_manager'], [2, 5]);

        self::assertTrue($member->canAccessBranch(2));
        self::assertTrue($member->canAccessBranch(5));
        self::assertFalse($member->canAccessBranch(3));
        self::assertTrue($member->canAccessBranch(null), 'a missing branch is not a scope violation');
    }

    public function testAnEmptyListAllowsNoSpecificBranch(): void
    {
        $member = self::member(['appointments.view'], ['staff'], []);

        self::assertFalse($member->canAccessBranch(1));
    }

    public function testMfaSensitivePermissionsAreDetected(): void
    {
        self::assertTrue(self::member(['billing.manage'], ['accountant'])->holdsAnyMfaSensitivePermission());
        self::assertTrue(self::member(['dashboard.view', 'users.manage'], ['business_administrator'])->holdsAnyMfaSensitivePermission());
        self::assertFalse(self::member(['dashboard.view', 'appointments.view'], ['receptionist'])->holdsAnyMfaSensitivePermission());
        self::assertFalse(self::member(['settings.view'], ['branch_manager'])->holdsAnyMfaSensitivePermission());
    }

    /**
     * @param list<string> $permissions
     * @param list<string> $roles
     * @param list<int>|null $branches
     */
    private static function member(array $permissions, array $roles, ?array $branches = null): MembershipContext
    {
        return new MembershipContext(
            tenantUserId: 1,
            userId: 2,
            tenantId: 3,
            roleSlugs: $roles,
            permissions: $permissions,
            branchIds: $branches,
        );
    }
}
