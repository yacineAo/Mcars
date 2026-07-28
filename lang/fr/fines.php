<?php

declare(strict_types=1);

return [
    'actions' => [
        'propose' => 'Proposer le responsable',
        'assign' => 'Imputer la responsabilité',
        'assign_description' => "Imputer au client crée une créance à son encontre. Imputer à la société enregistre une charge supportée par l'entreprise.",
    ],
    'fields' => [
        'liability' => 'Qui paie',
    ],
    'notifications' => [
        'proposed' => "Une proposition a été établie d'après qui détenait le véhicule à cet instant. Vérifiez-la avant d'imputer.",
        'assigned' => 'Responsabilité imputée.',
    ],
];
