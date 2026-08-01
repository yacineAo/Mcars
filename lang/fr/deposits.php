<?php

declare(strict_types=1);

return [
    'actions' => [
        'hold' => 'Encaisser la caution',
        'deduct' => 'Retenir sur la caution',
        'deduct_description' => 'Seule la part retenue devient un produit. Le reste demeure une somme due au client.',
        'refund' => 'Restituer la caution',
        'refund_description' => 'Laissez le montant vide pour restituer tout le solde encore détenu après retenues.',
    ],
    'fields' => [
        'status_help' => 'Défini automatiquement par les actions Encaisser, Retenir et Restituer.',
        'reason' => 'Motif',
        'amount' => 'Montant à retenir',
        'description' => 'Note',
        'refund_amount' => 'Montant à restituer',
        'refund_help' => 'Laissez vide pour restituer la totalité du solde restant.',
        'amount_held' => 'Montant détenu',
        'amount_held_help' => 'Une dette envers le client — jamais une recette.',
        'remaining_balance' => 'Solde restant',
    ],
    'filters' => [
        'held_at' => 'Retenue le',
        'from' => 'Du',
        'to' => 'Au',
    ],
    'notifications' => [
        'held' => 'Caution encaissée. Elle est enregistrée comme une somme due au client, non comme un produit.',
        'deducted' => 'Retenue enregistrée.',
        'refunded' => 'Caution restituée.',
    ],
];
