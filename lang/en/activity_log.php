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
            'causer' => 'User',
            'subject_type' => 'Subject Type',
            'subject_id' => 'Subject ID',
        ],
        'actions' => [
            'view_history' => 'History',
        ],
        'diff' => [
            'field' => 'Field',
            'before' => 'Before',
            'after' => 'After',
        ],
    ],
];
