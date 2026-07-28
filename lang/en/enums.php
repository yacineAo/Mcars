<?php

declare(strict_types=1);

/*
 * Enum labels, keyed by the enum's snake_case short name (see
 * App\Enums\Concerns\HasEnumMeta). Each phase adds its own enums here in the
 * same session that adds them to the code — docs/07-enums.md rule 5.
 *
 * Phase 0 ships only what exists so far.
 */

return [
    'sequence_key' => [
        'contract' => 'Contract',
        'booking' => 'Booking',
        'transaction' => 'Transaction',
        'payment' => 'Payment',
        'expense' => 'Expense',
        'invoice' => 'Invoice',
    ],
    'user_role' => [
        'super_admin' => 'Super Admin',
        'manager' => 'Manager',
        'accountant' => 'Accountant',
        'receptionist' => 'Receptionist',
        'maintenance_officer' => 'Maintenance Officer',
        'supervisor' => 'Supervisor',
        'car_owner' => 'Car Owner',
        'client' => 'Client',
    ],
];
