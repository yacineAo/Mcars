<?php

declare(strict_types=1);

return [
    'resource' => [
        'label' => 'Activity Log',
        'plural_label' => 'Activity Log',
        'sections' => [
            'details' => 'Details',
            'changes' => 'Changes',
        ],
        'fields' => [
            'created_at' => 'Date',
            'log_name' => 'Log',
            'description' => 'Description',
            'event' => 'Event',
            'causer' => 'User',
            'subject' => 'Subject',
            'subject_id' => 'Subject ID',
            'branch' => 'Branch',
            'changes' => 'Attribute Changes',
        ],
        'filters' => [
            'date_range' => 'Date Range',
        ],
    ],
];
