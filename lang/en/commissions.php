<?php

declare(strict_types=1);

return [
    'fields' => [
        'employee' => 'Employee',
        'booking' => 'Booking',
        'basis_amount' => 'Basis Amount',
        'basis_amount_help' => 'The amount is computed as basis × rate / 100 by the service — it is never typed.',
        'rate' => 'Rate',
        'earned_on' => 'Earned On',
        'notes' => 'Notes',
    ],
    'columns' => [
        'employee' => 'Employee',
        'booking' => 'Booking',
        'earned_on' => 'Earned On',
        'basis' => 'Basis',
        'rate' => 'Rate',
        'amount' => 'Amount',
        'status' => 'Status',
        'swept_in' => 'Paid In',
    ],
    'filters' => [
        'unpaid' => 'Unpaid',
        'swept' => 'Already paid',
        'employee' => 'Employee',
        'earned_on' => 'Earned On',
        'earned_from' => 'From',
        'earned_until' => 'Until',
    ],
    'validation' => [
        'self_granted' => 'A commission on your own employee record cannot be created.',
    ],
];
