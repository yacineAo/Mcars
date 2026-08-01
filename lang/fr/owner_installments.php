<?php

declare(strict_types=1);

return [
    'actions' => [
        'accrue' => 'Constater la dette',
        'accrue_description' => 'Enregistre le loyer du mois comme charge du véhicule et comme somme due au propriétaire. À faire une seule fois par échéance.',
    ],
    'fields' => [
        'agreement' => 'Contrat',
        'owner' => 'Propriétaire',
        'car' => 'Véhicule',
        'period_month' => 'Période',
        'due_date' => 'Échéance',
        'amount_due' => 'Montant dû',
        'status' => 'Statut',
        'waived_reason' => 'Motif de la remise',
        'paid' => 'Payé',
        'accrued' => 'Constatée',
        'not_accrued' => 'Non constatée',
    ],
    'filters' => [
        'unaccrued' => 'Non constatées',
        'overdue' => 'En retard',
        'period_month' => 'Période',
        'owner' => 'Propriétaire',
        'car' => 'Véhicule',
        'status' => 'Statut',
    ],
    'relations' => [
        'payments' => 'Paiements contre cette échéance',
    ],
    'notifications' => [
        'accrued' => 'Échéance constatée comme due au propriétaire.',
    ],
];
