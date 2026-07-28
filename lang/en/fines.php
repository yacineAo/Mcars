<?php

declare(strict_types=1);

return [
    'actions' => [
        'propose' => 'Suggest who is liable',
        'assign' => 'Assign liability',
        'assign_description' => 'Assigning to the customer creates a debt owed by them. Assigning to the company records it as a cost the business absorbs.',
    ],
    'fields' => [
        'liability' => 'Who pays',
    ],
    'notifications' => [
        'proposed' => 'A suggestion has been made based on who had the car at the time. Review it before assigning.',
        'assigned' => 'Liability assigned.',
    ],
];
