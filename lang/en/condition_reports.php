<?php

declare(strict_types=1);

return [
    'fields' => [
        'booking' => 'Booking',
        'car' => 'Car',
        'type' => 'Direction',
        'performed_at' => 'Performed at',
        'performed_by' => 'Performed by',
        'odometer' => 'Odometer',
        'fuel_level' => 'Fuel level',
        'clean' => 'Clean',
        'damage_points' => 'Damage points',
        'notes' => 'Notes',
        'photos' => 'Photos',
    ],
    'filters' => [
        'type' => 'Direction',
        'booking' => 'Booking',
        'car' => 'Car',
        'damages' => 'Damages',
        'damages_options' => [
            'damaged' => 'With damages',
            'clean' => 'Clean',
        ],
    ],
    'sections' => [
        'report' => 'Report',
        'readings' => 'Readings',
        'readings_description' => 'The out reading and the in reading side by side — a closeout charge is argued from the difference.',
        'photos' => 'Photos',
        'this_report' => 'This report',
        'paired_report' => 'Paired report',
    ],
    'placeholders' => [
        'no_damage' => 'No damage recorded',
        'no_photos' => 'No photos',
    ],
    'errors' => [
        'duplicate_type' => 'This booking already has a report of this direction.',
    ],
];
