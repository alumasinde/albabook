<?php

declare(strict_types=1);

// Labels for lookup-table codes (decisions.md D-023): lookup.<table>.<code>.
return [
    'appointment_status' => [
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'checked_in' => 'Checked in',
        'in_service' => 'In service',
        'completed' => 'Completed',
        'no_show' => 'No-show',
        'cancelled' => 'Cancelled',
        'rejected' => 'Rejected',
        'expired' => 'Expired',
    ],
    'tenant_status' => [
        'pending' => 'Pending',
        'trial' => 'Trial',
        'active' => 'Active',
        'suspended' => 'Suspended',
        'cancelled' => 'Cancelled',
        'archived' => 'Archived',
    ],
];
