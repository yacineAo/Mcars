<?php

declare(strict_types=1);

return [
    'fields' => [
        'employee' => 'Employee',
        'amount' => 'Amount',
        'advanced_on' => 'Advanced On',
        'reason' => 'Reason',
        'status' => 'Status',
        'status_help' => 'Owned by the workflow: approving posts the payout to the ledger (E61), rejecting declines it, payroll recovery closes it.',
        'notes' => 'Notes',
    ],
    'columns' => [
        'employee' => 'Employee',
        'amount' => 'Amount',
        'advanced_on' => 'Advanced On',
        'status' => 'Status',
        'recovered_in' => 'Recovered In',
    ],
    'filters' => [
        'open' => 'Open advances',
        'settled' => 'Settled',
        'employee' => 'Employee',
    ],
    'actions' => [
        'approve' => 'Approve & Pay',
        'approve_description' => 'The payout is recorded in the ledger (advance to 1130, cash out of 1010). This cannot be undone.',
        'reject' => 'Reject',
        'reject_description' => 'The request is declined and closed. No ledger entry is made.',
    ],
    'notifications' => [
        'approved' => 'Advance approved and posted.',
        'rejected' => 'Advance rejected.',
    ],
    'validation' => [
        'self_granted' => 'An employee cannot request an advance on their own record.',
    ],
];
