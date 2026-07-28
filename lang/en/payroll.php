<?php

declare(strict_types=1);

return [
    'actions' => [
        'approve' => 'Approve',
        'approve_description' => 'Records salaries, employer contributions and commissions as amounts the business owes its staff.',
        'pay' => 'Mark as paid',
        'pay_description' => 'Records the money leaving the business and clears what was owed.',
    ],
    'notifications' => [
        'approved' => 'Payroll approved and recorded as owed.',
        'paid' => 'Payroll paid.',
    ],
];
