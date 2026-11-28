<?php

declare(strict_types=1);

namespace Tests\Unit\Appointments;

use App\Modules\Appointments\Services\AppointmentTransitionService;
use PHPUnit\Framework\TestCase;

/**
 * Pins the appointment lifecycle graph and its permission map. The graph
 * is the single source of truth for who may move an appointment where —
 * a silent edge change (e.g. pending → completed) would bypass check-in,
 * the till and the audit trail.
 */
final class TransitionGraphTest extends TestCase
{
    public function testHappyPathIncludesInService(): void
    {
        self::assertSame(['confirmed', 'rejected', 'cancelled'], AppointmentTransitionService::allowed('pending'));
        self::assertContains('checked_in', AppointmentTransitionService::allowed('confirmed'));
        self::assertContains('no_show', AppointmentTransitionService::allowed('confirmed'));
        self::assertSame(
            ['in_service', 'completed', 'cancelled', 'no_show'],
            AppointmentTransitionService::allowed('checked_in'),
        );
        self::assertSame(['completed', 'cancelled'], AppointmentTransitionService::allowed('in_service'));
    }

    public function testTerminalStatusesHaveNoEdges(): void
    {
        foreach (['completed', 'cancelled', 'no_show', 'rejected', 'expired'] as $status) {
            self::assertSame([], AppointmentTransitionService::allowed($status), "{$status} must be terminal");
        }
    }

    public function testNoSkippingTheFloor(): void
    {
        // pending can never reach service/paid states directly.
        self::assertNotContains('in_service', AppointmentTransitionService::allowed('pending'));
        self::assertNotContains('completed', AppointmentTransitionService::allowed('pending'));
        self::assertNotContains('in_service', AppointmentTransitionService::allowed('confirmed'));
    }

    public function testSalonPolicyUnlocksDirectStartOnly(): void
    {
        // require_check_in = false is the only documented unlock — it adds
        // confirmed → in_service for salons and nothing else, anywhere.
        self::assertNotContains('in_service', AppointmentTransitionService::allowed('confirmed'));
        self::assertContains('in_service', AppointmentTransitionService::allowed('confirmed', ['require_check_in' => false]));
        self::assertNotContains('in_service', AppointmentTransitionService::allowed('confirmed', ['require_check_in' => true]));
        self::assertNotContains('in_service', AppointmentTransitionService::allowed('pending', ['require_check_in' => false]));
    }

    public function testEveryTransitionRequiresAPermission(): void
    {
        $targets = [];
        foreach (['pending', 'confirmed', 'checked_in', 'in_service'] as $from) {
            foreach (AppointmentTransitionService::allowed($from) as $to) {
                self::assertNotNull(
                    AppointmentTransitionService::permissionFor($to),
                    "{$from} → {$to} has no permission gate",
                );
                $targets[$to] = true;
            }
        }
        self::assertNotEmpty($targets);
    }

    public function testPermissionMapIsStrict(): void
    {
        self::assertSame('appointments.confirm', AppointmentTransitionService::permissionFor('confirmed'));
        self::assertSame('appointments.update', AppointmentTransitionService::permissionFor('checked_in'));
        self::assertSame('appointments.update', AppointmentTransitionService::permissionFor('in_service'));
        self::assertSame('appointments.update', AppointmentTransitionService::permissionFor('completed'));
        self::assertSame('appointments.update', AppointmentTransitionService::permissionFor('no_show'));
        self::assertSame('appointments.cancel', AppointmentTransitionService::permissionFor('cancelled'));
        self::assertNull(AppointmentTransitionService::permissionFor('expired'));
    }
}
