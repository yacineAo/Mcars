<?php

declare(strict_types=1);

return [
    'fields' => [
        'period_month' => 'Période',
        'branch' => 'Agence',
        'status' => 'Statut',
        'total' => 'Total net',
        'employees' => 'Employés',
        'approved_by' => 'Approuvée par',
        'approved_at' => 'Approuvée le',
        'paid_at' => 'Payée le',
        'notes' => 'Notes',
        'gross' => 'Salaires bruts',
        'commissions' => 'Commissions',
        'advances' => 'Avances récupérées',
    ],
    'filters' => [
        'status' => 'Statut',
        'period' => 'Période',
        'branch' => 'Agence',
    ],
    'sections' => [
        'run' => 'Paie',
        'approval_trail' => 'Traçabilité de l’approbation',
        'totals' => 'Totaux dérivés',
        'items' => 'Lignes',
    ],
    'items' => [
        'employee' => 'Employé',
        'employee_number' => 'N°',
        'base' => 'Salaire de base',
        'bonuses' => 'Primes',
        'overtime' => 'Heures sup.',
        'commissions' => 'Commissions',
        'advances' => 'Avances',
        'absences' => 'Absences',
        'social' => 'Cotisations sociales',
        'other' => 'Autres retenues',
        'net' => 'Net',
        'edit' => 'Modifier',
        'remove' => 'Retirer',
    ],
    'transactions' => [
        'reference' => 'Référence',
        'occurred_on' => 'Date',
        'description' => 'Description',
        'debit' => 'Débit',
        'credit' => 'Crédit',
        'amount' => 'Montant',
    ],
    'actions' => [
        'approve' => 'Approuver',
        'approve_description' => 'Constate les salaires, les cotisations patronales et les commissions comme sommes dues au personnel.',
        'pay' => 'Marquer comme payée',
        'pay_description' => 'Enregistre la sortie des fonds et solde les sommes dues.',
    ],
    'notifications' => [
        'approved' => 'Paie approuvée et constatée comme due.',
        'paid' => 'Paie réglée.',
        'item_removed' => 'Ligne retirée ; sa commission et son avance retournent dans les files d’attente.',
    ],
];
