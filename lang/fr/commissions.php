<?php

declare(strict_types=1);

return [
    'fields' => [
        'employee' => 'Employé',
        'booking' => 'Réservation',
        'basis_amount' => 'Montant de base',
        'basis_amount_help' => 'Le montant est calculé (base × taux / 100) par le service — il n’est jamais saisi.',
        'rate' => 'Taux',
        'earned_on' => 'Acquise le',
        'notes' => 'Notes',
    ],
    'columns' => [
        'employee' => 'Employé',
        'booking' => 'Réservation',
        'earned_on' => 'Acquise le',
        'basis' => 'Base',
        'rate' => 'Taux',
        'amount' => 'Montant',
        'status' => 'Statut',
        'swept_in' => 'Payée dans',
    ],
    'filters' => [
        'unpaid' => 'Non payées',
        'swept' => 'Déjà payées',
        'employee' => 'Employé',
        'earned_on' => 'Acquise le',
        'earned_from' => 'Du',
        'earned_until' => 'Au',
    ],
    'validation' => [
        'self_granted' => 'Une commission sur votre propre dossier d’employé ne peut pas être créée.',
    ],
];
