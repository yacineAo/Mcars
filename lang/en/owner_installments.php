<?php

declare(strict_types=1);

return [
    'actions' => [
        'accrue' => 'Record as owed',
        'accrue_description' => 'Records this month\'s rent as a cost of the car and as money owed to the owner. Do this once per instalment.',
    ],
    'fields' => [
        'agreement' => 'Agreement',
        'owner' => 'Owner',
        'car' => 'Car',
        'period_month' => 'Period',
        'due_date' => 'Due date',
        'amount_due' => 'Amount due',
        'status' => 'Status',
        'waived_reason' => 'Waived reason',
        'paid' => 'Paid',
        'accrued' => 'Accrued',
        'not_accrued' => 'Not accrued',
    ],
    'filters' => [
        'unaccrued' => 'Not yet accrued',
        'overdue' => 'Overdue',
        'period_month' => 'Period',
        'owner' => 'Owner',
        'car' => 'Car',
        'status' => 'Status',
    ],
    'relations' => [
        'payments' => 'Payments against this instalment',
    ],
    'notifications' => [
        'accrued' => 'Instalment recorded as owed to the owner.',
    ],
];
