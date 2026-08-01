<?php

declare(strict_types=1);

return [
    'actions' => [
        'hold' => 'Hold deposit',
        'deduct' => 'Deduct from deposit',
        'deduct_description' => 'Only the deducted part becomes income. The rest stays money you owe the customer.',
        'refund' => 'Refund deposit',
        'refund_description' => 'Leave the amount empty to refund everything still held after deductions.',
    ],
    'fields' => [
        'status_help' => 'Set automatically by the Hold, Deduct and Refund actions.',
        'reason' => 'Reason',
        'amount' => 'Amount to deduct',
        'description' => 'Note',
        'refund_amount' => 'Amount to refund',
        'refund_help' => 'Leave empty to refund the full remaining balance.',
        'amount_held' => 'Amount held',
        'amount_held_help' => 'A liability owed back to the customer — never revenue.',
        'remaining_balance' => 'Remaining balance',
    ],
    'filters' => [
        'held_at' => 'Held at',
        'from' => 'From',
        'to' => 'To',
    ],
    'notifications' => [
        'held' => 'Deposit held. It is recorded as money owed back to the customer, not as income.',
        'deducted' => 'Deduction recorded.',
        'refunded' => 'Deposit refunded.',
    ],
];
