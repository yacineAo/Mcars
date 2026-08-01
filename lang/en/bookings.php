<?php

declare(strict_types=1);

return [
    'actions' => [
        'confirm' => 'Confirm',
        'checkout' => 'Check out (hand over)',
        'checkout_heading' => 'Hand the car over',
        'checkout_description' => 'This starts the rental and invoices it: the rental total is posted to the customer\'s account and the car is marked as rented.',
        'checkin' => 'Check in (return)',
        'checkin_heading' => 'Take the car back',
        'checkin_description' => 'This closes the rental and releases the car. If a check-in condition report exists, extra charges (late hours, excess km, fuel) are calculated and invoiced.',
        'cancel' => 'Cancel booking',
    ],
    'fields' => [
        'actual_pickup_at' => 'Handed over at',
        'odometer_out' => 'Odometer at hand-over (km)',
        'fuel_level_out' => 'Fuel at hand-over',
        'actual_return_at' => 'Returned at',
        'odometer_in' => 'Odometer at return (km)',
        'fuel_level_in' => 'Fuel at return',
        'cancellation_reason' => 'Reason for cancelling',
        'extras_total_helper' => 'Computed from the Extras lines on the booking page.',
    ],
    'notifications' => [
        'confirmed' => 'Booking confirmed. The car is now reserved for these dates.',
        'checked_out' => 'Car handed over.',
        'revenue_posted' => 'The rental has been invoiced to the customer.',
        'checked_in' => 'Car returned and the rental closed.',
        'cancelled' => 'Booking cancelled.',
    ],
];
