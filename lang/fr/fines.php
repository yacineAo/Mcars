<?php

declare(strict_types=1);

return [
    'actions' => [
        'propose' => 'Proposer le responsable',
        'assign' => 'Imputer la responsabilité',
        'assign_description' => "Imputer au client crée une créance le concernant (E49). Imputer à la société enregistre la contravention comme une charge supportée par l'entreprise (E50).",
    ],
    'fields' => [
        'reference' => 'Référence',
        'notice_number' => 'N° d’avis',
        'authority' => 'Autorité',
        'type' => 'Type',
        'violation_at' => 'Infraction',
        'location' => 'Lieu',
        'received_at' => 'Reçue le',
        'due_date' => 'Échéance',
        'amount' => 'Amende',
        'late_penalty_amount' => 'Pénalité de retard',
        'total_amount' => 'Total',
        'liability' => 'Qui paie',
        'status' => 'Statut',
        'liability_note' => 'Note de décision',
        'determined_at' => 'Décidée le',
        'determined_by' => 'Décidée par',
        'customer' => 'Client',
        'booking' => 'Réservation',
        'contract' => 'Contrat',
        'car' => 'Véhicule',
        'liability_help' => "Décidée via l'action « Imputer la responsabilité », qui inscrit la créance ou la charge au grand livre.",
        'status_help' => 'Le statut suit le grand livre ; il ne peut pas être saisi à la main.',
    ],
    'filters' => [
        'pending_liability' => 'Non décidée',
        'violation_range' => 'Date d’infraction',
        'violated_from' => 'Du',
        'violated_until' => 'Au',
    ],
    'sections' => [
        'notice' => 'Avis',
        'money' => 'Montants',
        'liability' => 'Décision de responsabilité',
        'related' => 'Enregistrements liés',
        'history' => 'Historique',
    ],
    'notifications' => [
        'proposed' => 'Une proposition a été établie d’après qui détenait le véhicule à cet instant, puis enregistrée sur la contravention. Vérifiez-la avant d’imputer.',
        'assigned' => 'Responsabilité imputée et inscrite au grand livre.',
    ],
];
