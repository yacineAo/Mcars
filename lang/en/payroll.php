<?php

declare(strict_types=1);

return [
    'fields' => [
        'period_month' => 'Period',
        'branch' => 'Branch',
        'status' => 'Status',
        'total' => 'Net total',
        'employees' => 'Employees',
        'approved_by' => 'Approved by',
        'approved_at' => 'Approved at',
        'paid_at' => 'Paid at',
        'notes' => 'Notes',
        'gross' => 'Gross salaries',
        'commissions' => 'Commissions',
        'advances' => 'Advances recovered',
    ],
    'filters' => [
        'status' => 'Status',
        'period' => 'Period',
        'branch' => 'Branch',
    ],
    'sections' => [
        'run' => 'Payroll run',
        'approval_trail' => 'Approval trail',
        'totals' => 'Derived totals',
        'items' => 'Items',
    ],
    'items' => [
        'employee' => 'Employee',
        'employee_number' => 'No.',
        'base' => 'Base salary',
        'bonuses' => 'Bonuses',
        'overtime' => 'Overtime',
        'commissions' => 'Commissions',
        'advances' => 'Advances',
        'absences' => 'Absences',
        'social' => 'Social contributions',
        'other' => 'Other deductions',
        'net' => 'Net',
        'edit' => 'Edit',
        'remove' => 'Remove',
    ],
    'transactions' => [
        'reference' => 'Reference',
        'occurred_on' => 'Date',
        'description' => 'Description',
        'debit' => 'Debit',
        'credit' => 'Credit',
        'amount' => 'Amount',
    ],
    'actions' => [
        'approve' => 'Approve',
        'approve_description' => 'Records salaries, employer contributions and commissions as amounts the business owes its staff.',
        'pay' => 'Mark as paid',
        'pay_description' => 'Records the money leaving the business and clears what was owed.',
    ],
    'notifications' => [
        'approved' => 'Payroll approved and recorded as owed.',
        'paid' => 'Payroll paid.',
        'item_removed' => 'Item removed; its commission and advance are back in the queues.',
    ],
];
