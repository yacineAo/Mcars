<?php

declare(strict_types=1);

return [
    'actions' => [
        'propose' => 'Suggest who is liable',
        'assign' => 'Assign liability',
        'assign_description' => 'Assigning to the customer posts a receivable (E49). Assigning to the company records the fine as a cost the business absorbs (E50).',
    ],
    'fields' => [
        'reference' => 'Reference',
        'notice_number' => 'Notice no.',
        'authority' => 'Authority',
        'type' => 'Type',
        'violation_at' => 'Violation',
        'location' => 'Location',
        'received_at' => 'Received',
        'due_date' => 'Due',
        'amount' => 'Fine',
        'late_penalty_amount' => 'Late penalty',
        'total_amount' => 'Total',
        'liability' => 'Who pays',
        'status' => 'Status',
        'liability_note' => 'Decision note',
        'determined_at' => 'Decided at',
        'determined_by' => 'Decided by',
        'customer' => 'Customer',
        'booking' => 'Booking',
        'contract' => 'Contract',
        'car' => 'Car',
        'liability_help' => "Decided through the 'Assign liability' action, which posts the receivable or the absorbed expense.",
        'status_help' => 'Status follows the ledger; it cannot be set by hand.',
    ],
    'filters' => [
        'pending_liability' => 'Undecided',
        'violation_range' => 'Violation date',
        'violated_from' => 'From',
        'violated_until' => 'Until',
    ],
    'sections' => [
        'notice' => 'Notice',
        'money' => 'Amounts',
        'liability' => 'Liability decision',
        'related' => 'Related records',
        'history' => 'History',
    ],
    'notifications' => [
        'proposed' => 'A suggestion has been made from who had the car at the time and saved to the fine. Review it before assigning.',
        'assigned' => 'Liability assigned and posted to the ledger.',
    ],
];
