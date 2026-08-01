<?php

declare(strict_types=1);

return [
    'actions' => [
        'generate' => 'Générer un échéancier',
        'record_payment' => 'Enregistrer le paiement',
        'reschedule' => 'Reporter l’échéance',
    ],
    'fields' => [
        'customer' => 'Client',
        'sequence' => 'Échéance',
        'sequence_of' => ':sequence sur :total',
        'due_date' => 'Date d’échéance',
        'amount' => 'Montant',
        'status' => 'Statut',
        'reminder_sent' => 'Rappel envoyé',
        'method' => 'Mode de paiement',
        'financial_account' => 'Compte financier',
        'plan_for' => 'Échéancier pour',
        'booking' => 'Réservation',
        'contract' => 'Contrat',
        'total' => 'Total de l’échéancier',
        'installments' => 'Nombre d’échéances',
        'first_due_date' => 'Première échéance',
        'notes' => 'Notes',
    ],
    'filters' => [
        'overdue' => 'En retard',
        'due_this_month' => 'À échoir ce mois-ci',
        'status' => 'Statut',
        'customer' => 'Client',
    ],
    'groups' => [
        'plan' => 'Échéancier',
        'plan_title' => ':type n° :id',
        'unassigned' => 'Non rattaché',
    ],
    'notifications' => [
        'generated' => 'Échéancier généré.',
        'generated_body' => ':count échéance(s) pour un total de :total DZD.',
        'payment_recorded' => 'Paiement enregistré sur l’échéance.',
        'rescheduled' => 'Échéance reportée.',
    ],
];
