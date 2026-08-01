<?php

declare(strict_types=1);

return [
    'fields' => [
        'booking' => 'Réservation',
        'car' => 'Véhicule',
        'type' => 'Sens',
        'performed_at' => 'Effectuée le',
        'performed_by' => 'Effectuée par',
        'odometer' => 'Compteur',
        'fuel_level' => 'Niveau de carburant',
        'clean' => 'Propre',
        'damage_points' => 'Points de dégâts',
        'notes' => 'Remarques',
        'photos' => 'Photos',
    ],
    'filters' => [
        'type' => 'Sens',
        'booking' => 'Réservation',
        'car' => 'Véhicule',
        'damages' => 'Dégâts',
        'damages_options' => [
            'damaged' => 'Avec dégâts',
            'clean' => 'Propre',
        ],
    ],
    'sections' => [
        'report' => 'Rapport',
        'readings' => 'Relevés',
        'readings_description' => 'Le relevé de sortie et celui de retour côte à côte — une facturation de clôture se fonde sur la différence.',
        'photos' => 'Photos',
        'this_report' => 'Ce rapport',
        'paired_report' => 'Rapport jumelé',
    ],
    'placeholders' => [
        'no_damage' => 'Aucun dégât enregistré',
        'no_photos' => 'Aucune photo',
    ],
    'errors' => [
        'duplicate_type' => 'Cette réservation possède déjà un rapport de ce sens.',
    ],
];
