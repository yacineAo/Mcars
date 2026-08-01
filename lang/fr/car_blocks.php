<?php

declare(strict_types=1);

return [
    'fields' => [
        'car' => 'Voiture',
        'reason' => 'Motif',
        'starts_at' => 'Début',
        'ends_at' => 'Fin',
        'maintenance_log' => 'Fiche de maintenance',
        'notes' => 'Notes',
    ],
    'columns' => [
        'state' => 'État',
        'state_active' => 'En vigueur',
        'state_upcoming' => 'À venir',
        'state_ended' => 'Terminé',
    ],
    'filters' => [
        'state' => 'État',
        'state_options' => [
            'active' => 'En vigueur',
            'upcoming' => 'À venir',
            'ended' => 'Terminé',
        ],
        'car' => 'Voiture',
        'reason' => 'Motif',
        'window' => 'Chevauche la période',
        'window_from' => 'Du',
        'window_to' => 'Au',
    ],
    'actions' => [
        'unblock' => 'Libérer maintenant',
        'cancel' => 'Annuler le blocage',
        'unblocked' => 'Voiture libérée',
        'cancelled' => 'Blocage annulé',
    ],
    'errors' => [
        'block_clash' => 'La voiture est déjà bloquée pendant une partie de cette période.',
        'booking_clash' => 'La voiture est déjà réservée pendant une partie de cette période.',
        'not_active' => 'Ce blocage n\'est pas en vigueur actuellement.',
        'not_upcoming' => 'Seul un blocage qui n\'a pas encore commencé peut être annulé.',
    ],
];
