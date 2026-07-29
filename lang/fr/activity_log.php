<?php

declare(strict_types=1);

return [
    'resource' => [
        'label' => "Journal d'Activités",
        'plural_label' => "Journal d'Activités",
        'sections' => [
            'details' => 'Détails',
            'changes' => 'Modifications',
        ],
        'fields' => [
            'created_at' => 'Date',
            'log_name' => 'Journal',
            'description' => 'Description',
            'event' => 'Événement',
            'causer' => 'Utilisateur',
            'subject' => 'Sujet',
            'subject_id' => 'ID Sujet',
            'branch' => 'Agence',
            'changes' => 'Modifications d\'Attributs',
        ],
        'filters' => [
            'date_range' => 'Période',
        ],
    ],
];
