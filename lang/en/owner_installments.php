<?php

declare(strict_types=1);

return [
    'actions' => [
        'accrue' => 'Record as owed',
        'accrue_description' => 'Records this month\'s rent as a cost of the car and as money owed to the owner. Do this once per instalment.',
    ],
    'notifications' => [
        'accrued' => 'Instalment recorded as owed to the owner.',
    ],
];
