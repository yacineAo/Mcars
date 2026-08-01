<?php

declare(strict_types=1);

return [
    'actions' => [
        'confirm' => 'Confirmer',
        'checkout' => 'Remise du véhicule',
        'checkout_heading' => 'Remettre le véhicule au client',
        'checkout_description' => 'Ceci démarre la location et la facture : le montant total est porté au compte du client et le véhicule passe en « loué ».',
        'checkin' => 'Retour du véhicule',
        'checkin_heading' => 'Reprendre le véhicule',
        'checkin_description' => 'Ceci clôture la location et libère le véhicule. Si un état des lieux de retour existe, les frais supplémentaires (heures de retard, kilomètres, carburant) sont calculés et facturés.',
        'cancel' => 'Annuler la réservation',
    ],
    'fields' => [
        'actual_pickup_at' => 'Remis le',
        'odometer_out' => 'Kilométrage à la remise',
        'fuel_level_out' => 'Carburant à la remise',
        'actual_return_at' => 'Restitué le',
        'odometer_in' => 'Kilométrage au retour',
        'fuel_level_in' => 'Carburant au retour',
        'cancellation_reason' => "Motif de l'annulation",
        'extras_total_helper' => 'Calculé à partir des lignes d’extras sur la page de réservation.',
    ],
    'notifications' => [
        'confirmed' => 'Réservation confirmée. Le véhicule est réservé pour ces dates.',
        'checked_out' => 'Véhicule remis au client.',
        'revenue_posted' => 'La location a été facturée au client.',
        'checked_in' => 'Véhicule restitué et location clôturée.',
        'cancelled' => 'Réservation annulée.',
    ],
];
