<?php

declare(strict_types=1);

return [
    'actions' => [
        'generate' => 'Generate payment plan',
        'record_payment' => 'Record payment',
        'reschedule' => 'Reschedule',
        'waive' => 'Waive instalment',
        'waive_confirm' => 'The instalment will be written off without a payment. This cannot be undone — a reason is recorded with the decision.',
    ],
    'fields' => [
        'customer' => 'Customer',
        'sequence' => 'Instalment',
        // Templated rather than concatenated: Arabic reverses the order, and
        // "of" is not a word that survives being glued on in code.
        'sequence_of' => ':sequence of :total',
        'due_date' => 'Due date',
        'amount' => 'Amount',
        'status' => 'Status',
        'reminder_sent' => 'Reminder sent',
        'method' => 'Method',
        'financial_account' => 'Financial account',
        'plan_for' => 'Plan for',
        'booking' => 'Booking',
        'contract' => 'Contract',
        'total' => 'Plan total',
        'installments' => 'Number of instalments',
        'first_due_date' => 'First due date',
        'notes' => 'Notes',
        'waived_reason' => 'Reason for waiving',
    ],
    'filters' => [
        'overdue' => 'Overdue',
        'due_this_month' => 'Due this month',
        'status' => 'Status',
        'customer' => 'Customer',
    ],
    'groups' => [
        'plan' => 'Plan',
        'plan_title' => ':type #:id',
        'unassigned' => 'Unassigned',
    ],
    'notifications' => [
        'generated' => 'Payment plan generated.',
        'generated_body' => ':count instalment(s) totalling :total DZD.',
        'payment_recorded' => 'Payment recorded against the instalment.',
        'rescheduled' => 'Instalment rescheduled.',
        'waived' => 'Instalment waived.',
    ],
];
