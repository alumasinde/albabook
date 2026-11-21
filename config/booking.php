<?php

declare(strict_types=1);

// Platform defaults (booking-engine.md §21). A NULL column in booking_policies
// inherits these. Nothing reads them until Phase 4; they live here so the
// contract is fixed early.
return [
    'slot_interval_minutes' => 15,
    'min_notice_minutes' => 60,
    'max_advance_days' => 60,
    'hold_ttl_seconds' => 300,
    'hold_max_extension_seconds' => 600,
    'max_active_holds_per_client' => 2,
    'confirmation_mode' => 'auto',
    'pending_expiry_minutes' => 1440,
    'staff_assignment_strategy' => 'least_booked_day',
    'customer_changes' => 'self_service',
    'cancellation_cutoff_minutes' => 1440,
    'reschedule_cutoff_minutes' => 1440,
    'max_reschedules' => 2,
    'deposit_mode' => 'none',
    'deposit_due_minutes' => 30,
    'max_active_bookings_per_contact' => 3,
    'customer_match' => 'phone',
    'customer_verification' => 'none',
    'availability_max_range_days' => 62,
    'idempotency_ttl_hours' => 24,
    'booking_tx_max_attempts' => 3,
    'booking_lock_wait_timeout_s' => 5,
    'no_show_grace_minutes' => 15,

    // Bounds tenants may not exceed when overriding.
    'bounds' => [
        'hold_ttl_seconds' => [120, 900],
        'slot_interval_minutes' => [5, 240],
    ],
];
