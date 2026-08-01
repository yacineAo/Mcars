<?php

declare(strict_types=1);

return [
    'fields' => [
        'car' => 'Car',
        'reason' => 'Reason',
        'starts_at' => 'Starts at',
        'ends_at' => 'Ends at',
        'maintenance_log' => 'Maintenance log',
        'notes' => 'Notes',
    ],
    'columns' => [
        'state' => 'State',
        'state_active' => 'Active now',
        'state_upcoming' => 'Upcoming',
        'state_ended' => 'Ended',
    ],
    'filters' => [
        'state' => 'State',
        'state_options' => [
            'active' => 'Active now',
            'upcoming' => 'Upcoming',
            'ended' => 'Ended',
        ],
        'car' => 'Car',
        'reason' => 'Reason',
        'window' => 'Overlaps range',
        'window_from' => 'From',
        'window_to' => 'To',
    ],
    'actions' => [
        'unblock' => 'Unblock now',
        'cancel' => 'Cancel block',
        'unblocked' => 'Car unblocked',
        'cancelled' => 'Block cancelled',
    ],
    'errors' => [
        'block_clash' => 'The car is already blocked during part of this window.',
        'booking_clash' => 'The car is already booked during part of this window.',
        'not_active' => 'This block is not currently in force.',
        'not_upcoming' => 'Only a block that has not started yet can be cancelled.',
    ],
];
