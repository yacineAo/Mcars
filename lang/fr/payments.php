<?php

declare(strict_types=1);

return [
    'actions' => [
        'post' => 'Comptabiliser',
    ],
    'fields' => [
        'posted' => 'Comptabilisé',
        'not_posted' => 'Non comptabilisé',
        'customer' => 'Client',
        'branch' => 'Agence',
        'paid_for' => 'Payé pour',
        'external_reference' => 'Référence externe',
        'rib' => 'RIB / référence de virement',
        'ccp_account' => 'Compte CCP',
        'baridimob_number' => 'Numéro BaridiMob',
        'cheque_number' => 'Numéro de chèque',
        'card_reference' => 'Référence de transaction carte',
    ],
    'filters' => [
        'unposted' => 'Non encore comptabilisés',
        'paid_at' => 'Date de paiement',
        'from' => 'Du',
        'to' => 'Au',
    ],
    'notifications' => [
        'posted' => 'Paiement enregistré et comptabilisé.',
        'post_failed' => "Le paiement a été enregistré mais N'A PAS pu être comptabilisé",
        'post_failed_body' => "Utilisez l'action « Comptabiliser » sur ce paiement pour réessayer. Si l'erreur persiste, prévenez le comptable — les détails ont été enregistrés dans les journaux.",
    ],
];
